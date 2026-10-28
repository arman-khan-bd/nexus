<?php

declare(strict_types=1);

namespace Core\Compiler;

use Core\Env;
use Core\Security\Escaper;
use RuntimeException;

/**
 * Class JsxCompiler
 *
 * High-performance React/Next.js-style JSX template compiler for Nexus.
 * Compiles JSX tags, slots, and reactive expressions into cached native PHP.
 *
 * Features:
 * - PascalCase Component tags (<Button />, <Card>...</Card>)
 * - Context-aware auto-escaping ({ $var } -> Escaper::html, {!! $raw !!} -> raw)
 * - Evaluation of expressions inside component props (prop={ $expr })
 * - Intelligent disk caching (recompiles only when source mtime changes)
 * - Zero Node.js runtime required
 */
final class JsxCompiler
{
    private string $cacheDirectory;

    public function __construct(?string $cacheDirectory = null)
    {
        $this->cacheDirectory = $cacheDirectory ?? (dirname(__DIR__, 2) . '/storage/cache/views');
        if (!is_dir($this->cacheDirectory)) {
            @mkdir($this->cacheDirectory, 0700, true);
        }
    }

    /**
     * Singleton / default compiler helper.
     */
    private static ?self $instance = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Compile source file and return path to the cached native PHP file.
     */
    public static function compileFile(string $sourcePath): string
    {
        return self::instance()->getCompiledPath($sourcePath);
    }

    /**
     * Render a template file with context.
     */
    public static function render(string $sourcePath, array $context = []): string
    {
        $compiledPath = self::compileFile($sourcePath);
        return self::instance()->evaluate($compiledPath, $context);
    }

    /**
     * Get compiled file path, recompiling if stale or non-existent.
     */
    public function getCompiledPath(string $sourcePath): string
    {
        if (!file_exists($sourcePath) || !is_readable($sourcePath)) {
            throw new RuntimeException("Template source file not found or unreadable: [{$sourcePath}].");
        }

        $sourceMtime = filemtime($sourcePath);
        $cacheKey = hash('sha256', $sourcePath);
        $cachedPath = $this->cacheDirectory . '/view_' . substr($cacheKey, 0, 32) . '.php';

        $isHotReload = Env::bool('JSX_HOT_RELOAD', false);

        // Check if cache is still valid
        if (!$isHotReload && file_exists($cachedPath) && filemtime($cachedPath) >= $sourceMtime) {
            return $cachedPath;
        }

        // Recompile template
        $rawSource = (string)file_get_contents($sourcePath);
        $compiledPhp = $this->compile($rawSource);

        // Atomic write to cache file
        $fp = fopen($cachedPath, 'c+');
        if ($fp !== false) {
            if (flock($fp, LOCK_EX)) {
                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, $compiledPhp);
                fflush($fp);
                flock($fp, LOCK_UN);
            }
            fclose($fp);
        }

