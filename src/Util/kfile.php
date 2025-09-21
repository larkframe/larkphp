<?php

namespace Lark\Util;

class kfile
{
    /**
     * Copy dir
     * @param string $source
     * @param string $dest
     * @param bool $overwrite
     * @return void
     */
    public static function copyDir(string $source, string $dest, bool $overwrite = false)
    {
        if (is_dir($source)) {
            if (!is_dir($dest)) {
                mkdir($dest);
            }
            $files = scandir($source);
            foreach ($files as $file) {
                if ($file !== "." && $file !== "..") {
                    static::copyDir("$source/$file", "$dest/$file", $overwrite);
                }
            }
        } else if (file_exists($source) && ($overwrite || !file_exists($dest))) {
            copy($source, $dest);
        }
    }

    /**
     * Remove dir
     * @param string $dir
     * @return bool
     */
    public static function removeDir(string $dir): bool
    {
        if (is_link($dir) || is_file($dir)) {
            return unlink($dir);
        }
        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            (is_dir("$dir/$file") && !is_link($dir)) ? static::removeDir("$dir/$file") : unlink("$dir/$file");
        }
        return rmdir($dir);
    }

    public static function scanDir(string $basePath, bool $withBasePath = true): array
    {
        if (!is_dir($basePath)) {
            return [];
        }
        $paths = array_diff(scandir($basePath), array('.', '..')) ?: [];
        return $withBasePath ? array_map(static function ($path) use ($basePath) {
            return $basePath . DIRECTORY_SEPARATOR . $path;
        }, $paths) : $paths;
    }

    public static function getRealpath(string $filePath): string
    {
        if (strpos($filePath, 'phar://') === 0) {
            return $filePath;
        } else {
            return realpath($filePath);
        }
    }
}