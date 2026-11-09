<?php

/**
 * BulkSaveOutcomeInterface: what a bulk save lost, and why, as the bulk controller reads it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Dto
 * @package  OCA\OpenRegister\Dto
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Dto;

/**
 * The methods of BulkSaveOutcome that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface BulkSaveOutcomeInterface {

	/**
	 * Number of submitted objects that were not written.
	 *
	 * @return int The count.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function failedCount(): int;

	/**
	 * Per-object explanations, each `{index, uuid, error, type}`.
	 *
	 * @return array<int, array<string, mixed>> The explanations.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function failures(): array;

	/**
	 * Whether every submitted object was accounted for.
	 *
	 * @return bool True when nothing was lost.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function isComplete(): bool;
}//end interface
