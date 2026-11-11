<?php

/**
 * OpenRegister Example Set Required Register
 *
 * One register an example set requires, as the install ensures it: the set it
 * is required by, its slug and whether its mock data is wanted.
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
 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-imports-the-openregister-registers-it-requires-req-exs-009
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * One required register of a set.
 */
class ExampleSetRequiredRegister {
	/**
	 * Constructor.
	 *
	 * @param string $set  The set that requires the register.
	 * @param string $slug The register's slug.
	 * @param bool   $mock Whether the register's mock data is imported with it.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $set,
		public readonly string $slug,
		public readonly bool $mock,
	) {
	}//end __construct()
}//end class
