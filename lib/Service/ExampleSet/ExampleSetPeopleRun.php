<?php

/**
 * OpenRegister Example Set People Run
 *
 * The state of one accounts install: the request, the record as the install
 * grows it, and the account part of the report it fills.
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
 * One accounts install in progress; it never leaves ExampleSetPeople.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-accounts-and-groups-come-from-the-cast-and-are-never-taken-over-req-exs-003
 */
final class ExampleSetPeopleRun {
	/**
	 * The account part of the install report.
	 *
	 * @var array<string, mixed>
	 */
	public array $report = [
		'users'       => [],
		'groups'      => [],
		'memberships' => [],
		'subAdmins'   => [],
		'passwords'   => [],
		'dropped'     => [],
	];

	/**
	 * Constructor.
	 *
	 * @param ExampleSetAccountsInstall $install The plan and the account terms.
	 * @param array<string, mixed>      $record  The record; written after every step.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly ExampleSetAccountsInstall $install,
		public array $record,
	) {
	}//end __construct()

	/**
	 * The set id.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-accounts-and-groups-come-from-the-cast-and-are-never-taken-over-req-exs-003
	 */
	public function set(): string {
		return $this->install->terms->set;
	}//end set()
}//end class
