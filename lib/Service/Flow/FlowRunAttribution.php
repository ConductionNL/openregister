<?php

/**
 * Who a flow run executes as, and which tenant owns it.
 *
 * Split out of {@see FlowRunService} because it is a self-contained question
 * with its own precedence rule, and because folding it in pushed that class
 * past the complexity ceiling — the same reason the other Flow* collaborators
 * in this directory exist.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves the identity a queued run executes as, and the tenant that owns it.
 *
 * A run is only runnable if it names WHOSE RIGHTS it uses, and only listable if
 * it names WHICH tenant it belongs to. Only the manual path has a session; a
 * scheduled, event-fired, MCP, workflow-engine or sub-flow dispatch does not.
 *
 * Every one of those except a SCHEDULE is still handed its identity by its
 * caller — an object event carries the user whose action raised it, a sub-flow
 * carries its parent run's. A schedule has nobody by construction, which is why
 * it is the one entry point that must name a user on the trigger node itself.
 *
 * 🔴 The flow's own `owner` is NOT a fallback, and this class used to treat it
 * as one. ADR-099 removed that: `flow.owner` says who may EDIT the definition,
 * and reading it as an acting identity silently converts authorship into
 * standing consent to unattended execution as the author. An unresolvable
 * identity now returns null, and `FlowRunService::queue()` refuses.
 *
 * This is the general form of a defect the codebase had patched five times one
 * call site at a time (or#2158). FlowScheduleService::fire() spells out the
 * consequence of the OTHER failure mode — no identity at all: an ownerless run
 * makes every attribution-requiring node refuse, with ObjectWriteNode answering
 * "this flow run has no owner", so a natively scheduled flow was silently
 * incapable of writing anything. Refusing at the queue replaces both.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/delegated-identity/spec.md
 */
class FlowRunAttribution {

	/**
	 * The trigger kind that has no caller and must therefore declare an identity.
	 *
	 * @var string
	 */
	private const TRIGGER_SCHEDULE = 'schedule';

	/**
	 * The node type a schedule trigger uses.
	 *
	 * @var string
	 */
	private const NODE_TRIGGER_SCHEDULE = 'openregister.trigger-schedule';

	/**
	 * The node-config key naming the identity a scheduled run executes as.
	 *
	 * @var string
	 */
	private const CONFIG_RUN_AS = 'runAs';

