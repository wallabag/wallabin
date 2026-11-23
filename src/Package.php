<?php

namespace Wallabag\Wallabin;

use Symfony\Component\Process\Process;

final class Package
{
    /**
     * @param array<string, mixed> $release
     */
    public static function extract(array $release, string $workDirectory): string
    {
        Filesystem::remove($workDirectory);
        mkdir($workDirectory, 0777, true);

        $archive = $workDirectory . '/wallabag.tar.gz';
        Http::download($release['tarball_url'], $archive);

        if (!hash_equals($release['sha256'], hash_file('sha256', $archive))) {
            throw new \RuntimeException(sprintf('The SHA-256 checksum of the wallabag %s package doesn\'t match: the download is corrupted.', $release['version']));
        }

        if (null !== ($release['signature_url'] ?? null)) {
            Signature::verify($archive, Http::get($release['signature_url']));
        }

        mkdir($workDirectory . '/extract');
        (new Process(['tar', '-xzf', $archive, '-C', $workDirectory . '/extract'], null, null, null, 600))->mustRun();
        unlink($archive);

        $entries = Filesystem::entries($workDirectory . '/extract');

        if (1 !== \count($entries)) {
            throw new \RuntimeException('Unexpected content in the wallabag package.');
        }

        return $workDirectory . '/extract/' . $entries[0];
    }
}
