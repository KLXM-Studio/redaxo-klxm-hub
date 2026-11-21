<?php

namespace Klxm\Hub\Console;

use Klxm\Hub\SelfTest;
use Redaxo\Core\Console\AbstractCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'klxm_hub:selftest', description: 'Prüft Status-Signatur, PKCE, OIDC-Client und ID-Token-Prüfungen von klxm_hub (ohne Netz)')]
final class SelftestCommand extends AbstractCommand
{
    public function __invoke(SymfonyStyle $io): int
    {
        $r = SelfTest::run();
        $io->writeln(sprintf('klxm_hub: %d in Ordnung%s', $r['ok'], $r['fails'] ? ', ' . \count($r['fails']) . ' Fehler' : ''));
        foreach ($r['fails'] as $f) {
            $io->writeln('  ✗ ' . $f);
        }
        if ($r['fails']) {
            $io->error('Selbsttest fehlgeschlagen.');

            return Command::FAILURE;
        }
        $io->success('Alles in Ordnung.');

        return Command::SUCCESS;
    }
}
