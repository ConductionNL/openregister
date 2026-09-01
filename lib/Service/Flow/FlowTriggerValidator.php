<?php

/**
 * Validates a flow's TRIGGER nodes before the flow is written.
 *
 * 🔴 THIS CLOSES AN ORPHANED CAPABILITY, NOT A NEW RULE.
 *
 * Every trigger node has implemented `validateConfig()` since it was written.
 * `TriggerScheduleNode` refuses a missing or malformed cron expression;
 * `TriggerObjectNode` refuses a trigger with no subject. Nothing ever called
 * either on the save path: {@see FlowNodePreflight} invokes `validateConfig()`
 * only for STEPS, reading `$edge['config']`, and a trigger is not a step.
 *
 * Measured on the dev instance 2026-08-24: a schedule trigger posted with
 * `config: {}` — no cron, no identity — was stored with HTTP 201, and all three
 * live schedule-triggered flows carry exactly that shape. The validator was
 * written, unit-tested and unreachable, so the defect it existed to prevent
 * happened anyway. A test that calls `validateConfig()` directly passes while
 * the behaviour is absent from every real request.
 *
 * WHY TRIGGERS AND NOT EVERY NODE
 *
 * `flow-engine` requires that saving a half-wired flow SUCCEEDS and warns: an
 * editor cannot demand authors build a graph in an order that is never
 * disconnected. That rule is about WIRING, and an unconnected node mid-authoring
 * is normal.
 *
 * A trigger's own required fields are a different thing. A schedule with no cron
 * never fires; a schedule with no `runAs` has no identity to fire as. Neither is
 * a stage of authoring — both are a flow that is finished and broken, and both
 * fail silently at a time and place far from the author. So connectivity keeps
 * warning, and a trigger's own vocabulary is enforced here, before the write.
 *
 * Split out of {@see FlowService} because that class was already at its
 * complexity ceiling — the same reason the other `Flow*` collaborators in this
 * directory exist.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Service\Delegation\DelegationService;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use UnexpectedValueException;

/**
 * Asks every trigger node in a flow whether it accepts its own config.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
class FlowTriggerValidator {

	/**
	 * The trigger-config key recording WHO asserted the delegation.
	 *
	 * Server-written on every save, never trusted from a request body. Read at
	 * fire time as the principal whose grant must still be live.
	 *
	 * @var string
	 */
	public const CONFIG_DECLARED_BY = 'runAsDeclaredBy';

	/**
	 * Constructor.
	 *
	 * The registry is resolved lazily through the container rather than injected,
	 * matching the other collaborators here: this runs on a request path that
	 * already holds the container, and several tests build the surrounding
	 * service by hand.
	 *
	 * @param ContainerInterface $container Resolves the node registry.
	 * @param LoggerInterface    $logger    Records a registry that would not build.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Let every resolvable trigger node reject its own config.
	 *
	 * Node types this instance cannot resolve are SKIPPED, not refused: a leaf
	 * app's trigger is not OpenRegister's to validate, and guessing would reject
	 * correct flows authored against a fuller instance.
	 *
	 * @param Flow $flow The flow about to be written.
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException When a trigger node rejects its config.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function validate(Flow $flow): void {
		// `getNodes()` is declared `array|null`, so the null-coalesce already
		// guarantees an array — an is_array() guard here is dead by construction
		// and PHPStan says so.
		$nodes = ($flow->getNodes() ?? []);

		$registry = $this->registry();
		if ($registry === null) {
			return;
		}

		// Resolved ONCE for the whole flow. The identity performing the save is a
		// property of the request, not of a node, and reading it per node would
		// invite the impression that different nodes could be saved by different
		// people.
		$savedBy = $this->savingUid();

		$stamped = false;
		foreach ($nodes as $index => $node) {
			$result = $this->validateNode(registry: $registry, node: $node, savedBy: $savedBy);
			if ($result !== null) {
				$nodes[$index] = $result;
				$stamped = true;
			}
		}

		if ($stamped === true) {
			$flow->setNodes($nodes);
		}

	}//end validate()

	/**
	 * The uid performing this save, or null when nothing is.
	 *
	 * Null means CODE-INITIATED — a migration, a repair step, an installation
	 * seed. Those run with no session at all, and there is no principal for a
	 * grant to be checked against, so the delegation check does not apply to
	 * them. That is not a hole to be closed by falling back to the flow's owner:
	 * `flow.owner` says who may edit the definition, and treating it as the
	 * saver would let a code path assert a grant on behalf of a person who is
	 * not present and did not ask.
	 *
	 * @return string|null The saving uid, or null when code-initiated.
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	private function savingUid(): ?string {
		try {
			$session = $this->container->get(IUserSession::class);
		} catch (Throwable $e) {
			return null;
		}

		if (($session instanceof IUserSession) === false) {
			return null;
		}

		$user = $session->getUser();
		if ($user === null) {
			return null;
		}

		return $user->getUID();
	}//end savingUid()

	/**
	 * Ask one node to reject its config, when it is a trigger this instance knows.
	 *
	 * Returns the node REWRITTEN when the delegation stamp changed, and null when
	 * it did not. Returning the node rather than mutating in place keeps the
	 * caller in charge of whether the flow gets written back at all.
	 *
	 * @param object      $registry The node registry.
	 * @param mixed       $node     One entry from the flow's node list.
	 * @param string|null $savedBy  The uid performing the save, or null when code-initiated.
	 *
	 * @return array|null The rewritten node, or null when unchanged.
	 *
	 * @throws \InvalidArgumentException When the node rejects its config.
	 */
	private function validateNode(object $registry, mixed $node, ?string $savedBy = null): ?array {
		if (is_array($node) === false) {
			return null;
		}

		$type = trim((string)($node['type'] ?? ''));
		if ($type === '') {
			return null;
		}

		try {
			$resolved = $registry->get($type);
		} catch (UnexpectedValueException $e) {
			// An unknown node type is not this class's verdict to give. It is a
			// leaf app's node that is not installed here, or a typo the preflight
			// already reports — either way refusing the save would make this
			// instance unable to store a flow authored against a fuller one.
			return null;
		}

		// BOTH interfaces, deliberately. `IFlowTriggerNode` is an empty MARKER —
		// it says "this node is an entry point" and declares no methods — so
		// narrowing on it alone leaves `validateConfig()` unproven, which is
		// exactly what PHPStan flagged. `IFlowNode` is what declares the method.
		if (($resolved instanceof IFlowTriggerNode) === false
			|| ($resolved instanceof IFlowNode) === false
		) {
			return null;
		}

		$config = ($node['config'] ?? []);
		if (is_array($config) === false) {
			$config = [];
		}

		// The node's own message goes through unchanged: it names the key and
		// says why, which is the point of asking the node rather than
		// re-implementing its rules out here.
		$resolved->validateConfig($config);

		$stamped = $this->validateDelegation(config: $config, savedBy: $savedBy);
		if ($stamped === $config) {
			return null;
		}

		$node['config'] = $stamped;

		return $node;
	}//end validateNode()

	/**
	 * Refuse a trigger that names someone the saver holds no grant for.
	 *
	 * Naming YOURSELF stays free — that is not delegation, and requiring a grant
	 * for it would make the ordinary case need a record nobody would ever answer.
	 *
	 * 🔴 A PASS HERE IS NOT STANDING AUTHORIZATION. It answers "may this be
	 * saved", judged against the grants that exist at save time. The grant can be
	 * revoked before the schedule ever fires, which is why the fire path resolves
	 * again rather than trusting that the definition was once valid. Save-time
	 * checking exists so an author is told at the keyboard instead of at 03:00 in
	 * a log nobody reads — not so the fire path can skip the question.
	 *
	 * THE STAMP
	 *
	 * When the delegation is permitted the config is returned carrying
	 * `runAsDeclaredBy: <saver>`. That field is what makes the fire-time re-check
	 * possible at all: a schedule fires unattended, so at 03:00 there is no
	 * principal in the room to check a grant against, and without a record of who
	 * asserted the delegation the only candidate left is `flow.owner` — which
	 * answers a different question and would quietly re-introduce the fallback
	 * ADR-099 removed.
	 *
	 * 🔴 The stamp is SERVER-WRITTEN on every save and never read from the
	 * request. A client can put any `runAsDeclaredBy` it likes in the POST body;
	 * overwriting it unconditionally — and STRIPPING it when the trigger names
	 * the saver — is what stops a forged value from standing in for a grant.
	 *
	 * @param array       $config  The trigger's config.
	 * @param string|null $savedBy The uid performing the save, or null when code-initiated.
	 *
	 * @return array The config, with the delegation stamp corrected.
	 *
	 * @throws \InvalidArgumentException When the saver may not delegate to the named user.
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	private function validateDelegation(array $config, ?string $savedBy): array {
		$runAs = trim((string)($config['runAs'] ?? ''));
		if ($runAs === '' || $savedBy === null || $runAs === $savedBy) {
			// No delegation is being asserted, so no stamp may survive. A value
			// left here from a previous save — or supplied by a client that
			// noticed the field — would be read at fire time as an assertion
			// nobody made.
			unset($config[self::CONFIG_DECLARED_BY]);

			return $config;
		}

		// Only NOW does the delegation service become load-bearing, and only now
		// does a resolution failure have to fail closed. Refusing every flow save
		// because a container could not build a service most saves never touch
		// would turn an infrastructure fault into an editing outage — the same
		// reasoning that makes registry() return null rather than throw.
		try {
			$delegation = $this->container->get(DelegationService::class);
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[FlowTriggerValidator] Could not resolve the delegation service; refusing the delegation: '
					. $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'savedBy' => $savedBy, 'runAs' => $runAs]
			);

			throw new InvalidArgumentException(
				sprintf(
					'Cannot verify that "%s" may schedule runs as "%s": the delegation store is unavailable. '
					. 'The trigger has not been saved.',
					$savedBy,
					$runAs
				)
			);
		}//end try

		$verdict = $delegation->verdictFor(principal: $savedBy, actingAs: $runAs);
		if ($verdict->permitted === true) {
			$config[self::CONFIG_DECLARED_BY] = $savedBy;

			return $config;
		}

		// The reason is in the message because the four refusals send the author
		// to four different places — ask, wait, give up, or find an
		// administrator. "Not allowed" sends them to all four.
		throw new InvalidArgumentException(
			sprintf(
				'The schedule trigger names "%s", but "%s" may not act as them (%s). %s',
				$runAs,
				$savedBy,
				$verdict->reason,
				$verdict->detail
			)
		);
	}//end validateDelegation()

	/**
	 * The node registry, or null when it cannot be built.
	 *
	 * A resolution failure is not a validation verdict. Refusing every save
	 * because the registry would not build turns an infrastructure fault into
	 * data loss for whoever was editing.
	 *
	 * @return object|null The registry, or null.
	 */
	private function registry(): ?object {
		try {
			return $this->container->get('OCA\OpenRegister\Service\Flow\FlowNodeRegistry');
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[FlowTriggerValidator] Could not resolve the node registry: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);

			return null;
		}
	}//end registry()
}//end class
