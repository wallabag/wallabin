<?php

namespace Wallabag\Wallabin\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;
use Wallabag\Wallabin\Http;
use Wallabag\Wallabin\Signature;

#[AsCommand(name: 'self-update', description: 'Update wallabin to its latest version')]
final class SelfUpdateCommand extends Command
{
    public const RELEASE_URL = 'https://api.github.com/repos/wallabag/wallabin/releases/latest';

    protected function configure(): void
    {
        $this->addOption('release-url', null, InputOption::VALUE_REQUIRED, 'URL of the latest wallabin release, in the format of the GitHub API', self::RELEASE_URL);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $phar = \Phar::running(false);
        $current = (string) $this->getApplication()?->getVersion();

        if ('' === $phar) {
            $io->error('self-update only works with wallabin.phar.');

            return self::FAILURE;
        }

        if (!preg_match('/^v?\d+\.\d+\.\d+$/', $current)) {
            $io->error(sprintf('This is a development build of wallabin (%s): download a release from https://github.com/wallabag/wallabin/releases instead.', $current));

            return self::FAILURE;
        }

        $release = json_decode(Http::get($input->getOption('release-url')), true, 512, \JSON_THROW_ON_ERROR);
        $latest = ltrim((string) ($release['tag_name'] ?? ''), 'v');
        $assets = array_column($release['assets'] ?? [], 'browser_download_url', 'name');

        if (!isset($assets['wallabin.phar'], $assets['wallabin.phar.minisig'])) {
            $io->error(sprintf('The wallabin %s release has no signed wallabin.phar.', $latest));

            return self::FAILURE;
        }

        if (version_compare($latest, ltrim($current, 'v'), '<=')) {
            $io->success(sprintf('wallabin %s is up to date.', $current));

            return self::SUCCESS;
        }

        if (!is_writable($phar) || !is_writable(\dirname($phar))) {
            $io->error(sprintf('%s is not writable: run self-update as the owner of this file.', $phar));

            return self::FAILURE;
        }

        $io->text(sprintf('Downloading wallabin %s…', $latest));
        $new = \dirname($phar) . '/.wallabin-' . bin2hex(random_bytes(4)) . '.phar';

        try {
            Http::download($assets['wallabin.phar'], $new);
            Signature::verify($new, Http::get($assets['wallabin.phar.minisig']));
            chmod($new, fileperms($phar) & 0777);
            (new Process([\PHP_BINARY, $new, '--version']))->mustRun();
            $io->text(sprintf('Replacing %s…', $phar));

            if (!rename($new, $phar)) {
                throw new \RuntimeException(sprintf('Cannot replace %s', $phar));
            }
        } catch (\Throwable $e) {
            @unlink($new);
            $io->error(sprintf("wallabin is not updated.\n%s", $e->getMessage()));

            return self::FAILURE;
        }

        $output->writeln(sprintf('<info>wallabin is updated from %s to %s.</info>', $current, $latest));

        exit(self::SUCCESS);
    }
}
