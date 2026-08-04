<?php

/**
 * Checks a flow document's step types against the live node registry.
 *
 * `FlowNodeRegistry::get()` already refuses an unknown type, and refuses it for
 * the right reason — a silently skipped step produces a run that reports success
 * while never having done the work. The defect this class closes is *when* it
 * refuses: at step-dispatch time, mid-run, after earlier steps have already
 * written to the outside world. Labels have moved, commits have landed, issues
 * have been filed, and none of it rolls back when step four turns out to name a
 * node this instance does not have.
 *
 * The measured case: `openregister.explode` shipped in or#2247 and was named by
 * a flow document while the running instance sat at or#2244. Nothing noticed
 * until someone checked by hand. The flow saved cleanly, ran, did real work, and
 * then died in the middle.
 *
 * So the check moves to save/import time, where refusing costs nothing.
 *
 * ## Why not simply refuse every unknown type
 *
 * Because a flow legitimately outlives the apps on one instance. A shared
 * configuration export carries flows naming `openconnector.source-call` and
 * `hermiq.agent-step`; importing it onto an instance that has not installed
 * openconnector yet must not fail — the flow is not wrong, the instance is
 * merely incomplete, and it will become correct the moment the app is enabled.
 * The fleet treats optional apps that way everywhere else (see the `IAppManager`
 * probes in `ExternalIntegrationRouter`, `TalkLinkService`, `AnalyticsProvider`).
 *
 * The distinction that makes a hard refusal safe is ownership. Node ids are
 * namespaced with the owning app by contract ({@see IFlowNode::getId()}), so the
 * owner of `openregister.explode` is `openregister`. That gives two genuinely
 * different situations:
 *
 * - The owning app **is enabled** and still does not provide the type. No
 *   install can fix this: the app is right here and does not have it. It is
 *   version drift or a typo, always a defect, never a legitimate absence. This
 *   is the or#2247 case, and it is REFUSED.
 * - The owning app **is not enabled**. Installing it is exactly the fix, and the
 *   document is not wrong. WARNED, loudly and structurally, and saved.
 *
 * ## The type resolving is only half the question
 *
 * A resolved type says the app provides the step. It says nothing about whether
 * the node can READ the config the edge hands it, and that second half produced
 * its own set of measured failures (hydra#489, four inert flows). The check has
 * two parts, and it needs both:
 *
 * - `validateConfig()` — the node's own opinion. Catches a REQUIRED key that is
 *   missing or malformed. It cannot catch anything else, because a node only
 *   examines the keys it looks for; a key it does not look for is invisible to
 *   it by construction. Where the required set is empty the method is a no-op no
 *   matter how carefully it is written — `StopNode::validateConfig()` is empty
 *   for exactly that reason, and it is not wrong to be.
 * - The node's declared vocabulary ({@see IFlowNodeConfigKeys}) — catches a key
 *   the node will silently ignore. `StopNode` accepting `status`/`reason` while
 *   reading `error`/`message`, `SubFlowNode` accepting `input`/`output` while
 *   implementing neither: both satisfied every required key and both were inert.
 *
 * Keys beginning with `$` are authoring annotations (`$why`, `$comment`), used
 * throughout the fleet's flow documents and read by nothing. They are exempt by
 * contract — a check that refused them would refuse nearly every real flow,
 * which is worse than no check.
 *
 * A node that does not declare a vocabulary is not vocabulary-checked. That is
 * today's behaviour preserved for out-of-tree nodes, not an oversight; see
 * {@see IFlowNodeConfigKeys} for why the contract is opt-in.
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
 * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;
use Throwable;
use UnexpectedValueException;

/**
 * Resolves every step type in a flow document before the flow can run.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The complexity is a count of
 *   the distinct ways a flow document can be wrong, spread across ten short
 *   single-purpose methods (the longest branches four ways). Splitting it would
 *   move the branching, not remove it, and would put "what makes a step
 *   unrunnable" in two places — which is the drift this class exists to end.
 *
 * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
 */