	/**
	 * Constructor.
	 *
	 * Both collaborators are resolved lazily through the container, matching
	 * FlowRunService: this runs on paths that need neither, and several tests
	 * build the surrounding service by hand.
	 *
	 * @param ContainerInterface $container The app container.
	 * @param LoggerInterface    $logger    The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve the run's acting identity and organisation.
	 *
	 * IDENTITY and TENANCY resolve independently, and by different rules. The
	 * mixed case is real — a signed-in caller whose organisation does not
	 * resolve still yields a run that must be scoped to the flow's tenant rather
	 * than to none — so they are not collapsed into one precedence chain.
	 *
	 * **Identity: caller, then the trigger. Never the flow.**
	 *
	 * The caller wins when there is one: a manual run is the acting user's act,
	 * and blaming the flow's author for somebody else's click would be a worse
	 * answer than none. Absent a caller, the TRIGGER answers — because a trigger
	 * is where a run begins, and a flow may carry several, each an independent
	 * entry point with its own identity.
	 *
	 * 🔴 The flow's `owner` is deliberately NOT a fallback here, and removing it
	 * is the point of ADR-099. `flow.owner` says who may EDIT this definition and
	 * which tenant it belongs to. Reading it as an acting identity turns "this
	 * person wrote a flow" into "this person consented to unattended execution as
	 * themselves, forever, under whatever triggers anyone later adds" — which
	 * they did not. An unresolvable identity therefore returns null and the
	 * caller refuses, rather than quietly borrowing the author's rights.
	 *
	 * **Tenancy: active organisation, then the flow.** Unchanged, and the flow
	 * IS the right fallback here: an organisation is a property of the definition
	 * in a way an acting identity is not.
	 *
	 * @param string      $flowId  The flow being queued.
	 * @param string|null $user    The caller's uid, when there is a session.
	 * @param string      $trigger What started the run — `manual`, `schedule`, an
	 *                             event id, `sub-flow`, `nc-flow`.
	 *
	 * @return array{user: string|null, organisation: string|null} The attribution.
	 *                                                             A null `user`
	 *                                                             means REFUSE.
	 *
	 * @spec openspec/specs/delegated-identity/spec.md
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function resolve(string $flowId, ?string $user, string $trigger = 'manual'): array {
		$caller = $this->orNull(value: $user);
		$organisation = $this->activeOrganisation();

		if ($caller !== null && $organisation !== null) {
			return ['user' => $caller, 'organisation' => $organisation];
		}

		$flow = $this->loadFlow(flowId: $flowId);

		return [
			'user' => ($caller ?? $this->declaredByTrigger(flow: $flow, trigger: $trigger)),
			'organisation' => ($organisation ?? $this->orNull(value: $flow?->getOrganisation())),
		];
	}//end resolve()

	/**
	 * The identity declared by the trigger that started this run.
	 *
	 * Only a SCHEDULE trigger declares one. Every other entry point already
	 * carries its identity in the caller argument: a manual run has the session
	 * user, an object event has the user whose action raised it, and a sub-flow
	 * is dispatched with its parent run's acting identity. A schedule has no
	 * caller by construction — nobody is there — which is exactly why it is the
	 * one trigger that must name a user on the node itself.
	 *
	 * Returns null when the flow cannot be read, when it carries no schedule
	 * trigger, or when that trigger declares no identity. Every one of those is
	 * a refusal at the caller, not a reason to substitute someone.
	 *
	 * @param object|null $flow    The flow entity, or null when unreadable.
	 * @param string      $trigger What started the run.
	 *
	 * @return string|null The declared uid, or null.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	private function declaredByTrigger(?object $flow, string $trigger): ?string {
		if ($flow === null || $trigger !== self::TRIGGER_SCHEDULE) {
			return null;
		}

		$nodes = $flow->getNodes();
		if (is_array($nodes) === false) {
			return null;
		}

		foreach ($nodes as $node) {
			if (is_array($node) === false
				|| ($node['type'] ?? null) !== self::NODE_TRIGGER_SCHEDULE
			) {
				continue;
			}

			// `config` is `[]` on a flow still mid-cutover from the trigger
			// COLUMNS to trigger NODES — measured on 3 of this instance's flows,
			// all of which keep their cron in the legacy column. Such a node
			// declares no identity, so it yields null and the run is refused
			// rather than fired ownerless.
			$config = ($node['config'] ?? []);
			if (is_array($config) === false) {
				continue;
			}

			$declared = $this->orNull(value: ($config[self::CONFIG_RUN_AS] ?? null));
			if ($declared !== null) {
				return $declared;
			}
		}

		return null;
	}//end declaredByTrigger()

	/**
	 * Load the flow, or null when it cannot be read.
	 *
	 * A lookup failure must not be reported as an attribution failure — the
	 * same rule FlowRunService::refuseDeadEnd() follows, so the downstream
	 * not-found handling is what speaks.
	 *
	 * @param string $flowId The flow uuid.
	 *
	 * @return object|null The flow entity, or null.
	 */
	private function loadFlow(string $flowId): ?object {
		try {
			return $this->container->get('OCA\OpenRegister\Db\FlowMapper')->findByUuid($flowId);
		} catch (Throwable $e) {
			$this->logger->debug(
				message: '[FlowRunAttribution] Could not load the flow to attribute its run: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'flow' => $flowId]
			);

			return null;
		}
	}//end loadFlow()

	/**
	 * The caller's active organisation uuid, or null when none resolves.
	 *
	 * @return string|null The organisation uuid.
	 */
	private function activeOrganisation(): ?string {
		try {
			$organisationService = $this->container->get('OCA\OpenRegister\Service\OrganisationService');
			$uuid = $organisationService->getActiveOrganisation()?->getUuid();
		} catch (Throwable $e) {
			$this->logger->debug(
				message: '[FlowRunAttribution] Could not resolve the active organisation: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);

			return null;
		}

		return $this->orNull(value: $uuid);
	}//end activeOrganisation()

	/**
	 * Normalise a blank value to null.
	 *
	 * Both ownership columns are nullable strings, and an empty string is not an
	 * identity — storing one would make `triggeredBy` look answered while
	 * naming nobody, which is harder to notice than a null.
	 *
	 * @param string|null $value The stored value.
	 *
	 * @return string|null The value, or null when it is blank.
	 */
	private function orNull(?string $value): ?string {
		if ($value === null || trim($value) === '') {
			return null;
		}

		return $value;
	}//end orNull()
}//end class
