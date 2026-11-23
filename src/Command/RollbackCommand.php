<?php

namespace Wallabag\Wallabin\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Wallabag\Wallabin\Filesystem;
use Wallabag\Wallabin\Installation;

#[AsCommand(name: 'rollback', description: 'Go back to the version of wallabag before the last update')]
final class RollbackCommand extends ReleaseCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('dir', 'd', InputOption::VALUE_REQUIRED, 'wallabag directory', '.')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Do not ask for confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $installation = new Installation(rtrim($input->getOption('dir'), '/'));
        $current = $this->installedVersion($io, $installation);

        if (null === $current || !$this->runAsOwner($io, $installation)) {
            return self::FAILURE;
        }

        $backup = $installation->backup();
        $previous = null === $backup ? null : (new Installation($backup))->version();

        if (null === $previous) {
            $io->error('No backup found: wallabin keeps one after each update, in var/wallabin/.');

            return self::FAILURE;
        }

        $databaseChanged = [] !== $installation->migrationsSince($backup);
        $database = $databaseChanged ? $installation->sqliteDatabase($installation->databaseUrl()) : null;
        $databaseBackup = $backup . '/wallabag.sqlite';
        $restoreDatabase = null !== $database && is_file($databaseBackup);

        $io->text(sprintf('wallabag %s goes back to %s.', $current, $previous));

        if ($restoreDatabase) {
            $io->warning('The last update changed the database: the SQLite database goes back to its state before the update. Everything saved since then (articles, tags, annotations…) is lost.');
        } elseif ($databaseChanged) {
            $io->warning('The last update changed the database and wallabin can\'t restore it: restore the backup of your database made before the update, or wallabag may not work.');
        }

        if (!$input->getOption('yes') && !$io->confirm('Go back to the previous version?', false)) {
            $io->text('Nothing changed. Use --yes to confirm without a question.');

            return self::FAILURE;
        }

        $workDirectory = $installation->directory . '/var/wallabin/work';
        $oldDirectory = $workDirectory . '/old';
        $currentDatabase = $workDirectory . '/current.sqlite';
        Filesystem::remove($workDirectory);
        mkdir($oldDirectory, 0777, true);

        try {
            if (is_file($databaseBackup)) {
                Filesystem::move($databaseBackup, $workDirectory . '/backup.sqlite');
            }

            if ($restoreDatabase && !copy($database, $currentDatabase)) {
                throw new \RuntimeException(sprintf('Cannot copy the database %s', $database));
            }

            $installation->replaceWith($backup, $oldDirectory);
            $installation->clearCache();

            try {
                if ($restoreDatabase && !copy($workDirectory . '/backup.sqlite', $database)) {
                    throw new \RuntimeException(sprintf('Cannot restore the database %s', $database));
                }

                $io->text('Clearing the cache…');
                $installation->console(['cache:clear']);
            } catch (\Throwable $e) {
                $installation->replaceWith($oldDirectory, $backup);

                if ($restoreDatabase) {
                    copy($currentDatabase, $database);
                }

                if (is_file($workDirectory . '/backup.sqlite')) {
                    Filesystem::move($workDirectory . '/backup.sqlite', $databaseBackup);
                }

                $io->error(sprintf("The rollback failed, wallabag %s is restored.\n%s", $current, $e->getMessage()));

                return self::FAILURE;
            }

            Filesystem::remove($backup);

            if (version_compare($previous, '2.7.0-alpha.1', '<')) {
                Filesystem::remove($installation->directory . '/.env.local');
            }
        } finally {
            Filesystem::remove($workDirectory);
        }

        $io->success(sprintf('wallabag is back to %s.', $previous));

        return self::SUCCESS;
    }
}
