<?php

/**
 * Which person object belongs to a user: the field matched and the user it must hold.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md#requirement-a-schema-declares-a-department-by-role-matrix-keyed-on-an-object-field
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * The person-object field matched against a user id, and that user id.
 *
 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md#requirement-a-schema-declares-a-department-by-role-matrix-keyed-on-an-object-field
 */
final class PersonMatch {

	/**
	 * Constructor.
	 *
	 * @param string $field  The property matched against the user id.
	 * @param string $userId The current user.
	 */
	public function __construct(
		public readonly string $field,
		public readonly string $userId,
	) {
	}//end __construct()
}//end class
