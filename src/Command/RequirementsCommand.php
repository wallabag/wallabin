<?php

namespace Wallabag\Wallabin\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Wallabag\Wallabin\Installation;
use Wallabag\Wallabin\Requirements;

#[AsCommand(name: 'requirements', description: 'Check that this server can run wallabag')]
final class RequirementsCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('dir', 'd', InputOption::VALUE_REQUIRED, 'wallabag directory, or the directory to install wallabag in', '.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $installation = new Installation(rtrim($input->getOption('dir'), '/'));
        $databaseUrl = null !== $installation->version() ? $installation->databaseUrl() : null;
        $checks = (new Requirements())->check($installation->directory, $databaseUrl);

        foreach ($checks as $requirement => $ok) {
            $io->writeln(sprintf(' %s %s', $ok ? '<info>✔</info>' : '<error>✘</error>', $requirement));
        }

        $io->newLine();
        $io->text('These checks use the PHP of the command line: check that your web server uses the same PHP version and extensions.');

        if (\in_array(false, $checks, true)) {
            $io->error('Some requirements are missing.');

            return self::FAILURE;
        }

        $io->success('This server can run wallabag.');

        return self::SUCCESS;
    }
}
