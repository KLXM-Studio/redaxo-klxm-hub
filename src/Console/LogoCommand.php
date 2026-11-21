<?php

namespace Klxm\Hub\Console;

use Klxm\Hub\Logo;
use Klxm\Hub\Settings;
use Redaxo\Core\Console\AbstractCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'klxm_hub:logo', description: 'Logo des Anbieters (Discovery logo_uri) für den Anmelde-Link neu laden')]
final class LogoCommand extends AbstractCommand
{
    public function __invoke(SymfonyStyle $io): int
    {
        $issuer = (string) (Settings::oidc()['issuer'] ?? '');
        if ('' === $issuer) {
            $io->writeln('Kein Anbieter eingerichtet.');

            return Command::FAILURE;
        }
        $io->writeln(Logo::refresh($issuer) ? 'Logo geladen.' : 'Kein Logo (Anbieter nennt keins oder es ist ungültig).');

        return Command::SUCCESS;
    }
}
