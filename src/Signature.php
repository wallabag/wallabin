<?php

namespace Wallabag\Wallabin;

final class Signature
{
    public const PUBLIC_KEY = 'RWQT4s5qtqdNSaZOi9peL3i+pP/KMMzpk/c9XJd84ZIpxFWaGqYOlwy+';

    public static function verify(string $file, string $signature, string $publicKey = self::PUBLIC_KEY): void
    {
        $key = base64_decode($publicKey, true);

        if (false === $key || 42 !== \strlen($key) || 'Ed' !== substr($key, 0, 2)) {
            throw new \RuntimeException('Invalid public key');
        }

        $lines = preg_split('/\r?\n/', trim($signature));
        $decoded = base64_decode($lines[1] ?? '', true);
        $globalSignature = base64_decode($lines[3] ?? '', true);

        if (4 !== \count($lines) || false === $decoded || 74 !== \strlen($decoded) || !str_starts_with($lines[2], 'trusted comment: ') || false === $globalSignature || 64 !== \strlen($globalSignature)) {
            throw new \RuntimeException(sprintf('Invalid signature for %s', basename($file)));
        }

        $algorithm = substr($decoded, 0, 2);
        $signatureBytes = substr($decoded, 10);
        $publicKeyBytes = substr($key, 10);

        if (substr($decoded, 2, 8) !== substr($key, 2, 8)) {
            throw new \RuntimeException(sprintf('%s is not signed with the wallabag key', basename($file)));
        }

        $message = match ($algorithm) {
            'ED' => self::hash($file),
            'Ed' => (string) file_get_contents($file),
            default => throw new \RuntimeException(sprintf('Invalid signature for %s', basename($file))),
        };

        if (!sodium_crypto_sign_verify_detached($signatureBytes, $message, $publicKeyBytes)
            || !sodium_crypto_sign_verify_detached($globalSignature, $signatureBytes . substr($lines[2], \strlen('trusted comment: ')), $publicKeyBytes)) {
            throw new \RuntimeException(sprintf('The signature of %s doesn\'t match: the file may have been tampered with.', basename($file)));
        }
    }

    private static function hash(string $file): string
    {
        $state = sodium_crypto_generichash_init('', \SODIUM_CRYPTO_GENERICHASH_BYTES_MAX);
        $handle = fopen($file, 'r');

        while (!feof($handle)) {
            sodium_crypto_generichash_update($state, (string) fread($handle, 1048576));
        }

        fclose($handle);

        return sodium_crypto_generichash_final($state, \SODIUM_CRYPTO_GENERICHASH_BYTES_MAX);
    }
}
