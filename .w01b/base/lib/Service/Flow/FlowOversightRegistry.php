<?php

/**
 * Holds the oversight checks and asks them whether a hop may proceed.
 *
 * The whole contract of this class is that it FAILS CLOSED. A check that throws
 * is a veto, not an absence of objection — the alternative is a gate that
 * silently opens whenever the thing guarding it breaks, which is the standard
 * way a safety rail becomes decorative while still reading as present in the
 * code.
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
 * @spec openspec/changes/flow-engine-unification/specs/flow-oversight/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Registry of pre-hop oversight checks.
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-oversight/spec.md
 */
class FlowOversightRegistry {

	/**
	 * The registered checks, keyed by id.
	 *
	 * @var array<string, IFlowOversightCheck>
	 */
	private array $checks = [];

	/**
	 * Whether contributions have been collected.
	 *
	 * @var boolean
	 */
	private bool $discovered = false;

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Records a check that misbehaves.
	 * @param IEventDispatcher|null $dispatcher Collects contributed checks by
	 *                                          dispatching
	 *                                          {@see RegisterFlowOversightEvent},
	 *                                          the same way FlowNodeRegistry
	 *                                          collects node types. Nullable so
	 *                                          the registry stays constructible
	 *                                          without a container — a test that
	 *                                          registers its checks by hand needs
	 *                                          no discovery — and defaulted LAST
	 *                                          so existing positional
	 *                                          constructions keep meaning what
	 *                                          they meant.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly ?IEventDispatcher $dispatcher = null,
	) {

	}//end __construct()

	/**
	 * Collect contributed checks once, lazily.
	 *
	 * THE GAP THIS CLOSES: the listeners for {@see RegisterFlowOversightEvent}
	 * were registered on every boot, but nothing ever DISPATCHED the event —
	 * `FlowNodeRegistry` dispatches its own registration event before first
	 * use, and this registry had no equivalent. The result was an oversight
	 * gate that was consulted on every hop and could never hold a check:
	 * `firstRefusal()` iterated an empty list and consented, so the instance
	 * kill switch (and every app-contributed check) was decorative. Observed
	 * live 2026-09-01: `flow_kill_switch=1` plus a resume left a suspended run
	 * suspended instead of stopping it, because no veto ever fired.
	 *
	 * Set BEFORE dispatching, exactly as FlowNodeRegistry::load() does: a
	 * listener that resolves a service which itself consults this registry
	 * would otherwise re-enter and dispatch again.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-oversight/spec.md
	 */
	private function discover(): void {
		if ($this->discovered === true || $this->dispatcher === null) {
			return;
		}

		$this->discovered = true;
		$this->dispatcher->dispatchTyped(new RegisterFlowOversightEvent(registry: $this));

	}//end discover()

	/**
	 * Register an oversight check.
	 *
	 * Later registrations of the same id replace earlier ones, matching how the
	 * node registry behaves, so an app can deliberately override a check it
	 * ships itself.
	 *
	 * @param IFlowOversightCheck $check The check to add.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-oversight/spec.md
	 */
	public function register(IFlowOversightCheck $check): void {
		$this->checks[$check->getId()] = $check;

	}//end register()

	/**
	 * The registered checks, keyed by id.
	 *
	 * @return array<string, IFlowOversightCheck> The checks.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-oversight/spec.md
	 */
	public function all(): array {
		$this->discover();

		return $this->checks;
	}//end all()

	/**
	 * Ask every check whether this hop may run.
	 *
	 * Returns the FIRST refusal, with the vetoing check's id, so the caller can
	 * record what stopped the run. An empty registry consents — there is
	 * nothing to object — which is why enabling oversight on an instance with
	 * no registered checks is a no-op rather than a wall.
	 *
	 * @param array<string, mixed> $context The run context passed to each check.
	 *
	 * @return array{checkId: string, reason: string}|null The refusal, or null when every check consents.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-oversight/spec.md
	 */
	public function firstRefusal(array $context): ?array {
		$this->discover();

		foreach ($this->checks as $id => $check) {
			try {
				$reason = $check->veto(context: $context);
			} catch (Throwable $e) {
				// FAIL CLOSED. A check that throws has not consented, and
				// treating its failure as consent would mean an oversight
				// outage silently disables oversight — precisely when it is
				// most likely to matter.
				$this->logger->error(
					message: '[FlowOversight] Check "' . $id . '" threw; refusing the hop: ' . $e->getMessage(),
					context: ['exception' => $e, 'file' => __FILE__, 'line' => __LINE__]
				);

				return [
					'checkId' => $id,
					'reason' => 'Oversight check "' . $id . '" could not complete, so the hop was refused.',
				];
			}//end try

			if ($reason !== null && trim($reason) !== '') {
				return ['checkId' => $id, 'reason' => $reason];
			}
		}//end foreach

		return null;
	}//end firstRefusal()
}//end class
