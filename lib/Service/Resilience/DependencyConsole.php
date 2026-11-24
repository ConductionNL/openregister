<?php

/**
 * What the operations console shows of the dependency breakers, and the reset.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Resilience
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Resilience;

use OCA\OpenRegister\Kernel\Contracts\DependencyConsoleInterface;
use OCA\OpenRegister\Kernel\Contracts\OperatorActionAuditInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The dependency list and the administrator's reset (design D-7).
 *
 * Both are reached only through `OperationsDependenciesController`, whose
 * methods carry no `#[NoAdminRequired]`, so a non-administrator never gets here.
 *
 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
 */
class DependencyConsole implements DependencyConsoleInterface {

	/**
	 * The audit action of a reset.
	 */
	public const RESET_ACTION = 'dependency.reset';

	/**
	 * Constructor.
	 *
	 * @param BreakerRegistry           $breakers    The breakers.
	 * @param DatabasePressureMeter     $pressure    The database-pressure meter.
	 * @param OperatorActionAuditInterface $audit  The audit trail, written as the administrator.
	 * @param LoggerInterface           $logger      The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly BreakerRegistry $breakers,
		private readonly DatabasePressureMeter $pressure,
		private readonly OperatorActionAuditInterface $audit,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Every breaker that has state, the database pressure, and whether the state is shared.
	 *
	 * A key in the index whose breaker is closed and whose window is empty has
	 * no state left and is not listed.
	 *
	 * @return array{dependencies: list<array<string, mixed>>, pressure: array<string, mixed>, shared: bool}
	 *
	 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
	 */
	public function overview(): array {
		$breaker      = $this->breakers->breaker();
		$dependencies = [];
		foreach ($breaker->knownKeys() as $key) {
			$state = $breaker->state(key: $key);
			if ($state['state'] === 'closed' && $state['calls'] === 0 && $state['failures'] === 0) {
				continue;
			}

			$dependencies[] = $state;
		}

		usort($dependencies, static fn (array $one, array $other): int => strcmp($one['key'], $other['key']));

		return [
			'dependencies' => $dependencies,
			'pressure' => $this->pressure->state(),
			'shared' => $breaker->shared,
		];
	}//end overview()

	/**
	 * Close a breaker and audit it as `dependency.reset`, naming the administrator.
	 *
	 * Only a key the breaker knows can be reset, so a typo writes no audit row.
	 *
	 * @param string $key The breaker key.
	 *
	 * @return array<string, mixed>|null The breaker's state after the reset, or null for an unknown key.
	 *
	 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
	 */
	public function reset(string $key): ?array {
		$breaker = $this->breakers->breaker();
		if (in_array($key, $breaker->knownKeys(), true) === false) {
			return null;
		}

		$before = $breaker->state(key: $key);
		$breaker->reset(key: $key);
		$this->audit(key: $key, before: $before['state']);

		return $breaker->state(key: $key);
	}//end reset()

	/**
	 * Write the reset's audit row; a failed write is logged, the reset stands.
	 *
	 * @param string $key    The breaker key.
	 * @param string $before The state it was in.
	 *
	 * @return void
	 *
	 */
	private function audit(string $key, string $before): void {
		try {
			$this->audit->record(
				action: self::RESET_ACTION,
				changed: [
					'dependency' => DependencyUnavailableException::classOf(key: $key),
					'key' => $key,
					'state' => ['old' => $before, 'new' => 'closed'],
				]
			);
		} catch (Throwable $e) {
			$this->logger->error('[DependencyConsole] reset of ' . $key . ' not audited: ' . $e->getMessage());
		}
	}//end audit()
}//end class
