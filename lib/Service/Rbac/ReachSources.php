<?php

/**
 * Where a principal's reach is read from: schemas, derived grants and delegations.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\DelegationGrantMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;

/**
 * The stores a principal's reach is read from and revoked in.
 *
 * A constructor bundle, autowired by Nextcloud: it carries no behaviour.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class ReachSources {

	/**
	 * Bundle the collaborators.
	 *
	 * @param SchemaMapperInterface $schemaMapper Reads the schemas whose rules grant reach.
	 * @param DerivedGrantStore     $derived      The derived grants.
	 * @param DelegationGrantMapperInterface $delegations  The delegation grants.
	 */
	public function __construct(
		public readonly SchemaMapperInterface $schemaMapper,
		public readonly DerivedGrantStore $derived,
		public readonly DelegationGrantMapperInterface $delegations,
	) {
	}//end __construct()
}//end class
