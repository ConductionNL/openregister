<?php

/**
 * ExampleSetInstallerInterface: check and install an example set plan.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

use DateTimeImmutable;

/**
 * Checks an example set plan against the instance and installs it. Implemented by ExampleSetInstaller.
 */
interface ExampleSetInstallerInterface {

	/**
	 * Check a set without writing any of its accounts, groups or objects.
	 *
	 * A register the set requires that is not on the instance is imported
	 * first, because its schemas are what the objects are checked against;
	 * the import is recorded, and remove takes it away again.
	 *
	 * An account id the set creates (a cast member's or a service account's)
	 * counts as existing for a `user` format here: the accounts are written
	 * first in a real install, and no other id is let through.
	 *
	 * @param array<string, mixed>   $plan  A plan from ExampleSetCatalogue::gather().
	 * @param array<int, string>     $adopt Account ids the administrator lets the set use although it did not create them.
	 * @param DateTimeImmutable|null $today The install day; now in the set's time zone when null.
	 *
	 * @return array{refusals: array<int, string>, checked: array<string, int>, checkedChanges: array<string, int>, registers: array<string, string>}
	 *         Every refusal, the number of objects (`checked`) and of changes (`checkedChanges`) checked per register
	 *         and schema, counted apart as an install reports them, and per required register `imported` or `kept`.
	 */
	public function check(array $plan, array $adopt=[], ?DateTimeImmutable $today=null): array;

	/**
	 * Install a set: check, then write what the record lacks, then prove it.
	 *
	 * @param array<string, mixed>   $plan     A plan from ExampleSetCatalogue::gather().
	 * @param array<int, string>     $adopt    Account ids the administrator lets the set use.
	 * @param string                 $password One password for every new account; '' makes one per account.
	 * @param DateTimeImmutable|null $today    The install day; now in the set's time zone when null.
	 *
	 * @return array<string, mixed> `refusals` (non-empty: none of the set's accounts or objects was written),
	 *                              `registers`, `users`, `groups`, `memberships` and `changes` (each name to
	 *                              `imported`, `created`, `adopted` or `kept`), `passwords` (uid to a password this
	 *                              run made), `types`, `dropped`, `missing`, `lost` and `ok`.
	 */
	public function install(array $plan, array $adopt=[], string $password='', ?DateTimeImmutable $today=null): array;
}//end interface
