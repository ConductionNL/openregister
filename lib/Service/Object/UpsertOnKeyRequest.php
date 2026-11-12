<?php

/**
 * OpenRegister UpsertOnKeyRequest
 *
 * The key's name, the body and the register and schema of one upsert on a
 * declared key.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/api-upsert-on-a-declared-key/specs/objects-crud/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * What one upsert on a declared key was asked to do.
 *
 * @spec openspec/changes/api-upsert-on-a-declared-key/specs/objects-crud/spec.md
 */
final class UpsertOnKeyRequest {

	/**
	 * Constructor.
	 *
	 * @param string               $constraintName The `_upsertOn` value: a refuse constraint's name.
	 * @param array<string, mixed> $object         The request body, control keys stripped.
	 * @param Register             $register       The register.
	 * @param Schema               $schema         The schema.
	 */
	public function __construct(
		public readonly string $constraintName,
		public readonly array $object,
		public readonly Register $register,
		public readonly Schema $schema,
	) {
	}//end __construct()
}//end class
