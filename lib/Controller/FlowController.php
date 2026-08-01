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

use OCA\OpenRegister\Db\FlowStateMapper;
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
     * @param FlowStateMapper     $flowState    Reads state a flow keeps between runs.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly EventCatalogService $eventCatalog,
        private readonly FlowNodeRegistry $nodes,
        private readonly FlowStateMapper $flowState,
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

    /**
     * What a flow is currently holding between its runs.
     *
     * The state itself has existed since #2219 and nodes can write it since
     * #2221, but nothing could READ it from outside the engine — so a slot
     * table was live data nobody could render. This is the surface a dashboard
     * needs to answer "which slot is running what, and since when".
     *
     * A flow with no state yet returns an empty map rather than 404: "nothing
     * claimed" is a perfectly good answer, and a widget should not have to
     * special-case a flow's first tick.
     *
     * `?list=<key>` additionally serves that key as `results` — a LIST, one
     * entry per slot, each carrying its own `slot` number. That exists because
     * the state's natural shape and a table's required shape genuinely differ:
     * a slot table is keyed by slot number so a claim is one lookup, while a
     * manifest `object-table` requires an array at its `responsePath`. Without
     * this a dashboard has to reshape the payload in code, which is the thing
     * manifest-driven widgets exist to avoid.
     *
     * It is opt-in and names its key explicitly. Nothing is inferred from the
     * shape of the data — a flow's state is arbitrary and "looks like a slot
     * table" is not a contract.
     *
     * @param string $flowId The flow's uuid.
     *
     * @return JSONResponse `{ flowId, state, updated }`, plus `{ key, results, total }` with `?list=`.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function state(string $flowId): JSONResponse
    {
        $stored  = $this->flowState->findByFlow(flowId: $flowId);
        $state   = [];
        $updated = null;

        if ($stored !== null) {
            $payload = $stored->jsonSerialize();
            $state   = (array) ($payload['state'] ?? []);
            $updated = ($payload['updated'] ?? null);
        }

        $body = [
            'flowId'  => $flowId,
            'state'   => $state,
            'updated' => $updated,
        ];

        $list = trim((string) $this->request->getParam('list', ''));
        if ($list !== '') {
            $results = $this->asList(value: ($state[$list] ?? []));

            $body['key']     = $list;
            $body['results'] = $results;
            $body['total']   = count($results);
        }

        return new JSONResponse($body);

    }//end state()

    /**
     * One state key as a list, with each entry carrying the key it was under.
     *
     * A FREE slot is emitted too, as `{slot: n, holder: null, …}` — not skipped.
     * "Slot 3 of 10 is empty" is what an operator needs to see, and a table that
     * silently omits free slots reads as a smaller pool rather than an idle one.
     * That is also why the node materialises the whole table rather than storing
     * only what is taken.
     *
     * @param mixed $value The state value.
     *
     * @return array<int, array> The list.
     */
    private function asList(mixed $value): array
    {
        if (is_array($value) === false) {
            return [];
        }

        $results = [];
        foreach ($value as $key => $entry) {
            $row = ['slot' => $key];
            if (is_array($entry) === true) {
                $row = array_merge($row, $entry);
            } else if ($entry !== null) {
                $row['holder'] = $entry;
            }

            $results[] = $row;
        }

        return $results;

    }//end asList()
}//end class
