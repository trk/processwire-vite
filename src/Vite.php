<?php

namespace Totoglu\Vite;

use Stringable;
use ProcessWire\WireException;

use function ProcessWire\setting;
use function ProcessWire\wire;

/**
 * An Vite adapter for ProcessWire
 *
 * @package   Totoglu Vite
 * @author    Iskender TOTOGLU <iskender@totoglu.com>
 * @link      https://totoglu.com
 * @copyright Iskender TOTOGLU
 * @license   https://opensource.org/licenses/MIT
 */
class Vite implements Stringable
{
    /**
     * The Vite Module instance.
     */
    protected \ProcessWire\Vite $module;

    /**
     * The Vite instance.
     */
    protected static Vite $instance;

    protected string $rootPath;

    protected string $rootUrl;

    /**
     * The Content Security Policy nonce to apply to all generated tags.
     */
    protected ?string $nonce = null;

    /**
     * The key to check for integrity hashes within the manifest.
     */
    protected string|false $integrity = 'integrity';

    /**
     * The configured entry points.
     */
    protected array $entries = [];

    /**
     * The path to the "hot" file.
     */
    protected ?string $hotFile = null;

    /**
     * The path to the build directory.
     */
    protected string $buildDirectory = 'build';

    /**
     * The name of the manifest file.
     */
    protected string $manifest = 'manifest.json';

    /**
     * The preload tag attributes.
     */
    protected array $preloadTagAttributesResolvers = [];

    /**
     * The script tag attributes.
     */
    protected array $scriptTagAttributesResolvers = [];

    /**
     * The style tag attributes.
     */
    protected array $styleTagAttributesResolvers = [];

    /**
     * The preloaded assets.
     */
    protected array $preloadedAssets = [];

    /**
     * The cached manifest files.
     */
    protected static array $manifests = [];

    /**
     * Create a new Vite instance.
     */
    public function __construct()
    {
        $this->module = wire('vite');

        $this->rootPath = $this->module->rootPath;
        $this->rootUrl = $this->module->rootUrl;
        $this->buildDirectory = $this->module->buildDirectory;
        $this->hotFile = $this->module->hotFile;
        $this->integrity = $this->module->integrity;
        $this->manifest = $this->module->manifest;
        $this->nonce = $this->module->nonce;
    }

    /**
     * Generate Vite tags for an entrypoint.
     *
     * @param  string|string[]  $entries
     *
     * @throws \Exception
     */
    public function __invoke(array|string $entries, ?string $buildDirectory = null): ?string
    {
        $entries = (array) $entries;
        $exists  = [];

        foreach ($entries as $key => $value) {

            if ($optional = str_starts_with($value, '@')) {
                $value = substr($value, 1);
            }

            if ($optional) {
                if ($this->exists($value)) {
                    $entries[$key] = $value;
                    $exists[]      = $value;
                } else {
                    unset($entries[$key]);
                }
            }
        }

        if ($this->isRunningHot()) {
            array_unshift($entries, '@vite/client');

            return join('', array_map(
                fn(string $value) =>
                $this->makeTag($value, $this->hotAsset($value)),
                $entries
            ));
        }

        $manifest = $this->manifest($buildDirectory ??= $this->buildDirectory);

        $preloads = [];
        $assets   = [];

        foreach ($entries as $key) {
            try {
                $chunk = $this->chunk($manifest, $key);
            } catch (WireException $e) {
                if (! in_array($key, $exists)) throw $e;
            }

            if (! isset($chunk)) {
                continue;
            }

            $args = [
                $key,
                $this->url($buildDirectory . '/' . $file = $chunk['file']),
                $chunk,
                $manifest,
            ];

            if (! isset($preloads[$file])) {
                $preloads[$file] = $this->makePreloadTag(...$args);
            }

            if (! isset($assets[$file])) {
                $assets[$file] = $this->makeTag(...$args);
            }

            $this->resolveImports($chunk, $buildDirectory, $manifest, $assets, $preloads);

            $this->resolveCss($chunk, $buildDirectory, $manifest, $assets, $preloads);
        }

        uksort(
            $preloads,
            fn($a, $b) =>
            $this->isStylePath($a) === $this->isStylePath($b) ? 0 : 1
        );

        uksort(
            $assets,
            fn($a, $b) =>
            $this->isStylePath($a) === $this->isStylePath($b) ? 0 : 1
        );

        return join('', $preloads) . join('', $assets);
    }

    /**
     * Create a new Vite instance.
     */
    public static function instance(): static
    {
        return static::$instance ??= new static();
    }

