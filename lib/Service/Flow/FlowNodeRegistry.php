<?php

/**
 * The catalogue of node types available to flows on this instance.
 *
 * Populated by dispatching {@see RegisterFlowNodesEvent}, the same way core's
 * workflow engine populates its operator list. The palette a flow author sees
 * is this catalogue; the step a run executes is resolved out of it by `type`.
 *
 * This is what makes "apps present nodes through OpenRegister" true rather than
 * aspirational: hermiq contributes an agent step, openconnector contributes a
 * synchronisation step, and neither one needs an engine change or a release of
 * OpenRegister to do it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCP\EventDispatcher\IEventDispatcher;
use OCP\WorkflowEngine\IManager;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

/**
 * Holds the registered node types and resolves a step's type to one.
 */
class FlowNodeRegistry
{

    /**
     * Registered nodes, keyed by their id.
     *
     * @var array<string, IFlowNode>
     */
    private array $nodes = [];

    /**
     * Whether contribution has already been collected this request.
     *
     * @var boolean
     */
    private bool $loaded = false;

    /**
     * Constructor.
     *
     * @param IEventDispatcher $dispatcher Dispatches the contribution event.
     * @param LoggerInterface  $logger     The logger.
     */
    public function __construct(
        private readonly IEventDispatcher $dispatcher,
        private readonly LoggerInterface $logger
    ) {

    }//end __construct()

    /**
     * Add a node type to the catalogue.
     *
     * A duplicate id is REFUSED rather than allowed to overwrite. Two apps
     * claiming one type would otherwise resolve by registration order, so which
     * app's code ran would depend on app load order — a class of bug that only
     * shows up on someone else's instance.
     *
     * @param IFlowNode $node The node type.
     *
     * @return void
     *
     * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
     */
    public function register(IFlowNode $node): void
    {
        $id = $node->getId();
        if ($id === '') {
            $this->logger->warning(
                message: '[FlowNodeRegistry] Refusing a node type with an empty id',
                context: ['file' => __FILE__, 'line' => __LINE__, 'class' => get_class($node)]
            );
            return;
        }

        if (isset($this->nodes[$id]) === true) {
            $this->logger->warning(
                message: '[FlowNodeRegistry] Refusing a duplicate flow node type',
                context: [
                    'file'     => __FILE__,
                    'line'     => __LINE__,
                    'type'     => $id,
                    'existing' => get_class($this->nodes[$id]),
                    'rejected' => get_class($node),
                ]
            );
            return;
        }

        $this->nodes[$id] = $node;

    }//end register()

    /**
     * Every registered node type, optionally narrowed to one scope.
     *
     * @param int|null $scope A Nextcloud `IManager::SCOPE_*` constant, or null for all.
     *
     * @return array<string, IFlowNode> Nodes keyed by type id.
     *
     * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
     */
    public function all(?int $scope=null): array
    {
        $this->load();
        if ($scope === null) {
            return $this->nodes;
        }

        return array_filter(
            $this->nodes,
            static function (IFlowNode $node) use ($scope): bool {
                return $node->isAvailableForScope($scope);
            }
        );

    }//end all()

    /**
     * The palette an editor renders, as plain data.
     *
     * Each entry additionally carries `configKeys` when the node declares its
     * config vocabulary ({@see IFlowNodeConfigKeys}), which makes this endpoint
     * the fleet's single machine-readable source of truth for what a step may
     * be configured with — for the editor, and for any repository's flow lint.
     *
     * @param int $scope The scope to build the palette for.
     *
     * @return array<int, array<string, mixed>> One entry per available node.
     *
     * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
     */
    public function palette(int $scope=IManager::SCOPE_ADMIN): array
    {
        $palette = [];
        foreach ($this->all(scope: $scope) as $id => $node) {
            try {
                $entry = [
                    'id'          => $id,
                    'displayName' => $node->getDisplayName(),
                    'description' => $node->getDescription(),
                    'icon'        => $node->getIcon(),
                ];

                // The node's config vocabulary, when it declares one. This is
                // what stops a second, hand-maintained table of accepted keys
                // from existing somewhere else and drifting: hydra's
                // `scripts/test-flow-definitions.sh` keeps exactly such a table
                // today, and a table maintained in another repository is only
                // ever correct until the next node ships a key.
                //
                // ABSENT rather than empty when the node declares nothing, so a
                // consumer can tell "reads no config" (`[]`, which
                // openregister.switch really means) from "did not say", which
                // is every node predating IFlowNodeConfigKeys and must not be
                // read as a licence to reject its keys.
                if (($node instanceof IFlowNodeConfigKeys) === true) {
                    $entry['configKeys'] = array_values($node->configKeys());
                }

                $palette[] = $entry;
            } catch (\Throwable $e) {
                // One node whose metadata throws (a missing icon, a broken
                // translation) must not blank the whole palette — the author
                // would lose every node, not just the bad one.
                $this->logger->warning(
                    message: '[FlowNodeRegistry] Skipping a node whose palette metadata failed: '.$e->getMessage(),
                    context: ['file' => __FILE__, 'line' => __LINE__, 'type' => $id]
                );
            }//end try
        }//end foreach

        return $palette;

    }//end palette()

    /**
     * Resolve a step's `type` to its node.
     *
     * An unknown type throws rather than being skipped. A silently skipped step
     * produces a run that reports success while never having done the work —
     * the failure mode this codebase keeps paying for.
     *
     * @param string $type The step type.
     *
     * @return IFlowNode The node.
     *
     * @throws UnexpectedValueException When no app provides that type.
     *
     * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
     */
    public function get(string $type): IFlowNode
    {
        $this->load();
        if (isset($this->nodes[$type]) === false) {
            throw new UnexpectedValueException(
                sprintf('No app provides the flow node type "%s". Is the app that owns it installed and enabled?', $type)
            );
        }

        return $this->nodes[$type];

    }//end get()

    /**
     * Collect contributions once per request.
     *
     * @return void
     *
     * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
     */
    private function load(): void
    {
        if ($this->loaded === true) {
            return;
        }

        // Set BEFORE dispatching: a listener that resolves a service which
        // itself touches the registry would otherwise re-enter and dispatch
        // again, registering every node twice and tripping the duplicate guard.
        $this->loaded = true;
        $this->dispatcher->dispatchTyped(new RegisterFlowNodesEvent(registry: $this));

    }//end load()
}//end class
