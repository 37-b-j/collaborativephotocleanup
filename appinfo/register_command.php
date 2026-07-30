<?php
declare(strict_types=1);
$vendorAutoload = __DIR__ . "/../vendor/autoload.php";
if (file_exists($vendorAutoload)) {
    require_once $vendorAutoload;
}
use OCA\CollaborativePhotoCleanup\Command\PhashCommand;
use OCA\CollaborativePhotoCleanup\Command\PhashBatchCommand;
use OCA\CollaborativePhotoCleanup\Command\ClusterCommand;
$application->add(new PhashCommand(
    \OCP\Server::get(\OCA\CollaborativePhotoCleanup\Service\PhashCalculator::class),
    \OCP\Server::get(\OCP\Files\IRootFolder::class)
));
$application->add(new PhashBatchCommand(
    \OCP\Server::get(\OCA\CollaborativePhotoCleanup\Service\PhashCalculator::class),
    \OCP\Server::get(\OCP\Files\IRootFolder::class)
));
$application->add(new ClusterCommand(
    \OCP\Server::get(\OCA\CollaborativePhotoCleanup\Service\ClusterService::class),
    \OCP\Server::get(\OCP\Files\IRootFolder::class),
    \OCP\Server::get(\OCP\IUserSession::class)
));
