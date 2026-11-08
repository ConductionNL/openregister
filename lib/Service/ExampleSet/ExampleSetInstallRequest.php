<?php

/**
 * OpenRegister Example Set Install Request
 *
 * What one ExampleSetInstaller::install() call was asked to install: the plan,
 * the accounts it may adopt, the password for new accounts and the install day.
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
 * @spec openspec/specs/example-sets/spec.md#requirement-an-install-proves-what-arrived-req-exs-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use DateTimeImmutable;

/**
 * One install request, passed from install() to the step that runs inside the set's lock.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-an-install-proves-what-arrived-req-exs-006
 */
final class ExampleSetInstallRequest {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $plan     A plan from ExampleSetCatalogue::gather().
	 * @param array<int, string>   $adopt    Account ids the administrator lets the set use.
	 * @param string               $password One password for every new account; '' makes one per account.
	 * @param DateTimeImmutable    $today    The install day.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $plan,
		public readonly array $adopt,
		public readonly string $password,
		public readonly DateTimeImmutable $today,
	) {
	}//end __construct()
}//end class
