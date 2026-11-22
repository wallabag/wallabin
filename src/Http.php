<?php

namespace Wallabag\Wallabin;

final class Http
{
    public static function get(string $url): string
    {
        $handle = self::init($url, 30);
        curl_setopt($handle, \CURLOPT_RETURNTRANSFER, true);
        $body = curl_exec($handle);
        self::check($handle, $url, false !== $body);

        return $body;
    }

    public static function download(string $url, string $destination): void
    {
        $file = fopen($destination, 'w');
        $handle = self::init($url, 600);
        curl_setopt($handle, \CURLOPT_FILE, $file);
        $success = curl_exec($handle);
        fclose($file);

        try {
            self::check($handle, $url, false !== $success);
        } catch (\RuntimeException $e) {
            @unlink($destination);

            throw $e;
        }
    }

    private static function init(string $url, int $timeout): \CurlHandle
    {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            \CURLOPT_FOLLOWLOCATION => true,
            \CURLOPT_TIMEOUT => $timeout,
            \CURLOPT_USERAGENT => 'wallabin',
        ]);

        return $handle;
    }

    private static function check(\CurlHandle $handle, string $url, bool $success): void
    {
        $code = curl_getinfo($handle, \CURLINFO_RESPONSE_CODE);

        if (!$success || $code >= 400) {
            throw new \RuntimeException(sprintf('Cannot download %s (HTTP %d: %s)', $url, $code, curl_error($handle)));
        }
    }
}
