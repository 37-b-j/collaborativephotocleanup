<?php

declare(strict_types=1);

namespace OCA\CollaborativePhotoCleanup\Command;

use OCA\CollaborativePhotoCleanup\Service\PhashCalculator;
use OCP\Files\IRootFolder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class PhashCommand extends Command {

    private PhashCalculator $phashCalculator;
    private IRootFolder $rootFolder;

    public function __construct(
        PhashCalculator $phashCalculator,
        IRootFolder $rootFolder
    ) {
        parent::__construct();
        $this->phashCalculator = $phashCalculator;
        $this->rootFolder = $rootFolder;
    }

    protected function configure(): void {
        $this->setName('photocleanup:phash')
            ->setDescription('Berechne den perceptual hash (pHash) für eine Datei')
            ->addOption(
                'fileId',
                null,
                InputOption::VALUE_REQUIRED,
                'Datei-ID in Nextcloud'
            )
            ->addOption(
                'path',
                null,
                InputOption::VALUE_REQUIRED,
                'Dateipfad relativ zum Nextcloud-Root'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $io = new SymfonyStyle($input, $output);

        $fileId = $input->getOption('fileId');
        $path = $input->getOption('path');

        if (!$fileId && !$path) {
            $io->error('Bitte --fileId oder --path angeben.');
            return Command::FAILURE;
        }

        if ($fileId && $path) {
            $io->error('Bitte nur --fileId ODER --path verwenden, nicht beide.');
            return Command::FAILURE;
        }

        try {
            if ($fileId) {
                $result = $this->phashCalculator->calculateForFile((int)$fileId);
                $identifier = "Datei-ID $fileId";
            } else {
                $result = $this->phashCalculator->calculateForPath((string)$path);
                $identifier = "Pfad '$path'";
            }

            if ($result === null) {
                $io->warning("Kein pHash für $identifier berechnet (nicht unterstütztes Format oder Fehler).");
                return Command::FAILURE;
            }

            $io->success("pHash für $identifier:");
            $io->table(
                ['Eigenschaft', 'Wert'],
                [
                    ['Integer', (string)$result],
                    ['Hex', dechex($result)],
                    ['Binär', str_pad(decbin($result), 64, '0', STR_PAD_LEFT)],
                ]
            );

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Fehler: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