    /**
     * Returns a copy of the current Vite instance.
     */
    public static function copy(): static
    {
        return clone static::instance();
    }

    /**
     * Get the chunk for the given entry point / asset.
     *
     * @throws \Exception
     */
    protected function chunk(array $manifest, string $file): array
    {
        if (! isset($manifest[$file])) {
            throw new WireException("Unable to locate file in Vite manifest: {$file}");
        }

        return $manifest[$file];
    }

    /**
     * Check if a source file exists.
     */
    protected function exists(string $file): bool
    {
        $paths = wire('config')->paths;

        $paths = [
            $paths->root,
            $paths->templates
        ];

        foreach ($paths as $path) {
            if (file_exists($this->path($file, $path))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the the manifest file for the given build directory.
     *
     * @throws \WireException
     */
    protected function manifest(string $buildDirectory): array
    {
        $path = $this->manifestPath($buildDirectory);

        if (! isset(static::$manifests[$path])) {
            if (! is_file($path)) {
                throw new WireException("Vite manifest not found at: {$path}");
            }

            static::$manifests[$path] = json_decode(file_get_contents($path), true);
        }

        return static::$manifests[$path];
    }

    /**
     * Get the path to the manifest file for the given build directory.
     */
    protected function manifestPath(string $buildDirectory): string
    {
        return $this->path($buildDirectory . '/' . $this->manifest);
    }

    /**
     * Make a tag for the given chunk.
     */
    protected function makeTag(string $src, string $url, array $chunk = [], array $manifest = []): string
    {
        if ($this->isStylePath($url)) {
            return $this->makeStyleTag(
                $url,
                $this->resolveStyleTagAttributes($src, $url, $chunk, $manifest)
            );
        }

        return $this->makeScriptTag(
            $url,
            $this->resolveScriptTagAttributes($src, $url, $chunk, $manifest)
        );
    }

    /**
     * Generate a script tag with attributes for the given URL.
     */
    protected function makeScriptTag(string $url, array $attributes = []): string
    {
        $attributes = array_merge([
            'type'  => 'module',
            'src'   => $url,
            'nonce' => $this->nonce(),
        ], $attributes);
        return "<script {$this->getAttributes($attributes)}></script>";
    }

    /**
     * Generate a link tag with attributes for the given URL.
     */
    protected function makeStyleTag(string $url, array $attributes = []): string
    {
        $attributes = array_merge([
            'rel'   => 'stylesheet',
            'href'  => $url,
            'nonce' => $this->nonce(),
        ], $attributes);
        return "<link {$this->getAttributes($attributes)}>";
    }

    /**
     * Make a preload tag for the given chunk.
     */
    protected function makePreloadTag(string $src, string $url, array $chunk = [], array $manifest = []): string
    {
        $attributes = $this->resolvePreloadTagAttributes($src, $url, $chunk, $manifest);

        return $this->preloadedAssets[$url] ??= "<link {$this->getAttributes($attributes)}>";
    }

    /**
     * Resolve the attributes for the chunks generated script tag.
     */
    protected function resolveScriptTagAttributes(string $src, string $url, array $chunk = [], array $manifest = []): array
    {
        $attributes = $this->integrity !== false
            ? ['integrity' => $chunk[$this->integrity] ?? false]
            : [];

        foreach (setting('vite.scriptTagAttributes') ?: [] as $resolver) {
            if (!$resolver instanceof \Closure) {
                continue;
            }
            $attributes = array_merge($attributes, $resolver($src, $url, $chunk, $manifest));
        }

        return $attributes;
    }

    /**
     * Resolve the attributes for the chunks generated stylesheet tag.
     */
    protected function resolveStyleTagAttributes(string $src, string $url, array $chunk = [], array $manifest = []): array
    {
        $attributes = $this->integrity !== false
            ? ['integrity' => $chunk[$this->integrity] ?? false]
            : [];

        foreach (setting('vite.styleTagAttributes') ?: [] as $resolver) {
            $attributes = array_merge($attributes, $resolver($src, $url, $chunk, $manifest));
        }

        return $attributes;
    }

    /**
     * Resolve the attributes for the chunks generated preload tag.
     */
    protected function resolvePreloadTagAttributes(string $src, string $url, array $chunk = [], array $manifest = []): array
    {
        $attributes = $this->isStylePath($url) ? [
            'rel'         => 'preload',
            'as'          => 'style',
            'href'        => $url,
            'nonce'       => $this->nonce(),
            'crossorigin' => $this->resolveStyleTagAttributes($src, $url, $chunk, $manifest)['crossorigin'] ?? false,
        ] : [
            'rel'         => 'modulepreload',
            'href'        => $url,
            'nonce'       => $this->nonce(),
            'crossorigin' => $this->resolveScriptTagAttributes($src, $url, $chunk, $manifest)['crossorigin'] ?? false,
        ];

        $attributes = $this->integrity !== false
            ? array_merge($attributes, ['integrity' => $chunk[$this->integrity] ?? false])
            : $attributes;

        foreach ($this->preloadTagAttributesResolvers as $resolver) {
            $attributes = array_merge($attributes, $resolver($src, $url, $chunk, $manifest));
        }

        return $attributes;
    }

    /**
     * Get the preloaded assets.
     */
    public function preloadedAssets(): array
    {
        return $this->preloadedAssets;
    }

    /**
     * Get the URL for an asset.
     */
    public function asset(string $asset, ?string $buildDirectory = null): string
    {
        $buildDirectory ??= $this->buildDirectory;

        if ($this->isRunningHot()) {
            return $this->hotAsset($asset);
        }

        $chunk = $this->chunk($this->manifest($buildDirectory), $asset);

        return $this->url($buildDirectory . '/' . $chunk['file']);
    }

    /**
     * Generate React refresh runtime script.
     */
    public function reactRefresh(): string
    {
        if (! $this->isRunningHot()) {
            return '';
        }

        return sprintf(
            <<<'HTML'
            <script type="module" %s>
                import RefreshRuntime from '%s'
                RefreshRuntime.injectIntoGlobalHook(window)
                window.$RefreshReg$ = () => {}
                window.$RefreshSig$ = () => (type) => type
                window.__vite_plugin_react_preamble_installed__ = true
            </script>
            HTML,
            $this->getAttributes(['nonce' => $this->nonce()]),
            $this->hotAsset('@react-refresh')
        );
    }

    /**
     * Determine whether the given path is a style file.
     */
    public function isStylePath(string $path): bool
    {
        return preg_match('/\.(css|less|sass|scss|styl|stylus|pcss|postcss)$/', $path) === 1;
    }

    /**
     * Determine if the HMR server is running.
     */
    public function isRunningHot(): bool
    {
        return is_file($this->hotFile());
    }

    /**
     * Get the Vite "hot" file path.
     */
    public function hotFile(): string
    {
        return $this->hotFile ? $this->path($this->hotFile) : $this->path('hot');
    }

    /**
     * Get the path to a given asset when running in HMR mode.
     */
    public function hotAsset(string $asset): string
    {
        return rtrim(file_get_contents($this->hotFile())) . '/' . $asset;
    }

    /**
     * Get the Content Security Policy nonce applied to all generated tags.
     */
    public function nonce(): ?string
    {
        return $this->nonce;
    }

    /**
     * Resolve related css files.
     */
    public function resolveCss(array $chunk, string $buildDirectory, array $manifest, array &$assets, array &$preloads): void
    {
        foreach ($chunk['css'] ?? [] as $key) {
            $chunks = array_filter(
                $manifest,
                fn($value) =>
                $value['file'] === $key
            );

            if (empty($chunks)) {
                $chunks[$key] = ['file' => $key];
            }

            $key   = array_key_first($chunks);
            $chunk = current($chunks);
            $file  = $chunk['file'];

            $args = [
                $key,
                $this->url($buildDirectory . '/' . $file),
                $chunk,
                $manifest,
            ];

            if (! isset($assets[$file])) {
                $assets[$file] = $this->makeTag(...$args);
            }

            if (! isset($preloads[$file])) {
                $preloads[$file] = $this->makePreloadTag(...$args);
            }
        }
    }

    /**
     * Resolve related imports.
     */
    public function resolveImports(array $chunk, string $buildDirectory, array $manifest, array &$assets, array &$preloads): void
    {
        foreach ($chunk['imports'] ?? [] as $key) {
            $chunk = $this->chunk($manifest, $key);
            $file  = $chunk['file'];

            if (! isset($preloads[$file])) {
                $preloads[$file] = $this->makePreloadTag(
                    $key,
                    $this->url($buildDirectory . '/' . $file),
                    $chunk,
                    $manifest,
                );
            }

            $this->resolveCss($chunk, $buildDirectory, $manifest, $assets, $preloads);
        }
    }

    /**
     * Generate or set a Content Security Policy nonce to apply to all generated tags.
     */
    public function useNonce(?string $nonce = null): static
    {
        $this->nonce = $nonce ?? $this->random(40);

        return $this;
    }

    /**
     * Set the filename for the manifest file.
     */
    public function useManifest(string $name): static
    {
        $this->manifest = $name;

        return $this;
    }

    /**
     * Use the given key to detect integrity hashes in the manifest.
     */
    public function useIntegrity(string|false $key): static
    {
        $this->integrity = $key;

        return $this;
    }

    /**
     * Set the Vite "hot" file path.
     */
    public function useHotFile(string $path): static
    {
        $this->hotFile = $path;

        return $this;
    }

    /**
     * Set the Vite build directory.
     */
    public function useBuildDirectory(string $path): static
    {
        $this->buildDirectory = $path;
        return $this;
    }

    /**
     * Use the given callback to resolve attributes for script tags.
     */
    public function useScriptTagAttributes(array|callable $attributes): static
    {
        if (! is_callable($attributes)) {
            $attributes = fn() => $attributes;
        }

        $this->scriptTagAttributesResolvers[] = $attributes;

        return $this;
    }

    /**
     * Use the given callback to resolve attributes for style tags.
     */
    public function useStyleTagAttributes(array|callable $attributes): static
    {
        if (! is_callable($attributes)) {
            $attributes = fn() => $attributes;
        }

        $this->styleTagAttributesResolvers[] = $attributes;
        return $this;
    }

    /**
     * Use the given callback to resolve attributes for preload tags.
     */
    public function usePreloadTagAttributes(array|callable $attributes): static
    {
        if (! is_callable($attributes)) {
            $attributes = fn() => $attributes;
        }

        $this->preloadTagAttributesResolvers[] = $attributes;

        return $this;
    }

    /**
     * Set the Vite entry points.
     */
    public function withEntries(array $entries): static
    {
        $this->entries = $entries;

        return $this;
    }

    /**
     * Gets the image attributes.
     *
     * @param array $attributes
     *
     * @return string
     */
    public function getAttributes(array $attributes)
    {
        $attrs = [];
        foreach ($attributes as $key => $value) {
            // Skip attributes with null values
            if ($value === null) {
                continue;
            }

            // Convert non-string values to string
            if (!is_string($value)) {
                $value = (string)$value;
            }

            $attrs[] = sprintf('%1$s="%2$s"', $key, htmlspecialchars($value));
        }

        return join(' ', $attrs);
    }

    public function url(?string $path = null): string
    {
        return ltrim($this->rootUrl, '/') . ltrim($path, '/');
    }

    protected function path(?string $file = null, ?string $path = null): string
    {
        $path ??= $this->rootPath;
        return $path . ltrim($file, '/');
    }

    /**
     * Get a character pool with various possible combinations
     */
    public function pool(string|array $type, bool $array = true): string|array
    {
        if (is_array($type) === true) {
            $pool = [];

            foreach ($type as $t) {
                $pool = array_merge($pool, static::pool($t));
            }
        } else {
            $pool = match (strtolower($type)) {
                'alphalower' => range('a', 'z'),
                'alphaupper' => range('A', 'Z'),
                'alpha'      => static::pool(['alphaLower', 'alphaUpper']),
                'num'        => range(0, 9),
                'alphanum'   => static::pool(['alpha', 'num']),
                'base32'     => array_merge(static::pool('alphaUpper'), range(2, 7)),
                'base32hex'  => array_merge(range(0, 9), range('A', 'V')),
                default      => []
            };
        }

        return $array ? $pool : implode('', $pool);
    }

    /**
     * Generates a random string that may be used for cryptographic purposes
     *
     * @param int $length The length of the random string
     * @param string $type Pool type (type of allowed characters)
     */
    public function random(int|null $length = null, string $type = 'alphaNum'): string|false
    {
        $length ??= random_int(5, 10);
        $pool     = $this->pool($type, false);

        // catch invalid pools
        if (!$pool) {
            return false;
        }

        // regex that matches all characters
        // *not* in the pool of allowed characters
        $regex = '/[^' . $pool . ']/';

        // collect characters until we have our required length
        $result = '';

        while (($currentLength = strlen($result)) < $length) {
            $missing = $length - $currentLength;
            $bytes   = random_bytes($missing);
            $allowed = preg_replace($regex, '', base64_encode($bytes));
            $result .= substr($allowed, 0, $missing);
        }

        return $result;
    }

    /**
     * Get the Vite tag content as a string of HTML.
     */
    public function __toString(): string
    {
        return $this->__invoke($this->entries);
    }
}
