<?php

/**
 * Checking a fragment's rows before anything is written.
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
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * What every row of one fragment is checked against: the set's context, its accounts and the value filler.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
 */
final class ExampleSetFragmentCheck {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $context The set context the rows are filled from.
	 * @param array<int|string, mixed> $users The accounts the set declares.
	 * @param ExampleSetValues $values Fills a row's placeholders.
	 */
	public function __construct(
		public readonly array $context,
		public readonly array $users,
		public readonly ExampleSetValues $values,
	) {
	}//end __construct()
}//end class
