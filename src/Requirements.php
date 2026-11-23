<?php

namespace Wallabag\Wallabin;

use Symfony\Component\Process\ExecutableFinder;

final class Requirements
{
    public const PHP_MIN = '8.2';

    public const EXTENSIONS = ['ctype', 'curl', 'dom', 'filter', 'gd', 'hash', 'iconv', 'intl', 'json', 'libxml', 'mbstring', 'pcre', 'pdo', 'session', 'simplexml', 'tidy', 'tokenizer', 'xml'];

    public const DATABASE_DRIVERS = ['sqlite' => 'pdo_sqlite', 'mysql' => 'pdo_mysql', 'pgsql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql', 'postgresql' => 'pdo_pgsql'];

    /** @var list<string> */
    private readonly array $extensions;

    /**
     * @param list<string>|null $extensions
     */
    public function __construct(
        private readonly string $phpVersion = \PHP_VERSION,
        ?array $extensions = null,
        private readonly ?bool $tar = null,
    ) {
        $this->extensions = array_map('strtolower', $extensions ?? get_loaded_extensions());
    }

    /**
     * @return array<string, bool>
     */
    public function check(string $directory, ?string $databaseUrl = null): array
    {
        $checks = [sprintf('PHP %s or later (you run PHP %s)', self::PHP_MIN, $this->phpVersion) => version_compare($this->phpVersion, self::PHP_MIN, '>=')];

        foreach (self::EXTENSIONS as $extension) {
            $checks[sprintf('PHP extension %s', $extension)] = $this->hasExtension($extension);
        }

        $driver = self::DATABASE_DRIVERS[strtolower((string) strstr((string) $databaseUrl, ':', true))] ?? null;

        if (null !== $driver) {
            $checks[sprintf('PHP extension %s for your database', $driver)] = $this->hasExtension($driver);
        } else {
            $checks['PHP extension pdo_sqlite, pdo_mysql or pdo_pgsql for your database'] = (bool) array_filter(array_unique(self::DATABASE_DRIVERS), $this->hasExtension(...));
        }

        $checks['tar command'] = $this->tar ?? null !== (new ExecutableFinder())->find('tar');

        $existing = $directory;

        while (!file_exists($existing) && \dirname($existing) !== $existing) {
            $existing = \dirname($existing);
        }

        $checks[sprintf('%s is writable', $directory)] = is_dir($existing) && is_writable($existing);

        return $checks;
    }

    /**
     * @return list<string>
     */
    public function failures(string $directory, ?string $databaseUrl = null): array
    {
        return array_keys(array_filter($this->check($directory, $databaseUrl), static fn (bool $ok): bool => !$ok));
    }

    private function hasExtension(string $extension): bool
    {
        return \in_array($extension, $this->extensions, true);
    }
}
