<?php

namespace Wallabag\Wallabin\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Wallabag\Wallabin\Filesystem;
use Wallabag\Wallabin\Installation;
use Wallabag\Wallabin\LegacyParameters;
use Wallabag\Wallabin\Package;

#[AsCommand(name: 'install', description: 'Install wallabag in a new directory')]
final class InstallCommand extends ReleaseCommand
{
    private const DATABASES = ['sqlite' => 'pdo_sqlite', 'mysql' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql'];

    protected function configure(): void
    {
        $this
            ->addArgument('directory', InputArgument::OPTIONAL, 'Directory to install wallabag in, it must be empty or not exist', 'wallabag')
            ->addOption('release', 'r', InputOption::VALUE_REQUIRED, 'Version to install, the latest stable one by default')
            ->addOption('pre', null, InputOption::VALUE_NONE, 'Install the latest test version (alpha, beta or RC), it will not be updated by wallabin')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'URL of your wallabag instance')
            ->addOption('database-url', null, InputOption::VALUE_REQUIRED, 'Database, for example mysql://user:password@127.0.0.1:3306/wallabag, SQLite by default')
            ->addOption('admin-password', null, InputOption::VALUE_REQUIRED, 'Password of the "wallabag" admin user');
        $this->configureReleaseOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $directory = rtrim($input->getArgument('directory'), '/');

        if (is_dir($directory) && [] !== Filesystem::entries($directory)) {
            $io->error(sprintf('%s is not empty.', $directory));

            return self::FAILURE;
        }

        if (!$this->meetsRequirements($io, $directory)) {
            return self::FAILURE;
        }

        if (!$input->isInteractive() && null === $input->getOption('admin-password')) {
            $io->error('Give the password of the admin user with --admin-password.');

            return self::FAILURE;
        }

        $env = ['APP_ENV' => 'prod', 'APP_SECRET' => bin2hex(random_bytes(16))] + $this->askConfiguration($io, $input);
        $adminPassword = $input->getOption('admin-password') ?? $this->askAdminPassword($io);

        if (!$this->meetsRequirements($io, $directory, $env['DATABASE_URL'])) {
            return self::FAILURE;
        }

        $releases = $this->fetchReleases($input);
        $release = $input->getOption('release')
            ? $releases->find($input->getOption('release'))
            : ($releases->newerThan('0', $input->getOption('pre'))[0] ?? null);

        if (null === $release) {
            $io->error('No wallabag release found for this PHP version.');

            return self::FAILURE;
        }

        if (null !== $release['php_min'] && version_compare(\PHP_VERSION, $release['php_min'], '<')) {
            $io->error(sprintf('wallabag %s needs PHP %s or later, you run PHP %s.', $release['version'], $release['php_min'], \PHP_VERSION));

            return self::FAILURE;
        }

        $this->warnIfUnsigned($io, $release);
        $io->text(sprintf('Downloading wallabag %s…', $release['version']));
        $workDirectory = \dirname($directory) . '/.wallabin-' . bin2hex(random_bytes(4));

        try {
            $package = Package::extract($release, $workDirectory);
            Filesystem::remove($directory);
            Filesystem::move($package, $directory);
            file_put_contents($directory . '/.env.local', LegacyParameters::format($env));
        } finally {
            Filesystem::remove($workDirectory);
        }

        $installation = new Installation($directory);
        $io->text('Creating the database…');

        try {
            if (!str_starts_with($env['DATABASE_URL'], 'sqlite:')) {
                $installation->console(['doctrine:database:create', '--if-not-exists']);
            }

            $installation->console(['wallabag:install']);
            $installation->console(['fos:user:change-password', 'wallabag', $adminPassword]);
        } catch (\Throwable $e) {
            $io->error(sprintf("wallabag %s is extracted in %s, but its installation failed. Fix the configuration in %s/.env.local, then run \"php bin/console wallabag:install\" in this directory.\n%s", $release['version'], $directory, $directory, $e->getMessage()));

            return self::FAILURE;
        }

        $io->success(sprintf('wallabag %s is installed in %s.', $release['version'], $directory));
        $io->text([
            'Next steps:',
            sprintf('1. Point the document root of your web server to %s/web, with index.php as front controller', realpath($directory)),
            '2. Give the files to the user of your web server, for example: sudo chown -R www-data: ' . $directory,
            sprintf('3. Open %s and log in with the "wallabag" user and your password', $env['WALLABAG_BASE_URL'] ?? 'your wallabag instance'),
            'See https://doc.wallabag.org/admin/installation/',
        ]);

        return self::SUCCESS;
    }

    private function askAdminPassword(SymfonyStyle $io): string
    {
        $question = (new Question('Password of the "wallabag" admin user'))
            ->setHidden(true)
            ->setHiddenFallback(true)
            ->setValidator(static function (?string $password): string {
                if (null === $password || \strlen($password) < 8) {
                    throw new \RuntimeException('The password must be at least 8 characters long.');
                }

                return $password;
            });

        return $io->askQuestion($question);
    }

    /**
     * @return array{WALLABAG_BASE_URL?: string, DATABASE_URL: string}
     */
    private function askConfiguration(SymfonyStyle $io, InputInterface $input): array
    {
        $env = [];
        $url = $input->getOption('url') ?? ($input->isInteractive() ? $io->ask('URL of your wallabag instance', 'http://localhost:8000') : null);

        if (null !== $url) {
            $env['WALLABAG_BASE_URL'] = rtrim($url, '/');
        }

        if (null !== $input->getOption('database-url') || !$input->isInteractive()) {
            return $env + ['DATABASE_URL' => $input->getOption('database-url') ?? 'sqlite:///%kernel.project_dir%/data/db/wallabag.sqlite'];
        }

        $database = $io->choice('Database', array_keys(self::DATABASES), 'sqlite');

        if ('sqlite' === $database) {
            return $env + ['DATABASE_URL' => 'sqlite:///%kernel.project_dir%/data/db/wallabag.sqlite'];
        }

        $password = (new Question('Password of the database user'))->setHidden(true)->setHiddenFallback(true);

        return $env + ['DATABASE_URL' => LegacyParameters::databaseUrl([
            'database_driver' => self::DATABASES[$database],
            'database_host' => $io->ask('Database host', '127.0.0.1'),
            'database_port' => $io->ask('Database port', 'mysql' === $database ? '3306' : '5432'),
            'database_name' => $io->ask('Database name', 'wallabag'),
            'database_user' => $io->ask('Database user', 'wallabag'),
            'database_password' => $io->askQuestion($password),
            'database_charset' => 'mysql' === $database ? 'utf8mb4' : 'utf8',
        ])];
    }
}
