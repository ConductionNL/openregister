<?php

/**
 * A run that asserts a delegation: which flow, what started it, who declared
 * the delegation and whom the run would act as.
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
 * @spec openspec/specs/delegation-grants/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * The delegation a queued run would execute under.
 *
 * @spec openspec/specs/delegation-grants/spec.md
 */
final class DelegatedRun {
	/**
	 * Describe the run.
	 *
	 * @param string      $flowId     The flow being queued.
	 * @param string      $trigger    What started the run.
	 * @param string|null $declaredBy The principal who asserted the delegation.
	 * @param string      $runAs      The identity the run would execute as.
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function __construct(
		public readonly string $flowId,
		public readonly string $trigger,
		public readonly ?string $declaredBy,
		public readonly string $runAs,
	) {
	}//end __construct()

	/**
	 * Whether the run delegates at all: a trigger naming its own author, or
	 * nobody, delegates nothing.
	 *
	 * @return bool True when a principal other than the run identity asserted it.
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function delegates(): bool {
		return $this->declaredBy !== null && $this->declaredBy !== $this->runAs;
	}//end delegates()

	/**
	 * The fields a log line about this run names.
	 *
	 * @return array{flow: string, trigger: string, declaredBy: string|null, runAs: string}
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function logContext(): array {
		return [
			'flow' => $this->flowId,
			'trigger' => $this->trigger,
			'declaredBy' => $this->declaredBy,
			'runAs' => $this->runAs,
		];
	}//end logContext()
}//end class
