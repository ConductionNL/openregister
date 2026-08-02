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

        foreach ($nodes as $node) {
            if (is_array($node) === false || isset($node['id']) === false) {
                return false;
            }
        }

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

    }//end looksLikeFlow()

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

            if ($this->isRegistered(type: $type) === true) {
                continue;
            }

            $finding = $this->classify(type: $type, edge: (string) ($edge['id'] ?? $edge['name'] ?? $index));
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
        }

        return sprintf(
            'Flow "%s" names %d step type(s) this instance cannot run: %s. '
            .'Refusing the save: an unrunnable flow only fails once it is already halfway through, '
            .'after earlier steps have made changes that do not roll back.',
            $flow,
            count($blocking),
            implode('; ', $lines)
        );

    }//end describe()

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
     * Whether the registry provides a type.
     *
     * @param string $type The step type.
     *
     * @return boolean True when a node answers to it.
     *
     * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
     */
    private function isRegistered(string $type): bool
    {
        try {
            $this->registry->get(type: $type);
            return true;
        } catch (UnexpectedValueException) {
            return false;
        }

    }//end isRegistered()

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
