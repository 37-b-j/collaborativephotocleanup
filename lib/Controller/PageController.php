<?php

declare(strict_types=1);

namespace OCA\CollaborativePhotoCleanup\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

class PageController extends Controller {
    
    public function __construct(IRequest $request) {
        parent::__construct("collaborativephotocleanup", $request);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index(): TemplateResponse {
        // PWA meta tags
        \OCP\Util::addHeader("meta", ["name" => "apple-mobile-web-app-capable", "content" => "yes"]);
        \OCP\Util::addHeader("meta", ["name" => "apple-mobile-web-app-status-bar-style", "content" => "default"]);
        \OCP\Util::addHeader("meta", ["name" => "apple-mobile-web-app-title", "content" => "PhotoCleanup"]);
        \OCP\Util::addHeader("meta", ["name" => "mobile-web-app-capable", "content" => "yes"]);
        \OCP\Util::addHeader("meta", ["name" => "theme-color", "content" => "#1976d2"]);
        \OCP\Util::addHeader("link", ["rel" => "manifest", "href" => \OC::$WEBROOT . "/apps/collaborativephotocleanup/manifest.json"]);
        \OCP\Util::addHeader("link", ["rel" => "icon", "type" => "image/png", "sizes" => "192x192", "href" => \OC::$WEBROOT . "/apps/collaborativephotocleanup/img/icon-192.png"]);
        \OCP\Util::addHeader("link", ["rel" => "apple-touch-icon", "href" => \OC::$WEBROOT . "/apps/collaborativephotocleanup/img/icon-192.png"]);
        
        return new TemplateResponse("collaborativephotocleanup", "main");
    }
}