class FlowNodePreflight
{

    /**
     * The owning app is enabled but does not provide the type.
     *
     * Unfixable by installing anything — a defect, and therefore blocking.
     */
    public const REASON_MISSING_FROM_OWNER = 'node-type-missing-from-enabled-app';

    /**
     * The type carries no `app.` namespace, so no app can ever own it.
     *
     * Blocking: there is no install that would make this resolve.
     */
    public const REASON_NOT_NAMESPACED = 'node-type-not-namespaced';

    /**
     * The owning app is not enabled on this instance.
     *
     * Installing it is the fix, so this warns rather than blocks.
     */
    public const REASON_OWNER_NOT_ENABLED = 'node-type-owner-app-not-enabled';

    /**
     * The type resolves, but the node refuses the config it was given.
     *
     * Blocking. A step whose config the node cannot read is not a step that
     * half-works — it is a step that returns its input untouched and reports
     * COMPLETED, which is the failure this whole class exists to remove.
     */
    public const REASON_CONFIG_REJECTED = 'node-config-rejected';

    /**
     * The config carries a key the node never reads.
     *
     * Blocking, and it is the half `validateConfig()` structurally cannot see.
     * That method answers "is anything REQUIRED missing"; a step written in
     * another node's dialect can satisfy every required key and still be inert,
     * because the keys carrying the actual intent are ones the node ignores.
     * `StopNode` requires nothing at all, so it accepted `status`/`reason`
     * (it reads `error`/`message`) and stopped runs with the generic "Flow
     * stopped" and `isError=false`. `SubFlowNode` requires only `flow`, so it
     * accepted `input`/`output` and passed the child nothing. Neither was
     * catchable until a node could name its whole vocabulary.
     */
    public const REASON_CONFIG_UNKNOWN_KEY = 'node-config-unknown-key';

    /**
     * `onError` was put in `config` instead of on the edge.
     *
     * `FlowEngine::policyFor()` reads `$step['onError']` — the EDGE, one level
     * up from `config`. A policy inside `config` is read by nothing.
     *
     * Blocking only when the buried policy differs from the engine default
     * (`stop`), because that is exactly when it changes what the run does: the
     * author asked for `continue` or `dead_letter` and silently got `stop`.
     * A buried `"stop"` is still wrong, but it is wrong in the direction the
     * engine was already going, so it warns instead of refusing a fleet's worth
     * of live flow documents over a no-op key. Same rule, same threshold, as
     * `hydra/scripts/test-flow-definitions.sh` — deliberately, so the two
     * guards cannot disagree about the same document.
     */
    public const REASON_CONFIG_ONERROR_MISPLACED = 'node-config-onerror-misplaced';

    /**
     * The key the engine reads from the EDGE and never from `config`.
     */
    private const EDGE_LEVEL_ONERROR = 'onError';

    /**
     * The `onError` policy the engine applies when an edge declares none.
     *
     * Mirrors `FlowEngine::ON_ERROR_STOP`, not imported from it: this class
     * must not gain a dependency on the engine to know one default string.
     */
    private const DEFAULT_ONERROR = 'stop';

    /**
     * Constructor.
     *
     * @param FlowNodeRegistry $registry   The live catalogue of node types.
     * @param IAppManager      $appManager Tells an absent app from a stale one.
     * @param LoggerInterface  $logger     The logger.
     */
    public function __construct(
        private readonly FlowNodeRegistry $registry,
        private readonly IAppManager $appManager,
        private readonly LoggerInterface $logger
    ) {

    }//end __construct()

