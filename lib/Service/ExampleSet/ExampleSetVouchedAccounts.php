<?php

/**
 * OpenRegister Example Set Vouched Accounts
 *
 * The existing accounts a set may use: the ones its record holds and the ones
 * the administrator lets it adopt.
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
 * Who vouches for an existing account: the record or the administrator.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-accounts-and-groups-come-from-the-cast-and-are-never-taken-over-req-exs-003
 */
final class ExampleSetVouchedAccounts {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $record The record; its `users` are the set's own.
	 * @param array<int, string>   $adopt  Adoptable account ids.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $record,
		public readonly array $adopt,
	) {
	}//end __construct()

	/**
	 * Whether the record or the administrator vouches for an account id.
	 *
	 * @param string $uid The account id.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-accounts-and-groups-come-from-the-cast-and-are-never-taken-over-req-exs-003
	 */
	public function vouchFor(string $uid): bool {
		return isset($this->record['users'][$uid]) === true || in_array($uid, $this->adopt, true) === true;
	}//end vouchFor()
}//end class
