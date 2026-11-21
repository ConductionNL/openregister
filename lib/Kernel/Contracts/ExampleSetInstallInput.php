<?php

/**
 * ExampleSetInstallInput: what an example set is checked or installed with.
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
 * The plan of an example set, the account ids the administrator lets it adopt,
 * the password for new accounts and the install day. A check uses all but the
 * password.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-an-install-proves-what-arrived-req-exs-006
 */
final class ExampleSetInstallInput {

	/**
	 * Constructor
	 *
	 * @param array<string, mixed>   $plan     A plan from ExampleSetCatalogue::gather().
	 * @param array<int, string>     $adopt    Account ids the administrator lets the set use although it did not create them.
	 * @param string                 $password One password for every new account; '' makes one per account.
	 * @param DateTimeImmutable|null $today    The install day; now in the set's time zone when null.
	 */
	public function __construct(
		public readonly array $plan,
		public readonly array $adopt=[],
		public readonly string $password='',
		public readonly ?DateTimeImmutable $today=null,
	) {
	}//end __construct()
}//end class
