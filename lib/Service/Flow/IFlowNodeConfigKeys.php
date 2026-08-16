<?php

/**
 * A node that can name the config keys it reads.
 *
 * WHY THIS EXISTS
 * ---------------
 * `IFlowNode::validateConfig()` answers "is anything REQUIRED missing". It
 * cannot answer "is anything here that I will silently ignore", and the second
 * question is the one that produced the measured failures.
 *
 * `EndNode::validateConfig()` has an empty body: it requires nothing, so it
 * accepts `{"status": "...", "reason": "..."}` — keys it never reads; it reads
 * `error` / `message` — and then stops the run with the generic "Flow stopped"
 * and `isError=false`. `SubFlowNode` requires only `flow`/`flowId`, so it
 * accepts `input`/`output` alongside them and passes the child nothing. In both
 * cases the type resolves, the step dispatches, the run reports COMPLETED, and
 * the work never happened. A required-key check cannot see either one, because
 * in both the required keys are present.
 *
 * So a node must be able to state its whole vocabulary, not just its mandatory
 * part. That is this interface.
 *
 * WHY NOT ON `IFlowNode` ITSELF
 * -----------------------------
 * Adding a method to `IFlowNode` is a fatal error for every class implementing
 * it that has not been updated — and implementations live in other repositories
 * (openconnector ships `source-call` and `synchronization-run`, hermiq ships
 * `agent-step` and `workload-step`). Publishing a release that fatals those apps
 * on load is a worse outcome than the defect being closed.
 *
 * A separate, optional interface is also how Nextcloud itself adds capability to
 * an operation contract: `ISpecificOperation` and `IComplexOperation` extend
 * `IOperation` rather than widening it, and core probes with `instanceof`. This
 * file follows that, deliberately, for the same reason `IFlowNode` was shaped
 * after `IOperation` in the first place.
 *
 * The cost is honest and stated: a node that does not implement this interface
 * is NOT unknown-key checked. That is exactly today's behaviour, so nothing
 * regresses — but nothing improves for that node either, until its owner opts
 * in. Every node OpenRegister itself ships implements it.
 *
 * THE ANNOTATION RULE
 * -------------------
 * A config key beginning with `$` is documentation, not configuration. Flow
 * documents in the fleet carry `$why` and `$comment` throughout — they explain
 * to the next reader why a step exists, and the engine has never read them.
 * A check that refused them would refuse nearly every real flow, so `$`-prefixed
 * keys are exempt by contract, not by accident. This is the same rule
 * `hydra/scripts/test-flow-definitions.sh` applies, stated here so the two
 * cannot drift apart on what counts as an annotation.
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

/**
 * Optional companion to {@see IFlowNode}: the node's full config vocabulary.
 */
interface IFlowNodeConfigKeys {
	/**
	 * The prefix marking a config key as an authoring annotation.
	 *
	 * `$why`, `$comment` and anything else so prefixed is documentation the
	 * engine never reads and no author mistakes for behaviour. Exempt from the
	 * unknown-key check by contract.
	 */
	public const ANNOTATION_PREFIX = '$';

	/**
	 * Every config key this node reads, required and optional alike.
	 *
	 * The list is the node's whole vocabulary. A key on a step that is not in
	 * it, and is not an annotation ({@see self::ANNOTATION_PREFIX}), is one the
	 * node will silently ignore — which is a step that returns its input
	 * untouched and reports success.
	 *
	 * Only TOP-LEVEL keys. Nested shape (`rules[].condition`, `set.<field>`) is
	 * the node's own business and is checked in `validateConfig()`, where the
	 * node can say something specific about it.
	 *
	 * An empty list is meaningful and legal: it means the node reads NO config,
	 * so any key at all on that step is wrong. `openregister.switch` is exactly
	 * that — its conditions live on its outgoing edges, never in its config.
	 *
	 * @return array<int, string> The accepted top-level config keys.
	 *
	 * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
	 */
	public function configKeys(): array;
}//end interface
