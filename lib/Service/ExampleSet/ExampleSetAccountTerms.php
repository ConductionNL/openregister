<?php

/**
 * OpenRegister Example Set Account Terms
 *
 * What every account step of one install shares: the set the accounts are
 * recorded for, the existing account ids the administrator lets the set
 * adopt, and the administrator's password for new cast accounts.
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
 * @spec openspec/specs/example-sets/spec.md#requirement-accounts-and-groups-come-from-the-cast-and-are-never-taken-over-req-exs-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * The set id, the adoptable account ids and the administrator's password of one install's account steps.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-accounts-and-groups-come-from-the-cast-and-are-never-taken-over-req-exs-003
 */
final class ExampleSetAccountTerms {
	/**
	 * Constructor.
	 *
	 * @param string             $set      The set id.
	 * @param array<int, string> $adopt    Account ids the administrator lets the set use although it did not create them.
	 * @param string             $password The administrator's password for new cast accounts, or ''.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $set,
		public readonly array $adopt,
		public readonly string $password,
	) {
	}//end __construct()
}//end class
