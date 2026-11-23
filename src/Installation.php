<?php

namespace Wallabag\Wallabin;

use Symfony\Component\Process\Process;

final class Installation
{
    public const KEPT = ['.env.local', '.env.local.php', '.env.prod.local', 'data', 'var', 'web/assets/images'];

    public function __construct(public readonly string $directory)
    {
    }

    public function version(): ?string
    {
        $file = $this->directory . '/app/config/wallabag.yml';

        if (!is_file($file) || !preg_match('/^\s*wallabag\.version:\s*[\'"]?([^\s\'"]+)/m', (string) file_get_contents($file), $matches)) {
            return null;
        }

        return $matches[1];
    }

    public function owner(): ?int
    {
        $path = is_dir($this->directory . '/var') ? $this->directory . '/var' : $this->directory;
        $owner = @fileowner($path);

        return false === $owner ? null : $owner;
    }

    public function sqliteDatabase(): ?string
    {
        $process = new Process([\PHP_BINARY, '-d', 'display_errors=0', '-r', 'require "vendor/autoload.php"; (new Symfony\Component\Dotenv\Dotenv())->bootEnv(".env", "prod"); echo $_SERVER["DATABASE_URL"] ?? "";'], $this->directory, ['APP_ENV' => 'prod']);
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }

        return self::sqlitePath(trim($process->getOutput()), realpath($this->directory));
    }

    public static function sqlitePath(string $databaseUrl, string $directory): ?string
    {
        if (!str_starts_with($databaseUrl, 'sqlite:///')) {
            return null;
        }

        $path = str_replace('%kernel.project_dir%', $directory, explode('?', substr($databaseUrl, 10))[0]);

        return str_starts_with($path, '/') ? $path : $directory . '/' . $path;
    }

    public function replaceWith(string $newDirectory, string $oldDirectory): void
    {
        $topLevelKept = array_filter(self::KEPT, static fn (string $path): bool => !str_contains($path, '/'));
        $nestedKept = array_diff(self::KEPT, $topLevelKept);

        foreach (array_diff(Filesystem::entries($this->directory), $topLevelKept) as $entry) {
            Filesystem::move($this->directory . '/' . $entry, $oldDirectory . '/' . $entry);
        }

        foreach (array_diff(Filesystem::entries($newDirectory), $topLevelKept) as $entry) {
            Filesystem::move($newDirectory . '/' . $entry, $this->directory . '/' . $entry);
        }

        foreach ($nestedKept as $path) {
            if (file_exists($oldDirectory . '/' . $path)) {
                Filesystem::remove($this->directory . '/' . $path);
                Filesystem::move($oldDirectory . '/' . $path, $this->directory . '/' . $path);
            }
        }
    }

    public function restore(string $oldDirectory): void
    {
        $topLevelKept = array_filter(self::KEPT, static fn (string $path): bool => !str_contains($path, '/'));

        foreach (array_diff(self::KEPT, $topLevelKept) as $path) {
            if (file_exists($this->directory . '/' . $path) && !file_exists($oldDirectory . '/' . $path)) {
                Filesystem::move($this->directory . '/' . $path, $oldDirectory . '/' . $path);
            }
        }

        foreach (array_diff(Filesystem::entries($this->directory), $topLevelKept) as $entry) {
            Filesystem::remove($this->directory . '/' . $entry);
        }

        foreach (Filesystem::entries($oldDirectory) as $entry) {
            Filesystem::move($oldDirectory . '/' . $entry, $this->directory . '/' . $entry);
        }
    }

    /**
     * @param list<string> $arguments
     */
    public function console(array $arguments): void
    {
        $process = new Process([\PHP_BINARY, 'bin/console', ...$arguments, '--env=prod', '--no-interaction'], $this->directory, ['APP_ENV' => 'prod'], null, 3600);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(sprintf("bin/console %s failed:\n%s", implode(' ', $arguments), trim($process->getErrorOutput() ?: $process->getOutput())));
        }
    }
}
