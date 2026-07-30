<?php

declare(strict_types=1);

namespace OCA\CollaborativePhotoCleanup\AppInfo;

use OCA\CollaborativePhotoCleanup\Cron\PhashComputeJob;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\BackgroundJob\IJobList;

class Application extends App implements IBootstrap {
    public const APP_ID = 'collaborativephotocleanup';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void {
        // NC 34: registerBackgroundJob() does not exist on IRegistrationContext.
        // Background jobs are registered via IJobList in boot().
    }

    public function boot(IBootContext $context): void {
        try {
            $jobList = $context->getAppContainer()->get(IJobList::class);
            if (!$jobList->has(PhashComputeJob::class, null)) {
                $jobList->add(PhashComputeJob::class);
            }
        } catch (\Throwable $e) {
            // Don't break Nextcloud if job registration fails
        }
    }
}
