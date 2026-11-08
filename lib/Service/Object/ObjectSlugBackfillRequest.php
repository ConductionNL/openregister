<?php

/**
 * OpenRegister ObjectSlugBackfillRequest
 *
 * What one slug backfill run was asked to do.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugScope;

/**
 * The register, schema and mode of one slug backfill run.
 *
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
 */
final class ObjectSlugBackfillRequest {

	/**
	 * Constructor.
	 *
	 * @param Register $register The register.
	 * @param Schema   $schema   The schema.
	 * @param bool     $dryRun   Plan only, write nothing.
	 */
	public function __construct(
		public readonly Register $register,
		public readonly Schema $schema,
		public readonly bool $dryRun,
	) {
	}//end __construct()

	/**
	 * The register and schema as the slug rules take them.
	 *
	 * @return ObjectSlugScope The scope.
	 */
	public function scope(): ObjectSlugScope {
		return new ObjectSlugScope(register: $this->register, schema: $this->schema);
	}//end scope()
}//end class
