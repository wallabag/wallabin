<?php

namespace Wallabag\Wallabin\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Wallabag\Wallabin\Installation;

#[AsCommand(name: 'check', description: 'Check if an update of wallabag is available, without changing anything')]
final class CheckCommand extends ReleaseCommand
{
    public const UPDATE_AVAILABLE = 2;

    protected function configure(): void
    {
        $this->addOption('dir', 'd', InputOption::VALUE_REQUIRED, 'wallabag directory', '.');
        $this->configureReleaseOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $current = $this->installedVersion($io, new Installation($input->getOption('dir')));

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

        $io->text('Run "wallabin update" to update.');

        return self::UPDATE_AVAILABLE;
    }
}
