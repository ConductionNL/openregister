<?php

/**
 * OpenRegister Example Set Held Change
 *
 * A held change while it follows its declaration: the declared change, the
 * record entry kept for it, the change as resolved now and whether this run
 * refreshes. Passed between the steps of ExampleSetChanges::follow() as one
 * value.
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-held-change-or-preference-follows-its-declaration
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * One held change, resolved again.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-held-change-or-preference-follows-its-declaration
 */
class ExampleSetHeldChange {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $change   The declared change.
	 * @param array<string, mixed> $entry    The record entry, held fields only.
	 * @param array<string, mixed> $resolved From ExampleSetChanges::resolve(), without a refusal.
	 * @param bool                 $refresh  Whether this run refreshes.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $change,
		public readonly array $entry,
		public readonly array $resolved,
		public readonly bool $refresh,
	) {
	}//end __construct()
}//end class
