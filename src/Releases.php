<?php

namespace Wallabag\Wallabin;

final class Releases
{
    public const URL = 'https://wallabag.org/releases.json';

    public static function fetch(string $url = self::URL): self
    {
        return new self(json_decode(Http::get($url), true, 512, \JSON_THROW_ON_ERROR)['releases']);
    }

    /**
     * @param list<array<string, mixed>> $releases
     */
    public function __construct(private readonly array $releases)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function newerThan(string $current, bool $prereleases, string $phpVersion = \PHP_VERSION): array
    {
        $releases = array_values(array_filter($this->releases, static fn (array $release): bool => version_compare($release['version'], $current, '>')
            && ($prereleases || !$release['prerelease'])
            && (null === $release['php_min'] || version_compare($phpVersion, $release['php_min'], '>='))));

        usort($releases, static fn (array $a, array $b): int => version_compare($b['version'], $a['version']));

        return $releases;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $version): ?array
    {
        foreach ($this->releases as $release) {
            if ($release['version'] === $version) {
                return $release;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function needingNewerPhp(string $current, bool $prereleases, string $phpVersion = \PHP_VERSION): array
    {
        return array_values(array_filter($this->releases, static fn (array $release): bool => version_compare($release['version'], $current, '>')
            && ($prereleases || !$release['prerelease'])
            && null !== $release['php_min']
            && version_compare($phpVersion, $release['php_min'], '<')));
    }
}
