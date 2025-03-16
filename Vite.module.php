<?php

namespace ProcessWire;

/**
 * An Vite adapter for ProcessWire
 *
 * @package   Totoglu Vite
 * @author    Iskender TOTOGLU <iskender@totoglu.com>
 * @link      https://totoglu.com
 * @copyright Iskender TOTOGLU
 * @license   https://opensource.org/licenses/MIT
 * 
 * @property string $buildDirectory The path to the build directory.
 * @property ?string $hotFile The path to the "hot" file.
 * @property ?string $integrity The key to check for integrity hashes within the manifest.
 * @property string $manifest The name of the manifest file.
 * @property ?string $nonce The key to check for nonce hashes within the manifest.
 */
class Vite extends WireData implements Module
{
    public static function getModuleInfo()
    {
        return [
            'title' => 'Vite',
            "summary" => __('Vite adapter for ProcessWire', __FILE__),
            "version" => 1,
            'icon' => 'code',
            'singular' => true,
            'autoload' => true,
            'requires' => [
                'ProcessWire>=3.0.0'
            ]
        ];
    }

    public function __construct()
    {
        $this->wire('classLoader')->addNamespace('Totoglu\Vite', __DIR__ . '/src');

        require_once __DIR__ . '/functions.php';

        /** @var Config $config */
        $config = $this->wire('config');

        $this->set('rootPath', $config->paths->templates);
        $this->set('rootUrl', $config->urls->templates);
        $this->set('buildDirectory', 'build');
        $this->set('hotFile', 'hot');
        $this->set('integrity', 'integrity');
        $this->set('manifest', 'manifest.json');
        $this->set('nonce', null);
    }

    public function wired()
    {
        $this->wire('vite', $this);
    }

    public function init() {}

    public function ready() {}

    /**
     * Recursively copy stub files to destination
     */
    protected function copyStubFilesRecursive($sourceDir, $destDir, $relativePath = '')
    {
        $dir = new \DirectoryIterator($sourceDir . $relativePath);

        foreach ($dir as $fileInfo) {
            if ($fileInfo->isDot()) continue;

            $sourcePath = $fileInfo->getPathname();
            $relPath = $relativePath . '/' . $fileInfo->getFilename();
            $destPath = $destDir . $relPath;

            if ($fileInfo->isDir()) {
                // Create directory if it doesn't exist
                if (!is_dir($destPath)) {
                    $this->message("Creating directory: " . $relPath);
                    wireMkdir($destPath);
                }

                // Recursively copy files in subdirectory
                $this->copyStubFilesRecursive($sourceDir, $destDir, $relPath);
            } else {
                // Skip if file already exists
                if (file_exists($destPath)) {
                    $this->message("File already exists (skipping): " . $relPath);
                    continue;
                }

                // Copy the file
                $this->message("Copying file: " . $relPath);
                copy($sourcePath, $destPath);
            }
        }
    }

    /**
     * Copy stub files to site directory
     */
    protected function copyStubFiles()
    {
        $stubsDir = __DIR__ . '/stubs';
        $siteDir = $this->wire('config')->paths->site;

        if (!is_dir($stubsDir)) {
            $this->error("Stubs directory not found: $stubsDir");
            return;
        }

        $this->copyStubFilesRecursive($stubsDir, $siteDir);
        $this->message("Vite stub files have been copied to site templates directory.");
    }

    public function ___install()
    {
        $this->copyStubFiles();
    }
}
