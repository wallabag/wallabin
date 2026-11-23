<?php

namespace Wallabag\Wallabin\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Wallabag\Wallabin\Filesystem;
use Wallabag\Wallabin\Installation;
use Wallabag\Wallabin\LegacyParameters;

#[AsCommand(name: 'migrate', description: 'Migrate a wallabag 2.6 installation to 2.7')]
final class MigrateCommand extends ReleaseCommand
{
    private const PARAMETERS = 'app/config/parameters.yml';

    protected function configure(): void
    {
        $this
            ->addOption('dir', 'd', InputOption::VALUE_REQUIRED, 'wallabag directory', '.')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Do not ask for confirmation');
        $this->configureReleaseOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $installation = new Installation(rtrim($input->getOption('dir'), '/'));
        $current = $installation->version();

        if (null === $current) {
            $io->error(sprintf('No wallabag installation found in %s.', $installation->directory));

            return self::FAILURE;
        }

        if (version_compare($current, '2.7.0-alpha.1', '>=')) {
            $io->error(sprintf('wallabag %s is already a 2.7 version: use "wallabin update".', $current));

            return self::FAILURE;
        }

        if (version_compare($current, '2.6.0', '<')) {
            $io->error(sprintf('wallabin migrates wallabag 2.6 only: update wallabag %s to the latest 2.6 patch version first, with "make update".', $current));

            return self::FAILURE;
        }

        if (!$this->runAsOwner($io, $installation)) {
            return self::FAILURE;
        }

        $parametersFile = $installation->directory . '/' . self::PARAMETERS;
        $envFile = $installation->directory . '/.env.local';

        if (file_exists($envFile)) {
            $io->error(sprintf('%s already exists: wallabin creates it from your parameters.yml file. Move it away and run the migration again.', $envFile));

            return self::FAILURE;
        }

        try {
            $env = ['APP_ENV' => 'prod'] + LegacyParameters::toEnv(LegacyParameters::read($parametersFile));
        } catch (\Throwable $e) {
            $io->error(sprintf("Cannot read the configuration of wallabag from %s.\n%s", $parametersFile, $e->getMessage()));

            return self::FAILURE;
        }

        if (!$this->meetsRequirements($io, $installation->directory, $env['DATABASE_URL'])) {
            return self::FAILURE;
        }

        $release = $this->fetchReleases($input)->newerThan($current, false)[0] ?? null;

        if (null === $release || version_compare($release['version'], '2.7.0', '<')) {
            $io->error('No stable wallabag 2.7 release is available for this PHP version yet.');

            return self::FAILURE;
        }

        $database = $installation->sqliteDatabase($env['DATABASE_URL']);

        $io->text([
            sprintf('wallabag %s will be migrated to %s (%s):', $current, $release['version'], $release['release_url']),
            '- the files are replaced by the ones of the new version, the files of the current version are kept in var/wallabin/',
            '- the configuration of app/config/parameters.yml moves to .env.local',
            '- the database migrations run' . (null !== $database ? ', your SQLite database is backed up first' : ''),
        ]);

        if (null === $database) {
            $io->warning('wallabin can only back up SQLite databases: back up your database before the migration.');
        }

        if (!$input->getOption('yes') && !$io->confirm(null === $database ? 'Did you back up your database? Migrate now?' : 'Migrate now?', false)) {
            $io->text('Nothing changed. Use --yes to confirm without a question.');

            return self::FAILURE;
        }

        $result = $this->upgrade(
            $io,
            $installation,
            $current,
            $release,
            $database,
            static function (string $oldDirectory) use ($installation, $env, $envFile): void {
                Filesystem::move($installation->directory . '/' . self::PARAMETERS, $oldDirectory . '/' . self::PARAMETERS);
                file_put_contents($envFile, LegacyParameters::format($env));
            },
            static fn () => Filesystem::remove($envFile),
        );

        if (self::SUCCESS === $result) {
            $io->note([
                'Your web server configuration may need changes:',
                '- the built assets moved from web/wallassets/ to web/build/',
                '- web/app.php still works, but web/index.php is the new front controller',
                'See https://doc.wallabag.org/admin/upgrade/',
            ]);
        }

        return $result;
    }
}
