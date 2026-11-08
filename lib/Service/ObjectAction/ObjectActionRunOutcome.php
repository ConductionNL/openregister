<?php

/**
 * What one run of an action came to, as its audit entry records it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectAction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-every-run-is-audited-once-and-the-objects-action-history-can-be-read
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectAction;

/**
 * The run, the changes and the outcome of one action run.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-every-run-is-audited-once-and-the-objects-action-history-can-be-read
 */
final class ObjectActionRunOutcome {

	/**
	 * Constructor.
	 *
	 * @param string|null                                   $run     The run's uuid; null when the object was written directly.
	 * @param array<int, array{step: string, kind: string}> $changes What the run changed.
	 * @param string                                        $outcome The outcome.
	 */
	public function __construct(
		public readonly ?string $run,
		public readonly array $changes,
		public readonly string $outcome,
	) {
	}//end __construct()
}//end class