        return $cachedPath;
    }

    /**
     * Compile raw JSX template string into executable PHP code.
     */
    public function compile(string $source): string
    {
        // 1. Compile explicit raw outputs: {!! $var !!}
        $compiled = preg_replace('/\{\!\!(.+?)\!\!\}/s', '<?= $1 ?>', $source) ?? $source;

        // 2. Compile PascalCase Component Tags (handling nested components recursively)
        $compiled = $this->compileComponents($compiled);

        // 3. Compile escaped variable interpolations: { $var }
        // Match { $expression } or { $object->prop } but ignore CSS/JS brackets like { width: 100 }
        $compiled = preg_replace_callback('/\{(\s*\$[a-zA-Z0-9_\-\>\:\(\)\[\]\'\"\s\.\,\+\*\/\?\:]+?\s*)\}/', function ($matches) {
            $expr = trim($matches[1]);
            return "<?= \\Core\\Security\\Escaper::html({$expr}) ?>";
        }, $compiled) ?? $compiled;

        return $compiled;
    }

    /**
     * Parse and compile PascalCase JSX components (e.g. <Button />, <Card>...</Card>).
     */
    private function compileComponents(string $source): string
    {
        // Recursive loop to compile innermost components outward
        $maxDepth = 10;
        while ($maxDepth-- > 0) {
            $changed = false;

            // A. Self-closing components: <Component prop="val" />
            $selfClosingPattern = '/<([A-Z][a-zA-Z0-9_]*)((?:\s+[a-zA-Z0-9_\-:@\.]+(?:=(?:"[^"]*"|\'[^\']*\'|\{[^}]*\}|[^\s\/>]+))?)*)\s*\/>/';
            $newSource = preg_replace_callback($selfClosingPattern, function ($matches) {
                $componentName = $matches[1];
                $propsString = $matches[2] ?? '';
                $propsArrayCode = $this->parsePropsString($propsString);
                return "<?= \\Core\\UI\\ComponentRegistry::render('{$componentName}', {$propsArrayCode}) ?>";
            }, $source);

            if ($newSource !== $source) {
                $source = $newSource;
                $changed = true;
            }

            // B. Block components with children: <Component props...>slot</Component>
            // Non-greedy match that does not contain another opening tag of the same component
            $blockPattern = '/<([A-Z][a-zA-Z0-9_]*)((?:\s+[a-zA-Z0-9_\-:@\.]+(?:=(?:"[^"]*"|\'[^\']*\'|\{[^}]*\}|[^\s\/>]+))?)*)\s*>(.*?)<\/\1>/s';
            $newSource = preg_replace_callback($blockPattern, function ($matches) {
                $componentName = $matches[1];
                $propsString = $matches[2] ?? '';
                $children = $matches[3];
                $propsArrayCode = $this->parsePropsString($propsString);

                return "<?= \\Core\\UI\\ComponentRegistry::render('{$componentName}', {$propsArrayCode}, function() use (&\$__context) { extract(\$__context, EXTR_SKIP); ?>{$children}<?php }) ?>";
            }, $source);

            if ($newSource !== $source) {
                $source = $newSource;
                $changed = true;
            }

            if (!$changed) {
                break;
            }
        }

        return $source;
    }

    /**
     * Parse JSX attribute string into PHP array code string.
     * Example: variant="outline" icon="shield" disabled data-id={ $id }
     * -> ['variant' => "outline", 'icon' => "shield", 'disabled' => true, 'data-id' => $id]
     */
    public function parsePropsString(string $propsString): string
    {
        $propsString = trim($propsString);
        if ($propsString === '') {
            return '[]';
        }

        $items = [];
        $pattern = '/([a-zA-Z0-9_\-:@\.]+)(?:=(?:"([^"]*)"|\'([^\']*)\'|\{([^}]*)\}|([^\s\/>]+)))?/';

        if (preg_match_all($pattern, $propsString, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $name = $match[1];

                // Case 1: Boolean flag without value (e.g. disabled, required)
                if (!isset($match[2]) || $match[2] === '' && !isset($match[3]) && !isset($match[4]) && !isset($match[5])) {
                    if (!str_contains($match[0], '=')) {
                        $items[] = "'{$name}' => true";
                        continue;
                    }
                }

                // Case 2: Double-quoted string: key="value"
                if (isset($match[2]) && $match[2] !== '') {
                    $val = addcslashes($match[2], "'\\");
                    $items[] = "'{$name}' => '{$val}'";
                    continue;
                }

                // Case 3: Single-quoted string: key='value'
                if (isset($match[3]) && $match[3] !== '') {
                    $val = addcslashes($match[3], "'\\");
                    $items[] = "'{$name}' => '{$val}'";
                    continue;
                }

                // Case 4: JSX evaluated expression: key={ $expr }
                if (isset($match[4]) && $match[4] !== '') {
                    $expr = trim($match[4]);
                    $items[] = "'{$name}' => ({$expr})";
                    continue;
                }

                // Case 5: Bare word: key=value
                if (isset($match[5]) && $match[5] !== '') {
                    $val = addcslashes($match[5], "'\\");
                    $items[] = "'{$name}' => '{$val}'";
                    continue;
                }

                $items[] = "'{$name}' => ''";
            }
        }

        return '[' . implode(', ', $items) . ']';
    }

    /**
     * Evaluate compiled template with isolated scope.
     */
    public function evaluate(string $compiledPath, array $context = []): string
    {
        $__context = $context;
        extract($__context, EXTR_SKIP);

        ob_start();
        try {
            require $compiledPath;
            return (string)ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }
}
