<?php

namespace Wallabag\Wallabin\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Wallabag\Wallabin\Installation;
use Wallabag\Wallabin\Releases;

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
}
