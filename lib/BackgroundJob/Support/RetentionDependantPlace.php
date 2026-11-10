<?php

/**
 * OpenRegister retention dependant place
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob\Support;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * Where the dependants of one source record live: the source they refer to,
 * the property holding that reference, and the register and schema they are
 * stored in.
 *
 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-the-destruction-date-follows-its-source-date-under-every-method
 */
final class RetentionDependantPlace {
	/**
	 * Constructor.
	 *
	 * @param string   $sourceUuid The source record's uuid.
	 * @param string   $relation   The property holding the reference to the source.
	 * @param Register $register   The register the dependants live in.
	 * @param Schema   $schema     Their schema.
	 */
	public function __construct(
		public readonly string $sourceUuid,
		public readonly string $relation,
		public readonly Register $register,
		public readonly Schema $schema,
	) {
	}//end __construct()
}//end class
