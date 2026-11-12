<?php

/**
 * PlannedAccountsCheck: a schema check that accepts account ids which do not exist yet.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\Schema;
use stdClass;

/**
 * The schema an object is validated against and the account ids a `user` format accepts for that one call.
 *
 * No strict_types, like ValidateObject, which receives these values.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-check-validates-every-object-against-its-real-schema-without-writing-req-exs-004
 */
final class PlannedAccountsCheck {

	/**
	 * Constructor.
	 *
	 * @param Schema|int|null    $schema       The schema or schema ID to validate against.
	 * @param object             $schemaObject A custom schema object for validation.
	 * @param array<int, string> $plannedUsers Account ids to accept although they do not exist yet.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-check-validates-every-object-against-its-real-schema-without-writing-req-exs-004
	 */
	public function __construct(
		public readonly Schema|int|null $schema = null,
		public readonly object $schemaObject = new stdClass(),
		public readonly array $plannedUsers = [],
	) {
	}//end __construct()
}//end class
