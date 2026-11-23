<?php

/**
 * The resume slot a set's task fills in the run that waits on it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * The waiting run, the step it waits on and the task that fills it.
 *
 * @spec openspec/specs/example-sets/spec.md
 */
final class ExampleSetRunSlot {
	/**
	 * Constructor.
	 *
	 * @param string $runUuid The waiting run; empty when the task names none.
	 * @param string $nodeId The step the run waits on.
	 * @param string $taskUuid The written task.
	 */
	public function __construct(
		public readonly string $runUuid,
		public readonly string $nodeId,
		public readonly string $taskUuid,
	) {
	}//end __construct()
}//end class
