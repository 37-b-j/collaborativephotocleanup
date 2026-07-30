<?php
declare(strict_types=1);
namespace OCA\CollaborativePhotoCleanup\Command;
use OCA\CollaborativePhotoCleanup\Service\ClusterService;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ClusterCommand extends Command {
    private ClusterService $clusterService;
    private IRootFolder $rootFolder;
    private IUserSession $userSession;

    public function __construct(ClusterService $clusterService, IRootFolder $rootFolder, IUserSession $userSession) {
        parent::__construct();
        $this->clusterService = $clusterService;
        $this->rootFolder = $rootFolder;
        $this->userSession = $userSession;
    }

    protected function configure(): void {
        $this->setName("photocleanup:cluster")
            ->setDescription("Cluster ahnliche Bilder basierend auf pHash-Werten")
            ->addArgument("folder", InputArgument::OPTIONAL, "Ordnerpfad", "/Photos")
            ->addOption("recursive", "r", InputOption::VALUE_NONE, "Rekursiv durchsuchen")
            ->addOption("threshold", "t", InputOption::VALUE_REQUIRED, "Hamming-Distanz Threshold", 25);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $start = microtime(true);
        $folderPath = $input->getArgument("folder");
        $threshold = (int) $input->getOption("threshold");
        $output->writeln("PhotoCleanup Clustering gestartet");
        $output->writeln("  Ordner: " . $folderPath);
        $output->writeln("  Threshold: " . $threshold);
        try {
            $user = $this->userSession->getUser();
            if (!$user) {
                $output->writeln("Fehler: Kein Benutzer angemeldet.");
                return 1;
            }
            $userFolder = $this->rootFolder->getUserFolder($user->getUID());
            $folder = $userFolder;
            if ($folderPath && $folderPath !== "/") {
                $path = ltrim($folderPath, "/");
                if ($path !== "") {
                    try {
                        $node = $userFolder->get($path);
                        if ($node instanceof \OCP\Files\Folder) {
                            $folder = $node;
                        }
                    } catch (\OCP\Files\NotFoundException $e) {
                        $output->writeln("Fehler: Ordner nicht gefunden: " . $folderPath);
                        return 1;
                    }
                }
            }
            $clusters = $this->clusterService->findClusters($folder, $threshold);
            if (empty($clusters)) {
                $output->writeln("Keine Cluster gefunden");
                return 0;
            }
            $output->writeln("Gefunden: " . count($clusters) . " Cluster");
            $total = 0;
            foreach ($clusters as $c) {
                $total += $c["count"] ?? 0;
            }
            $output->writeln("  Geclusterte Dateien: " . $total);
            $end = microtime(true);
            $output->writeln("Clustering abgeschlossen in " . round($end - $start, 2) . " Sekunden");
            return 0;
        } catch (\Throwable $e) {
            $output->writeln("Fehler: " . $e->getMessage());
            return 1;
        }
    }
}
