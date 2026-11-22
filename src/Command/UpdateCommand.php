<?php

namespace Wallabag\Wallabin\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Wallabag\Wallabin\Filesystem;
use Wallabag\Wallabin\Installation;
use Wallabag\Wallabin\Package;

#[AsCommand(name: 'update', description: 'Update wallabag to the latest version: files, database migrations and cache')]
final class UpdateCommand extends ReleaseCommand
{
    protected function configure(): void
    {
        $this->addOption('dir', 'd', InputOption::VALUE_REQUIRED, 'wallabag directory', '.');
        $this->configureReleaseOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $installation = new Installation(rtrim($input->getOption('dir'), '/'));
        $current = $this->installedVersion($io, $installation);

        if (null === $current) {
            return self::FAILURE;
        }

        $releases = $this->fetchReleases($input);
        $prereleases = $input->getOption('pre') || str_contains($current, '-');
        $newer = $releases->newerThan($current, $prereleases);
        $this->showUpdate($io, $releases, $current, $prereleases, $newer);

        if ([] === $newer) {
            $io->success('wallabag is up to date.');

            return self::SUCCESS;
        }

        $release = $newer[0];
        $workDirectory = $installation->directory . '/var/wallabin';
        $oldDirectory = $workDirectory . '/old';

        $io->text(sprintf('Downloading wallabag %s…', $release['version']));
        Filesystem::remove($workDirectory);

        try {
            $package = Package::extract($release, $workDirectory . '/new');
            mkdir($oldDirectory, 0777, true);

            try {
                $io->text('Replacing the files…');
                $installation->replaceWith($package, $oldDirectory);

                $io->text('Running the database migrations…');
                $installation->console(['doctrine:migrations:migrate']);

                $io->text('Clearing the cache…');
                $installation->console(['cache:clear']);
            } catch (\Throwable $e) {
                $installation->restore($oldDirectory);
                $io->error(sprintf("The update failed, the files of wallabag %s are restored. The database is not restored.\n%s", $current, $e->getMessage()));

                return self::FAILURE;
            }
        } finally {
            Filesystem::remove($workDirectory);
        }

        $io->success(sprintf('wallabag is updated to %s.', $release['version']));

        return self::SUCCESS;
    }
}
