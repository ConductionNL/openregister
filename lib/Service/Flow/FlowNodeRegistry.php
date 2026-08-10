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
     * Node ids that were corrected, mapped old => new.
     *
     * A node id is a reference the SYSTEM writes into a flow definition, unlike
     * a Twig function name which a person types into a template — so unlike
     * those, an id can be corrected and the stored data migrated. A migration
     * rewrites existing rows; this alias covers the tail the migration cannot
     * reach: a flow exported before the rename and imported after it.
     *
     * Resolving through here is LOGGED, so the size of that tail is observable
     * rather than assumed to be zero. The alias is removed one release after the
     * rename.
     *
     * @var array<string, string>
     */
    private const RENAMED = [
        // Renamed because it never looped: it splits items into fixed-size
        // batches. Sitting next to the real `openregister.iterate`, the old name
        // was a trap that re-armed for every new reader.
        'openregister.loop' => 'openregister.batch',
    ];

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
                    // ALWAYS present, and always one of start/step/stop. An
                    // editor that had to infer this fell back to matching the
                    // id against a naming convention, which mis-labels every
                    // node another app contributes under a name that does not
                    // fit the pattern. The engine knows; it says so.
                    'role'        => $this->roleFor(node: $node),
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

                // The node's own form, when it describes one. Absent — not
                // empty — when it does not, for the same reason `configKeys`
                // is: the editor must be able to tell "this node described no
                // form, fall back to raw JSON" from "this node has no fields",
                // and an empty array would say the second while meaning the
                // first.
                if (($node instanceof IFlowNodeConfigForm) === true) {
                    $entry['configForm'] = array_values($node->configForm());
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
     * The links one run-log entry earns, from the node that wrote it.
     *
     * Asked of the node NOW rather than read from the entry: an href frozen
     * into a log at write time rots when the target moves, and these records
     * live for months.
     *
     * An entry whose node is unknown — a type an app stopped contributing, or
     * a log older than the node's removal — yields no links rather than an
     * error. A run log is history, and history routinely names things that no
     * longer exist; refusing to render it would lose the rest of the entry too.
     *
     * A node whose `logActions()` throws is skipped for the same reason
     * `palette()` skips one whose metadata throws: one broken provider must not
     * take out the log an operator is trying to read.
     *
     * @param array<string, mixed> $entry One entry from a run's log.
     *
     * @return array<int, array<string, mixed>> The links.
     *
     * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-type-declares-its-own-form-and-its-own-run-log-actions
     */
    public function logActions(array $entry): array
    {
        $type = trim((string) ($entry['type'] ?? ''));
        if ($type === '') {
            return [];
        }

        $node = ($this->all()[$type] ?? null);
        if ($node === null || ($node instanceof IFlowNodeLogActions) === false) {
            return [];
        }

        try {
            return array_values($node->logActions(entry: $entry));
        } catch (\Throwable $e) {
            $this->logger->warning(
                message: '[FlowNodeRegistry] A node\'s log actions failed: '.$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__, 'type' => $type]
            );

            return [];
        }

    }//end logActions()

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

        if (isset($this->nodes[$type]) === false && isset(self::RENAMED[$type]) === true) {
            $this->logger->info(
                message: sprintf(
                    '[FlowNodeRegistry] Flow node "%s" was renamed to "%s"; resolving via the '
                    .'compatibility alias. A flow definition still references the old id.',
                    $type,
                    self::RENAMED[$type]
                ),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );

            $type = self::RENAMED[$type];
        }

        if (isset($this->nodes[$type]) === false) {
            throw new UnexpectedValueException(
                sprintf('No app provides the flow node type "%s". Is the app that owns it installed and enabled?', $type)
            );
        }

        return $this->nodes[$type];

    }//end get()

    /**
     * Whether a node TYPE ends a path deliberately.
     *
     * Resolved through the registry rather than against a hardcoded list, so a
     * stop step contributed by another app — openconnector, hermiq, or one
     * not written yet — needs no OpenRegister change to be recognised. A
     * hardcoded list would silently report every contributed stop node as a
     * dead end, and the warning would train authors to ignore it.
     *
     * An unknown type does NOT stop. It is already reported by its own
     * preflight finding, and guessing "probably stops" here would suppress
     * the dead-end warning for exactly the documents most likely to have one.
     *
     * @param string $type The node type id.
     *
     * @return bool True when the type is registered and marks itself a stop.
     *
     * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-declares-whether-it-starts-or-stops-a-path
     */
    public function isStop(string $type): bool
    {
        if (trim($type) === '') {
            return false;
        }

        try {
            return ($this->get(type: $type) instanceof IFlowStopNode);
        } catch (UnexpectedValueException) {
            return false;
        }

    }//end isStop()

    /**
     * Whether a run may BEGIN at a node TYPE.
     *
     * The mirror of {@see isStop()}, and resolved the same way and for the same
     * reason: a start node contributed by another app is a start node, and no
     * consumer should have to recognise it by the shape of its id.
     *
     * @param string $type The node type id.
     *
     * @return bool True when the type is registered and marks itself a start.
     *
     * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-declares-whether-it-starts-or-stops-a-path
     */
    public function isStart(string $type): bool
    {
        if (trim($type) === '') {
            return false;
        }

        try {
            return ($this->get(type: $type) instanceof IFlowStartNode);
        } catch (UnexpectedValueException) {
            return false;
        }

    }//end isStart()

    /**
     * A node type's ROLE in a flow: `start`, `step` or `stop`.
     *
     * One word per concept, decided HERE and shipped to every consumer, so the
     * palette, the canvas and the connectivity check cannot disagree about what
     * a node is. Consumers used to infer this from the id
     * (`id.includes('.trigger-')`, `id.endsWith('.stop')`) — a convention that
     * mis-labels every node another app contributes under a different name.
     *
     * A type that marks itself BOTH is reported as a start: a node a run can
     * begin at is a start whatever else it does, and the canvas has to draw it
     * at the top of the flow.
     *
     * @param string $type The node type id.
     *
     * @return string `'start'`, `'stop'` or `'step'`.
     *
     * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-declares-whether-it-starts-or-stops-a-path
     */
    public function roleOf(string $type): string
    {
        if (trim($type) === '') {
            return 'step';
        }

        try {
            return $this->roleFor(node: $this->get(type: $type));
        } catch (UnexpectedValueException) {
            // An unregistered type is a STEP, not a guess at something else.
            // Its own preflight finding already reports that it is unknown.
            return 'step';
        }

    }//end roleOf()

    /**
     * The role of a node INSTANCE the caller already holds.
     *
     * The one place the two marker interfaces are read, so `roleOf()`,
     * `palette()` and anything added later cannot answer differently.
     *
     * @param IFlowNode $node The node.
     *
     * @return string `'start'`, `'stop'` or `'step'`.
     *
     * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-declares-whether-it-starts-or-stops-a-path
     */
    private function roleFor(IFlowNode $node): string
    {
        if (($node instanceof IFlowStartNode) === true) {
            return 'start';
        }

        if (($node instanceof IFlowStopNode) === true) {
            return 'stop';
        }

        return 'step';

    }//end roleFor()

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
