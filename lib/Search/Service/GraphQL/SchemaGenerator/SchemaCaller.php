<?php

/**
 * Who a generated GraphQL schema is for.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Search\Service\GraphQL\SchemaGenerator
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 *
 * @spec openspec/changes/schema-shape-exposure/specs/rbac-scopes/spec.md
 */

namespace OCA\OpenRegister\Search\Service\GraphQL\SchemaGenerator;

use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCP\IUserSession;

/**
 * The session of the caller and the rule that decides which properties they may read.
 */
final class SchemaCaller {

	/**
	 * Constructor.
	 *
	 * @param IUserSession $session The session that says who is asking
	 * @param PropertyRbacHandler $propertyRbac Decides which properties that caller may read
	 */
	public function __construct(
		public readonly IUserSession $session,
		public readonly PropertyRbacHandler $propertyRbac,
	) {
	}//end __construct()
}//end class
