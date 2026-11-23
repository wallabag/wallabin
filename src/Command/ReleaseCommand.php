<?php

namespace Wallabag\Wallabin\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Wallabag\Wallabin\Filesystem;
use Wallabag\Wallabin\Installation;
use Wallabag\Wallabin\Package;
use Wallabag\Wallabin\Releases;
use Wallabag\Wallabin\Requirements;

abstract class ReleaseCommand extends Command
{
    protected function configureReleaseOptions(): void
    {
        $this
            ->addOption('releases-url', null, InputOption::VALUE_REQUIRED, 'URL of releases.json', Releases::URL);
    }

    protected function fetchReleases(InputInterface $input): Releases
    {
        return Releases::fetch($input->getOption('releases-url'));
    }

    protected function installedVersion(SymfonyStyle $io, Installation $installation): ?string
    {
        $version = $installation->version();

        if (null === $version) {
            $io->error(sprintf('No wallabag installation found in %s.', $installation->directory));

            return null;
        }

        if (version_compare($version, '2.7.0-alpha.1', '<')) {
            $io->error(sprintf('wallabag %s can\'t be updated with wallabin: please follow the upgrade documentation to move to 2.7.', $version));

            return null;
        }

        return $version;
    }

    protected function runAsOwner(SymfonyStyle $io, Installation $installation): bool
    {
        $owner = $installation->owner();

        if (!\function_exists('posix_geteuid') || null === $owner || $owner === posix_geteuid()) {
            return true;
        }

        $name = posix_getpwuid($owner)['name'] ?? (string) $owner;
        $io->error([
            sprintf('The files of wallabag belong to %s, but you run wallabin as %s: the updated files would not be writable by your web server.', $name, posix_getpwuid(posix_geteuid())['name'] ?? posix_geteuid()),
            sprintf('Run wallabin as %s, for example: sudo -u %s php wallabin.phar %s', $name, $name, $this->getName()),
        ]);

        return false;
    }

    protected function meetsRequirements(SymfonyStyle $io, string $directory, ?string $databaseUrl = null): bool
    {
        $failures = (new Requirements())->failures($directory, $databaseUrl);

        if ([] === $failures) {
            return true;
        }

        $io->error(array_merge(['wallabag can\'t run on this server, these requirements are missing:'], array_map(static fn (string $failure): string => '- ' . $failure, $failures)));

        return false;
    }

    /**
     * @param list<array<string, mixed>> $releases
     */
    protected function showUpdate(SymfonyStyle $io, Releases $all, string $current, array $releases): void
    {
        $io->text(sprintf('Installed version: %s', $current));

        if (str_contains($current, '-')) {
            $io->note('wallabin only updates to stable versions. To try a newer test version, install a new instance with "wallabin install --pre".');
        }

        foreach ($all->needingNewerPhp($current, false) as $release) {
            $io->warning(sprintf('wallabag %s needs PHP %s or later, you run PHP %s.', $release['version'], $release['php_min'], \PHP_VERSION));
        }

        if ([] === $releases) {
            return;
        }

        $io->text(sprintf('Available version: %s (%s)', $releases[0]['version'], $releases[0]['release_url']));

        if (array_filter($releases, static fn (array $release): bool => $release['security'])) {
            $io->caution('This update fixes security issues: update as soon as possible.');
        }

        if (array_filter($releases, static fn (array $release): bool => $release['migrations'])) {
            $io->note('This update changes the database: back it up before updating.');
        }
    }

    /**
     * @param array<string, mixed>          $release
     * @param (callable(string): void)|null $afterReplace
     * @param (callable(): void)|null       $onFailure
     */
    protected function upgrade(SymfonyStyle $io, Installation $installation, string $current, array $release, ?string $database, ?callable $afterReplace = null, ?callable $onFailure = null): int
    {
        $this->warnIfUnsigned($io, $release);

        $wallabinDirectory = $installation->directory . '/var/wallabin';
        $workDirectory = $wallabinDirectory . '/work';
        $oldDirectory = $workDirectory . '/old';
        $databaseCopy = $workDirectory . '/wallabag.sqlite';

        $io->text(sprintf('Downloading wallabag %s…', $release['version']));
        Filesystem::remove($workDirectory);

        try {
            $package = Package::extract($release, $workDirectory . '/new');
            mkdir($oldDirectory, 0777, true);

            if (null !== $database && is_file($database) && !copy($database, $databaseCopy)) {
                throw new \RuntimeException(sprintf('Cannot back up the database %s', $database));
            }

            try {
                $io->text('Replacing the files…');
                $installation->replaceWith($package, $oldDirectory);

                if (null !== $afterReplace) {
                    $afterReplace($oldDirectory);
                }

                $installation->clearCache();

                $io->text('Running the database migrations…');
                $installation->console(['doctrine:migrations:migrate']);

                $io->text('Clearing the cache…');
                $installation->console(['cache:clear']);
            } catch (\Throwable $e) {
                if (null !== $onFailure) {
                    $onFailure();
                }

                $installation->restore($oldDirectory);
                $installation->clearCache();
                $databaseRestored = is_file($databaseCopy) && copy($databaseCopy, $database);
                $io->error(sprintf("The update failed, the files of wallabag %s are restored. %s\n%s", $current, $databaseRestored ? 'The database is restored.' : 'The database is not restored.', $e->getMessage()));

                return self::FAILURE;
            }

            $backup = $this->keepBackup($wallabinDirectory, $current, $oldDirectory, $databaseCopy);
        } finally {
            Filesystem::remove($workDirectory);
        }

        $io->success(sprintf('wallabag is updated to %s. The files of wallabag %s%s are kept in %s.', $release['version'], $current, null !== $database ? ' and its database' : '', $backup));

        return self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $release
     */
    protected function warnIfUnsigned(SymfonyStyle $io, array $release): void
    {
        if (null === ($release['signature_url'] ?? null)) {
            $io->warning(sprintf('The wallabag %s package is not signed: only its SHA-256 checksum is checked.', $release['version']));
        }
    }

    private function keepBackup(string $wallabinDirectory, string $version, string $oldDirectory, string $databaseCopy): string
    {
        foreach (glob($wallabinDirectory . '/backup-*') ?: [] as $previous) {
            Filesystem::remove($previous);
        }

        $backup = $wallabinDirectory . '/backup-' . $version;
        Filesystem::move($oldDirectory, $backup);

        if (is_file($databaseCopy)) {
            Filesystem::move($databaseCopy, $backup . '/wallabag.sqlite');
        }

        return $backup;
    }
}
