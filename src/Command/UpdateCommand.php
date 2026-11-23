<?php

namespace Wallabag\Wallabin\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Wallabag\Wallabin\Installation;

#[AsCommand(name: 'update', description: 'Update wallabag to the latest version: files, database migrations and cache')]
final class UpdateCommand extends ReleaseCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('dir', 'd', InputOption::VALUE_REQUIRED, 'wallabag directory', '.')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Do not ask to confirm that the database is backed up');
        $this->configureReleaseOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $installation = new Installation(rtrim($input->getOption('dir'), '/'));
        $current = $this->installedVersion($io, $installation);

        if (null === $current || !$this->runAsOwner($io, $installation)) {
            return self::FAILURE;
        }

        $databaseUrl = $installation->databaseUrl();

        if (!$this->meetsRequirements($io, $installation->directory, $databaseUrl)) {
            return self::FAILURE;
        }

        $releases = $this->fetchReleases($input);
        $newer = $releases->newerThan($current, false);
        $this->showUpdate($io, $releases, $current, $newer);

        if ([] === $newer) {
            $io->success('wallabag is up to date.');

            return self::SUCCESS;
        }

        $database = $installation->sqliteDatabase($databaseUrl);
        $migrations = [] !== array_filter($newer, static fn (array $release): bool => $release['migrations']);

        if (null === $database && $migrations && !$input->getOption('yes')
            && !$io->confirm('This update changes the database and wallabin can only back up SQLite databases. Did you back up your database?', false)) {
            $io->text('Back up your database, then run the update again with --yes.');

            return self::FAILURE;
        }

        return $this->upgrade($io, $installation, $current, $newer[0], $database);
    }
}