    /**
     * Whether a piece of object data is shaped like a flow document.
     *
     * Deliberately structural rather than register/schema based: flows live in
     * hermiq's `agentflow` objects, in OpenRegister's own `flow` schema, and in
     * whatever a future app nominates. Keying off the shape means the check
     * follows the documents instead of a configured location that can drift.
     *
     * The signature is a graph: a list of nodes carrying ids, and a list of
     * edges carrying endpoints. Anything looser would start judging objects
     * that merely happen to own a `nodes` key.
     *
     * @param array $data The object's data.
     *
     * @return boolean True when the data describes a flow graph.
     *
     * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
     */
    public function looksLikeFlow(array $data): bool
    {
        $nodes = ($data['nodes'] ?? null);
        $edges = ($data['edges'] ?? null);
        if (is_array($nodes) === false || is_array($edges) === false) {
            return false;
        }

        if ($nodes === [] || $edges === []) {
            return false;
        }

        return ($this->nodesLookRight(nodes: $nodes) === true && $this->edgesLookRight(edges: $edges) === true);

    }//end looksLikeFlow()

    /**
     * Whether every entry in a `nodes` list is a record carrying an id.
     *
     * @param array $nodes The candidate node list.
     *
     * @return boolean True when they all are.
     *
     * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
     */
    private function nodesLookRight(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if (is_array($node) === false || isset($node['id']) === false) {
                return false;
            }
        }

