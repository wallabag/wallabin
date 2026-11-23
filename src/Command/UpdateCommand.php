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

        $releases = $this->fetchReleases($input);
        $newer = $releases->newerThan($current, false);
        $this->showUpdate($io, $releases, $current, $newer);

        if ([] === $newer) {
            $io->success('wallabag is up to date.');

            return self::SUCCESS;
        }

        $database = $installation->sqliteDatabase();
        $migrations = [] !== array_filter($newer, static fn (array $release): bool => $release['migrations']);

        if (null === $database && $migrations && !$input->getOption('yes')
            && !$io->confirm('This update changes the database and wallabin can only back up SQLite databases. Did you back up your database?', false)) {
            $io->text('Back up your database, then run the update again with --yes.');

            return self::FAILURE;
        }

        $release = $newer[0];
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

                $io->text('Running the database migrations…');
                $installation->console(['doctrine:migrations:migrate']);

                $io->text('Clearing the cache…');
                $installation->console(['cache:clear']);
            } catch (\Throwable $e) {
                $installation->restore($oldDirectory);
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

    private function runAsOwner(SymfonyStyle $io, Installation $installation): bool
    {
        $owner = $installation->owner();

        if (!\function_exists('posix_geteuid') || null === $owner || $owner === posix_geteuid()) {
            return true;
        }

        $name = posix_getpwuid($owner)['name'] ?? (string) $owner;
        $io->error([
            sprintf('The files of wallabag belong to %s, but you run wallabin as %s: the updated files would not be writable by your web server.', $name, posix_getpwuid(posix_geteuid())['name'] ?? posix_geteuid()),
            sprintf('Run wallabin as %s, for example: sudo -u %s php wallabin.phar update', $name, $name),
        ]);

        return false;
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
