<?php

namespace Wallabag\Wallabin;

final class Filesystem
{
    public static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        foreach (new \FilesystemIterator($path) as $file) {
            self::remove($file->getPathname());
        }

        rmdir($path);
    }

    public static function move(string $from, string $to): void
    {
        if (!is_dir(\dirname($to))) {
            mkdir(\dirname($to), 0777, true);
        }

        if (!rename($from, $to)) {
            throw new \RuntimeException(sprintf('Cannot move %s to %s', $from, $to));
        }
    }

    /**
     * @return list<string>
     */
    public static function entries(string $directory): array
    {
        return array_values(array_diff(scandir($directory), ['.', '..']));
    }
}