        return true;

    }//end nodesLookRight()

    /**
     * Whether every entry in an `edges` list carries both endpoints.
     *
     * Either spelling is accepted — `from`/`to` is this engine's, `source`/
     * `target` is what most graph editors emit.
     *
     * @param array $edges The candidate edge list.
     *
     * @return boolean True when they all do.
     *
     * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
     */
    private function edgesLookRight(array $edges): bool
    {
        foreach ($edges as $edge) {
            if (is_array($edge) === false) {
                return false;
            }

            $hasFrom = (isset($edge['from']) === true || isset($edge['source']) === true);
            $hasTo   = (isset($edge['to']) === true || isset($edge['target']) === true);
            if ($hasFrom === false || $hasTo === false) {
                return false;
            }
        }

        return true;

    }//end edgesLookRight()

    /**
     * Resolve every step type the document names.
     *
     * Only `edges[].type` is inspected. The graph's *shape* is deliberately not
     * validated here even though {@see FlowDefinitionBuilder} could: a flow being
     * drawn is legitimately half-connected, and refusing to save a draft would
     * make the editor unusable. A step type, by contrast, is never bogus on
     * purpose — nobody drafts a node type they do not mean.
     *
     * An edge with no `type` is a pass-through and is skipped, matching
     * `RegistryStepDispatcher`, which returns items untouched for such an edge.
     *
     * A resolved type is checked TWICE: once that it exists, and once that the
     * node accepts the config the edge gives it. The second check is the one
     * that catches an edge written in another node's dialect — the type is
     * right, the step runs, and it does nothing.
     *
     * @param array $flow The flow document.
     *
     * @return array{blocking: array<int, array<string, string>>, warnings: array<int, array<string, string>>}
     *
     * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
     */
    public function inspect(array $flow): array
    {
        $blocking = [];
        $warnings = [];

        foreach (($flow['edges'] ?? []) as $index => $edge) {
            if (is_array($edge) === false) {
                continue;
            }

            $type = trim((string) ($edge['type'] ?? ''));
            if ($type === '') {
                continue;
            }

            $edgeId = (string) ($edge['id'] ?? $edge['name'] ?? $index);
            $node   = $this->resolve(type: $type);
            if ($node !== null) {
                // The type resolving is only half the question. A node reads the
                // config keys IT implements, and silently ignores the rest — so
                // an edge written in another node's dialect resolves fine, runs,
                // and returns its input untouched while reporting COMPLETED.
                //
                // Measured across the ten hydra flows: four are inert this way.
                // `hydra-analyze-verdicts` declares `routes[].when`/`routes[].to`
                // where RouterNode reads `rules[].output`, and `config.fields`
                // where SetFieldsNode reads `set`/`compute`. The flow cannot run
                // at all, and `scripts/test-flow-definitions.sh` passes on it —
                // that script checks graph STRUCTURE and is blind to dialect.
                //
                // Every node already implements `validateConfig()`; the contract
                // is on IFlowNode. It was simply never called at save time.
                // SetFieldsNode's own docblock says the check exists so a
                // malformed expression is "caught HERE, when the flow is saved,
                // rather than evaluating to null on every item at 03:00" — which
                // is exactly what it did NOT do, because nothing called it.
                $rejection = $this->configRejection(node: $node, edge: $edge);
                if ($rejection !== null) {
                    $blocking[] = [
                        'type'   => $type,
                        'app'    => $this->ownerOf(type: $type),
                        'edge'   => $edgeId,
                        'reason' => self::REASON_CONFIG_REJECTED,
                        'detail' => $rejection,
                    ];
                }//end if

                // ...and the half a required-key check cannot reach. A node
                // only sees the keys it looks for; the ones it does not look
                // for are invisible to it BY CONSTRUCTION, however careful its
                // validateConfig() is. Only the node's declared vocabulary
                // ({@see IFlowNodeConfigKeys}) can expose those.
                $dialect  = $this->dialectFindings(node: $node, edge: $edge, type: $type, edgeId: $edgeId);
                $blocking = array_merge($blocking, $dialect['blocking']);
                $warnings = array_merge($warnings, $dialect['warnings']);

                continue;
            }//end if

            $finding = $this->classify(type: $type, edge: $edgeId);
            if ($finding['reason'] === self::REASON_OWNER_NOT_ENABLED) {
                $warnings[] = $finding;
                continue;
            }

            $blocking[] = $finding;
        }//end foreach

        return [
            'blocking' => $blocking,
            'warnings' => $warnings,
        ];

    }//end inspect()

    /**
     * Refuse a document the instance cannot run, and log the rest.
     *
     * @param array       $flow  The flow document.
     * @param string|null $label A human name for the flow, used in the message.
     *
     * @return void
     *
     * @throws UnexpectedValueException When a step type can never resolve here.
     *
     * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
     */
    public function assertRunnable(array $flow, ?string $label=null): void
    {
        $report = $this->inspect(flow: $flow);
        $name   = ($label ?? (string) ($flow['name'] ?? 'flow'));

        foreach ($report['warnings'] as $warning) {
            if ($warning['reason'] === self::REASON_CONFIG_ONERROR_MISPLACED) {
                $this->logger->warning(
                    message: sprintf(
                        '[FlowNodePreflight] Flow "%s", edge "%s" (%s) sets config.onError="%s". '
                        .'Nothing reads that: the engine takes the policy from the EDGE, beside "type". '
                        .'It happens to match the default, so the run behaves the same — move it up one level.',
                        $name,
                        $warning['edge'],
                        $warning['type'],
                        ($warning['detail'] ?? '')
                    ),
                    context: [
                        'file'   => __FILE__,
                        'line'   => __LINE__,
                        'flow'   => $name,
                        'type'   => $warning['type'],
                        'edge'   => $warning['edge'],
                        'reason' => $warning['reason'],
                    ]
                );
                continue;
            }//end if

            // Loud on purpose. The measured failure was not that a step was
            // missing, it was that nothing said so — the run reported success.
            $this->logger->warning(
                message: sprintf(
                    '[FlowNodePreflight] Flow "%s" uses step type "%s", which app "%s" owns. '
                    .'That app is not enabled here, so this flow cannot run until it is.',
                    $name,
                    $warning['type'],
                    $warning['app']
                ),
                context: [
                    'file'   => __FILE__,
                    'line'   => __LINE__,
                    'flow'   => $name,
                    'type'   => $warning['type'],
                    'app'    => $warning['app'],
                    'edge'   => $warning['edge'],
                    'reason' => $warning['reason'],
                ]
            );
        }//end foreach

        if ($report['blocking'] === []) {
            return;
        }

        throw new UnexpectedValueException($this->describe(flow: $name, blocking: $report['blocking']));

    }//end assertRunnable()

    /**
     * Build the refusal message.
     *
     * Names every offending type and the app that owns it, all of them at once.
     * Reporting only the first would turn one fix into a fix-save-fix loop.
     *
     * @param string                            $flow     The flow's name.
     * @param array<int, array<string, string>> $blocking The blocking findings.
     *
     * @return string The message.
     *
     * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
     */
    public function describe(string $flow, array $blocking): string
    {
        $lines = [];
        foreach ($blocking as $finding) {
            if ($finding['reason'] === self::REASON_CONFIG_UNKNOWN_KEY) {
                // A node with an empty vocabulary needs saying in words:
                // "it reads " followed by nothing reads like a truncated
                // message, not like the deliberate answer it is.
                $reads = (string) ($finding['reads'] ?? '');
                if ($reads === '') {
                    $reads = 'no config at all';
                }

                $lines[] = sprintf(
                    '"%s" (edge %s) — config key(s) %s, which that node never reads; '
                    .'it reads %s. Ignored keys make the step a pass-through that reports success',
                    $finding['type'],
                    $finding['edge'],
                    ($finding['detail'] ?? '?'),
                    $reads
                );
                continue;
            }

            if ($finding['reason'] === self::REASON_CONFIG_ONERROR_MISPLACED) {
                $lines[] = sprintf(
                    '"%s" (edge %s) — config.onError="%s" is read by nothing; the engine takes the policy '
                    .'from the EDGE, beside "type". As written this step gets "%s"',
                    $finding['type'],
                    $finding['edge'],
                    ($finding['detail'] ?? '?'),
                    self::DEFAULT_ONERROR
                );
                continue;
            }

            if ($finding['reason'] === self::REASON_CONFIG_REJECTED) {
                $lines[] = sprintf(
                    '"%s" (edge %s) — the node exists but refuses this step\'s config: %s. '
                    .'A config it cannot read makes the step a pass-through that reports success',
                    $finding['type'],
                    $finding['edge'],
                    ($finding['detail'] ?? 'no detail')
                );
                continue;
            }

            if ($finding['reason'] === self::REASON_NOT_NAMESPACED) {
                $lines[] = sprintf(
                    '"%s" (edge %s) — not namespaced, so no app can provide it; '
                    .'step types are "<app>.<node>", e.g. "openregister.route"',
                    $finding['type'],
                    $finding['edge']
                );
                continue;
            }

            $lines[] = sprintf(
                '"%s" (edge %s) — app "%s" would own it and IS enabled here, but does not provide it; '
                .'that app is behind the flow',
                $finding['type'],
                $finding['edge'],
                $finding['app']
            );
        }//end foreach

        return sprintf(
            'Flow "%s" has %d step(s) this instance cannot run as written: %s. '
            .'Refusing the save: a step that cannot run only fails once the flow is already halfway through, '
            .'after earlier steps have made changes that do not roll back — and a step whose config is ignored '
            .'does not fail at all, it reports success having done nothing.',
            $flow,
            count($blocking),
            implode('; ', $lines)
        );

    }//end describe()

    /**
     * Ask the node whether it can read this edge's config.
     *
     * A node that throws for a reason of its own (a broken translation, a
     * missing collaborator) must not block the save — that would turn one bad
     * node into an unsavable instance. Only the node's own refusal counts, and
     * `UnexpectedValueException` is what every implementation in-tree raises.
     *
     * @param IFlowNode $node The resolved node.
     * @param array     $edge The edge declaring the step.
     *
     * @return string|null The node's message, or null when it accepts the config.
     *
     * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
     */
    private function configRejection(IFlowNode $node, array $edge): ?string
    {
        $config = ($edge['config'] ?? []);
        if (is_array($config) === false) {
            return 'Step config must be an object.';
        }

        try {
            $node->validateConfig($config);
        } catch (UnexpectedValueException $e) {
            return $e->getMessage();
        } catch (Throwable $e) {
            $this->logger->warning(
                message: '[FlowNodePreflight] A node\'s validateConfig() failed for its own reasons, not blocking: '
                    .$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__, 'node' => get_class($node)]
            );
        }//end try

        return null;

    }//end configRejection()

    /**
     * Every config key on this edge that the node will never read.
     *
     * Only asked of a node that declares its vocabulary. A node that does not
     * implement {@see IFlowNodeConfigKeys} is skipped entirely and silently:
     * OpenRegister cannot know what `openconnector.source-call` or
     * `hermiq.agent-step` read, and guessing would refuse correct flows from
     * apps that shipped before this contract existed. That is today's behaviour
     * preserved exactly — no node gets WORSE, some get better.
     *
     * Two exemptions, both deliberate and both documented on the interface:
     *
     * - A key beginning with `$` is an authoring annotation. `$why` and
     *   `$comment` appear throughout the fleet's flow documents, explaining to
     *   the next reader why a step exists. The engine has never read them. A
     *   check without this exemption would refuse nearly every real flow, which
     *   is a worse outcome than no check at all.
     * - `onError` gets its own diagnosis rather than a generic "unknown key",
     *   because the author did not invent it — they put a real engine option
     *   one level too deep, and telling them so is worth more than telling them
     *   the key is unrecognised.
     *
     * @param IFlowNode $node   The resolved node.
     * @param array     $edge   The edge declaring the step.
     * @param string    $type   The step type.
     * @param string    $edgeId The edge's identifier, for the message.
     *
     * @return array{blocking: array<int, array<string, string>>, warnings: array<int, array<string, string>>}
     *
     * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
     */
    private function dialectFindings(IFlowNode $node, array $edge, string $type, string $edgeId): array
    {
        $report = [
            'blocking' => [],
            'warnings' => [],
        ];

        $config = ($edge['config'] ?? []);
        $known  = $this->declaredKeys(node: $node);
        if ($known === null || is_array($config) === false || $config === []) {
            return $report;
        }

        $app    = $this->ownerOf(type: $type);
        $strays = $this->strayKeys(config: $config, known: $known);

        if ($strays['onError'] === true) {
            $finding  = $this->onErrorFinding(config: $config, type: $type, app: $app, edgeId: $edgeId);
            $severity = 'blocking';
            if ($finding['detail'] === self::DEFAULT_ONERROR) {
                $severity = 'warnings';
            }

            $report[$severity][] = $finding;
        }

        if ($strays['unknown'] === []) {
            return $report;
        }

        // One finding per EDGE, not per key. An edge authored in the wrong
        // dialect is wrong once — listing its four stray keys as four separate
        // refusals reads like four separate mistakes.
        $report['blocking'][] = [
            'type'   => $type,
            'app'    => $app,
            'edge'   => $edgeId,
            'reason' => self::REASON_CONFIG_UNKNOWN_KEY,
            'detail' => implode(', ', $strays['unknown']),
            'reads'  => implode(', ', $known),
        ];

        return $report;

    }//end dialectFindings()

    /**
     * A node's declared config vocabulary, or null when it declares none.
     *
     * Null and `[]` are different answers and the caller must be able to tell
     * them apart: `[]` is `openregister.switch` saying it reads no config at
     * all, null is a node that never joined the contract and must therefore not
     * be judged by it.
     *
     * @param IFlowNode $node The resolved node.
     *
     * @return array<int, string>|null The keys, or null when unchecked.
     *
     * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
     */
    private function declaredKeys(IFlowNode $node): ?array
    {
        if (($node instanceof IFlowNodeConfigKeys) === false) {
            return null;
        }

        try {
            $known = $node->configKeys();
        } catch (Throwable $e) {
            // A node whose own vocabulary lookup throws must not block the save
            // — same reasoning as configRejection(): one broken node cannot be
            // allowed to make the instance unsavable.
            $this->logger->warning(
                message: '[FlowNodePreflight] A node\'s configKeys() failed, skipping the dialect check: '.$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__, 'node' => get_class($node)]
            );
            return null;
        }

        return array_map(static fn ($key): string => (string) $key, (array) $known);

    }//end declaredKeys()

    /**
     * Split a step's config keys into the ones nothing will read.
     *
     * @param array              $config The step configuration.
     * @param array<int, string> $known  The node's declared vocabulary.
     *
     * @return array{unknown: array<int, string>, onError: bool}
     *
     * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
     */
    private function strayKeys(array $config, array $known): array
    {
        $unknown = [];
        $onError = false;

        foreach (array_keys($config) as $key) {
            $key = (string) $key;
            if (str_starts_with($key, IFlowNodeConfigKeys::ANNOTATION_PREFIX) === true) {
                continue;
            }

            if ($key === self::EDGE_LEVEL_ONERROR) {
                $onError = true;
                continue;
            }

            if (in_array($key, $known, true) === false) {
                $unknown[] = $key;
            }
        }//end foreach

        return [
            'unknown' => $unknown,
            'onError' => $onError,
        ];

    }//end strayKeys()

    /**
     * Describe an `onError` policy that was written one level too deep.
     *
     * @param array  $config The step configuration.
     * @param string $type   The step type.
     * @param string $app    The app that owns the type.
     * @param string $edgeId The edge's identifier.
     *
     * @return array<string, string> The finding; its severity is the caller's call.
     *
     * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
     */
    private function onErrorFinding(array $config, string $type, string $app, string $edgeId): array
    {
        return [
            'type'   => $type,
            'app'    => $app,
            'edge'   => $edgeId,
            'reason' => self::REASON_CONFIG_ONERROR_MISPLACED,
            'detail' => (string) ($config[self::EDGE_LEVEL_ONERROR] ?? ''),
        ];

    }//end onErrorFinding()

    /**
     * The app a namespaced type belongs to.
     *
     * @param string $type The step type.
     *
     * @return string The app id, or an empty string when the type is not namespaced.
     *
     * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
     */
    private function ownerOf(string $type): string
    {
        $separator = strpos($type, '.');
        if ($separator === false || $separator === 0) {
            return '';
        }

        return substr($type, 0, $separator);

    }//end ownerOf()

    /**
     * Decide why a type failed to resolve.
     *
     * @param string $type The unresolved step type.
     * @param string $edge The edge that names it.
     *
     * @return array<string, string> The finding.
     *
     * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
     */
    private function classify(string $type, string $edge): array
    {
        $separator = strpos($type, '.');
        if ($separator === false || $separator === 0) {
            return [
                'type'   => $type,
                'app'    => '',
                'edge'   => $edge,
                'reason' => self::REASON_NOT_NAMESPACED,
            ];
        }

        $app    = substr($type, 0, $separator);
        $reason = self::REASON_OWNER_NOT_ENABLED;
        if ($this->isAppEnabled(appId: $app) === true) {
            $reason = self::REASON_MISSING_FROM_OWNER;
        }

        return [
            'type'   => $type,
            'app'    => $app,
            'edge'   => $edge,
            'reason' => $reason,
        ];

    }//end classify()

    /**
     * The node answering to a type, or null when nothing does.
     *
     * @param string $type The step type.
     *
     * @return IFlowNode|null The node.
     *
     * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
     */
    private function resolve(string $type): ?IFlowNode
    {
        try {
            return $this->registry->get(type: $type);
        } catch (UnexpectedValueException) {
            return null;
        }

    }//end resolve()

    /**
     * Whether an app is enabled on this instance.
     *
     * A throwing app manager must not be read as "app absent": that would turn
     * an infrastructure hiccup into a blocked save. Treat it as enabled=false,
     * which downgrades the finding to a warning rather than a refusal.
     *
     * @param string $appId The app id.
     *
     * @return boolean True when the app is enabled.
     *
     * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
     */
    private function isAppEnabled(string $appId): bool
    {
        try {
            return $this->appManager->isEnabledForUser($appId);
        } catch (Throwable $e) {
            $this->logger->warning(
                message: '[FlowNodePreflight] Could not determine whether app "'.$appId.'" is enabled: '.$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__, 'app' => $appId]
            );
            return false;
        }

    }//end isAppEnabled()
}//end class
