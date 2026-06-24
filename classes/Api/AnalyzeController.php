<?php

declare(strict_types=1);

namespace Grav\Plugin\SmartInterlinker\Api;

use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Grav 2.0 Admin Next API endpoint for Smart Interlinker.
 *
 * The Admin Next panel (admin-next/panels/smart-interlinker.js) POSTs the live
 * editor draft here; this controller delegates to the plugin's analyze() method
 * so the indexing/phrase-matching engine is shared with the classic-admin path.
 *
 * Registered by SmartInterlinkerPlugin::onApiRegisterRoutes() as
 *   POST /api/v1/smart-interlinker/analyze
 *
 * This class is only ever autoloaded when the API plugin dispatches to it, so it
 * imposes no requirement on classic-admin / Grav 1.7 installs where the API
 * plugin (and therefore AbstractApiController) is absent.
 */
class AnalyzeController extends AbstractApiController
{
    public function analyze(ServerRequestInterface $request): ResponseInterface
    {
        // Page editors hold this permission; the endpoint only runs read-only
        // phrase matching against already-indexed page titles.
        $this->requirePermission($request, 'api.pages.read');

        $body = $this->getRequestBody($request);
        $content = (string)($body['content'] ?? '');
        $route = (string)($body['route'] ?? '');

        $plugin = $this->grav['smart-interlinker'] ?? null;
        if (!$plugin || !method_exists($plugin, 'analyze')) {
            return ApiResponse::create([
                'matches' => [],
                'index_size' => 0,
                'config' => (object)[],
            ]);
        }

        return ApiResponse::create($plugin->analyze($content, $route));
    }
}
