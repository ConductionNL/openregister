<?php

/**
 * ObjectSlugBackfillServiceInterface: what callers outside the Service component ask of ObjectSlugBackfillService.
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

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * The one call the backfill-slugs command makes.
 *
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
 */
interface ObjectSlugBackfillServiceInterface {

	/**
	 * Fill the missing slugs of one register and schema, or plan it.
	 *
	 * @param Register $register The register.
	 * @param Schema   $schema   The schema; must set `objectSlugField`.
	 * @param bool     $dryRun   Plan only, write nothing.
	 *
	 * @return array{
	 *     duplicates: array<string, list<string>>,
	 *     plan: list<array{uuid: string, old: ?string, new: ?string}>,
	 *     summary: array{scanned: int, assigned: int, collisions: int, unchanged: int, duplicates: int, skipped: int}
	 * } What was found and what was (or would be) written.
	 *
	 * @throws InvalidArgumentException When the schema has no `objectSlugField`.
	 * @throws \OCA\OpenRegister\Exception\SlugBackfillWriteException When a write chunk fails (earlier chunks stay written).
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
	 */
	public function run(Register $register, Schema $schema, bool $dryRun=false): array;
}//end interface
