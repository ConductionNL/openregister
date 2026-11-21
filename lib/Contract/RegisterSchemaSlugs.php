<?php

/**
 * RegisterSchemaSlugs: the register slug and the schema slug a slug search names.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Contract
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Contract;

/**
 * The register and schema of a slug search, as the published contract takes them.
 *
 * Both are slugs, resolved in the caller's organisation by
 * {@see ObjectServiceInterface::searchObjectsBySlug()}. The class lives in the
 * published namespace so a consuming app can build it from the contract alone.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */
final class RegisterSchemaSlugs {

	/**
	 * Constructor.
	 *
	 * @param string $register The register slug.
	 * @param string $schema   The schema slug.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function __construct(
		public readonly string $register,
		public readonly string $schema,
	) {
	}//end __construct()
}//end class
