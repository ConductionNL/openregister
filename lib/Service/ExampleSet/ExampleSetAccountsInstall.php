<?php

/**
 * OpenRegister Example Set Accounts Install
 *
 * The request to write a set's groups, accounts and memberships: the plan and
 * the account terms (the set id, the adoptable account ids, the password).
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
 * Write the accounts, groups and memberships a plan declares.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-accounts-and-groups-come-from-the-cast-and-are-never-taken-over-req-exs-003
 */
final class ExampleSetAccountsInstall {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>   $plan  The plan.
	 * @param ExampleSetAccountTerms $terms The set id, the adoptable account ids and the administrator's password.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $plan,
		public readonly ExampleSetAccountTerms $terms,
	) {
	}//end __construct()
}//end class
