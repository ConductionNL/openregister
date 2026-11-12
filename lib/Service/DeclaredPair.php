<?php

/**
 * Declared Pair
 *
 * The register and schema a read touches, each one a row a shared master
 * data declaration may be made on.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/saas-multi-tenant/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * The register id and schema id {@see SharedMasterDataService::holdersForResource()} asks about;
 * either may be unknown, and an unknown one asks nothing.
 *
 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/saas-multi-tenant/spec.md
 */
final class DeclaredPair {

	/**
	 * Constructor.
	 *
	 * @param integer|null $registerId The register being read, when known.
	 * @param integer|null $schemaId   The schema being read, when known.
	 */
	public function __construct(
		public readonly ?int $registerId,
		public readonly ?int $schemaId,
	) {
	}//end __construct()
}//end class
