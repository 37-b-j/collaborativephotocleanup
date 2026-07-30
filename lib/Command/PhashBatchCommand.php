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

class PhashBatchCommand extends Command {

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
        $this->setName('photocleanup:phash-batch')
            ->setDescription('Berechne perceptual hashes (pHash) für mehrere Dateien')
            ->addOption(
                'folder',
                null,
                InputOption::VALUE_REQUIRED,
                'Ordnerpfad relativ zum Nextcloud-Root'
            )
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_OPTIONAL,
                'Maximale Anzahl Dateien',
                '1000'
            )
            ->addOption(
                'fileIds',
                null,
                InputOption::VALUE_OPTIONAL,
                'Komma-separierte Liste von Datei-IDs'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $io = new SymfonyStyle($input, $output);

        $folder = $input->getOption('folder');
        $limit = (int)$input->getOption('limit');
        $fileIds = $input->getOption('fileIds');

        if (!$folder && !$fileIds) {
            $io->error('Bitte --folder oder --fileIds angeben.');
            return Command::FAILURE;
        }

        if ($folder && $fileIds) {
            $io->error('Bitte nur --folder ODER --fileIds verwenden, nicht beide.');
            return Command::FAILURE;
        }

        try {
            $fileIdList = [];

            if ($fileIds) {
                $fileIdList = array_map('intval', explode(',', $fileIds));
                $io->info(sprintf('Verarbeite %d Datei-IDs...', count($fileIdList)));
            } else {
                $io->info(sprintf("Scanne Ordner '%s' nach Bildern (Limit: %d)...", $folder, $limit));

                if (!$this->rootFolder->nodeExists($folder)) {
                    $io->error("Ordner '$folder' existiert nicht.");
                    return Command::FAILURE;
                }

                $folderNode = $this->rootFolder->get($folder);
                if (!$folderNode instanceof \OCP\Files\Folder) {
                    $io->error("'$folder' ist kein Ordner.");
                    return Command::FAILURE;
                }

                $this->collectImageFiles($folderNode, $fileIdList, $limit);
                $io->info(sprintf('%d Bilddateien gefunden.', count($fileIdList)));
            }

            if (empty($fileIdList)) {
                $io->warning('Keine zu verarbeitenden Dateien gefunden.');
                return Command::SUCCESS;
            }

            $io->section('Berechne pHash-Werte...');
            $io->progressStart(count($fileIdList));

            $results = $this->phashCalculator->batchCalculate($fileIdList);

            $io->progressFinish();

            $successCount = 0;
            $rows = [];
            foreach ($results as $fileId => $hash) {
                if ($hash !== null) {
                    $successCount++;
                    $rows[] = [$fileId, (string)$hash, dechex($hash)];
                } else {
                    $rows[] = [$fileId, 'FEHLER', '-'];
                }
            }

            $io->newLine();
            $io->table(
                ['Datei-ID', 'pHash (Int)', 'pHash (Hex)'],
                $rows
            );

            $io->success(sprintf(
                'Batch-Verarbeitung abgeschlossen: %d/%d erfolgreich (%.1f%%)',
                $successCount,
                count($fileIdList),
                ($successCount / count($fileIdList)) * 100
            ));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Fehler: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function collectImageFiles(\OCP\Files\Folder $folder, array &$fileIds, int $limit): void {
        $supportedMimes = ['image/jpeg', 'image/png', 'image/webp'];

        $directoryContent = $folder->getDirectoryListing();
        foreach ($directoryContent as $node) {
            if (count($fileIds) >= $limit) {
                return;
            }

            if ($node instanceof \OCP\Files\Folder) {
                $this->collectImageFiles($node, $fileIds, $limit);
            } elseif ($node instanceof \OCP\Files\File) {
                if (in_array($node->getMimeType(), $supportedMimes, true)) {
                    $fileIds[] = $node->getId();
                }
            }
        }
    }
}
