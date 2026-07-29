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
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\WorkflowEngine\IManager;

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
     * @param FlowNodeRegistry    $nodes        The registered flow-step types.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly EventCatalogService $eventCatalog,
        private readonly FlowNodeRegistry $nodes,
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

    /**
     * Return the flow NODE catalog — the step types a flow may use.
     *
     * The trigger catalog above answers "what can start a flow"; this answers
     * "what can a flow do", which had no HTTP surface at all. A flow is authored
     * as an object whose `edges[].type` names a registered node, so without this
     * a builder has to hardcode the list — and that list goes stale silently the
     * moment an app contributes a leaf, because an unknown type only surfaces
     * when the flow RUNS (FlowNodeRegistry::get() throws). Apps really do
     * contribute: openconnector ships source-call and synchronization-run,
     * hermiq ships agent-step.
     *
     * Scope-filtered, so a non-administrator is never offered a step they could
     * not run. Every node implements `isAvailableForScope()` already.
     *
     * @return JSONResponse `{ results: [{id,displayName,description,icon}], total }`.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/visual-flow-builder/specs/flow-builder/spec.md
     */
    public function nodeCatalog(): JSONResponse
    {
        $scope = IManager::SCOPE_ADMIN;
        if ($this->request->getParam('scope') === 'user') {
            $scope = IManager::SCOPE_USER;
        }

        $results = $this->nodes->palette(scope: $scope);

        return new JSONResponse(
            [
                'results' => $results,
                'total'   => count($results),
            ]
        );
    }//end nodeCatalog()
}//end class
