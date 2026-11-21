<?php

/**
 * OpenRegister operator act
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Operations
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Operations;

/**
 * An act an operator performed, recorded as one completed run.
 *
 * @spec openspec/specs/operations-console/spec.md#requirement-the-instance-checks-its-own-data-and-repairs-it-as-a-separate-act-req-aoc-005
 */
final class OperatorAct {
	/**
	 * Constructor.
	 *
	 * @param string               $jobClass The act, as a class-shaped name.
	 * @param string               $actor    The uid that performed it.
	 * @param array<string, mixed> $details  What the act concerned.
	 * @param string|null          $message  A sentence about the act.
	 */
	public function __construct(
		public readonly string $jobClass,
		public readonly string $actor,
		public readonly array $details,
		public readonly ?string $message = null,
	) {
	}//end __construct()
}//end class
