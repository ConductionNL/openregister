<?php

/**
 * One register lookup: the identifier and the two filter flags.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * What RegisterMapper::find() was asked: which register, and under which filters.
 *
 * Only built from find()'s own typed parameters, so every value is already of
 * its declared type when it arrives here.
 *
 * @spec openspec/specs/register-slug-resolution/spec.md
 */
final readonly class RegisterLookup {
	/**
	 * Constructor.
	 *
	 * @param string|int $id           The register id, uuid or slug.
	 * @param bool       $rbac         Whether RBAC filtering was asked for.
	 * @param bool       $multitenancy Whether the organisation filter applies.
	 *
	 * @return void
	 */
	public function __construct(
		public string|int $id,
		public bool $rbac,
		public bool $multitenancy,
	) {
	}//end __construct()
}//end class
