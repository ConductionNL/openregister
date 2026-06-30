<?php

/**
 * Icon controller.
 *
 * Serves curated Material Design Icon glyphs as standalone SVG images so they
 * can be referenced by a real, same-origin URL — used by the unified search
 * provider to render a schema's icon (Nextcloud search renders a thumbnail only
 * from a URL, not from a data: URI or a bare icon-class name).
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\MdiIconRenderer;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\IRequest;

/**
 * Renders curated MDI glyphs as SVG images.
 */
class IconController extends Controller
{
    /**
     * Constructor for the IconController.
     *
     * @param string   $appName The name of the app
     * @param IRequest $request The HTTP request object
     *
     * @return void
     */
    public function __construct(string $appName, IRequest $request)
    {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()

    /**
     * Serve a curated Material Design Icon as an SVG image.
     *
     * Public, cacheable, and read-only: it returns nothing but static glyph
     * geometry from a curated allow-list, so it is safe without authentication.
     * Unknown icon names return 404 so the caller falls back to its own icon.
     *
     * @param string $name The MDI icon reference (e.g. "Dog", "mdi-dog").
     *
     * @return DataDisplayResponse The SVG image, or a 404 for an unknown icon.
     *
     * @spec openspec/changes/unified-search-index/specs/unified-search-provider/spec.md
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function mdi(string $name): DataDisplayResponse
    {
        $svg = MdiIconRenderer::svg(icon: $name);
        if ($svg === null) {
            return new DataDisplayResponse(
                data: '',
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        $response = new DataDisplayResponse(
            data: $svg,
            statusCode: Http::STATUS_OK,
            headers: ['Content-Type' => 'image/svg+xml']
        );
        // Glyph geometry is immutable for a given name — cache hard.
        $response->cacheFor(86400, false, true);

        return $response;

    }//end mdi()
}//end class
