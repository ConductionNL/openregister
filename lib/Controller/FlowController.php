<?php

/**
 * FlowController — read surface for the visual flow builder.
 *
 * Exposes the declarative-flow event catalog (the triggers a flow may subscribe
 * to) so the visual builder can populate its trigger palette from the backend
 * rather than a hard-coded list. The catalog is authoritative: every id it
 * returns is a real, dispatched event routed to the flow runner.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/visual-flow-builder/specs/flow-builder/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\Flow\EventCatalogService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Read endpoints backing the visual flow builder.
 */
class FlowController extends Controller
{
    /**
     * Constructor.
     *
     * @param string              $appName      App id.
     * @param IRequest            $request      HTTP request.
     * @param EventCatalogService $eventCatalog The flow trigger catalog.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly EventCatalogService $eventCatalog,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Return the declarative-flow trigger catalog.
     *
     * @return JSONResponse `{ results: [{id,label,group,legacy?}], total }`.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/visual-flow-builder/specs/flow-builder/spec.md
     */
    public function eventCatalog(): JSONResponse
    {
        $results = $this->eventCatalog->getCatalog();

        return new JSONResponse(
            [
                'results' => $results,
                'total'   => count($results),
            ]
        );
    }//end eventCatalog()
}//end class
