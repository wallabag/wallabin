<?php

namespace Wallabag\Wallabin\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Wallabag\Wallabin\Filesystem;
use Wallabag\Wallabin\Package;

#[AsCommand(name: 'install', description: 'Download and extract a wallabag release in a new directory')]
final class InstallCommand extends ReleaseCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('directory', InputArgument::OPTIONAL, 'Directory to install wallabag in, it must be empty or not exist', 'wallabag')
            ->addOption('release', 'r', InputOption::VALUE_REQUIRED, 'Version to install, the latest one by default');
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

        $io->text(sprintf('Downloading wallabag %s…', $release['version']));
        $workDirectory = \dirname($directory) . '/.wallabin-' . bin2hex(random_bytes(4));

        try {
            $package = Package::extract($release, $workDirectory);
            Filesystem::remove($directory);
            Filesystem::move($package, $directory);
            file_put_contents($directory . '/.env.local', sprintf("APP_SECRET=%s\n", bin2hex(random_bytes(16))));
        } finally {
            Filesystem::remove($workDirectory);
        }

        $io->success(sprintf('wallabag %s is installed in %s.', $release['version'], $directory));
        $io->text([
            'Next steps:',
            sprintf('1. Set your configuration (database, URL of your instance…) in %s/.env.local', $directory),
            sprintf('2. Run "cd %s && php bin/console wallabag:install" to create the database and your user', $directory),
            'See https://doc.wallabag.org/admin/installation/',
        ]);

        return self::SUCCESS;
    }
}
