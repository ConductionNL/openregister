<?php

/**
 * OpenRegister ObjectSlugChangeSet
 *
 * The slug changes of one backfill run (or one chunk of it) with the table
 * they belong to and the run's counters.
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

/**
 * Slug changes for one register+schema table, with the run's counters.
 *
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
 */
final class ObjectSlugChangeSet {

	/**
	 * Constructor.
	 *
	 * @param Register                                              $register The register.
	 * @param Schema                                                $schema   The schema.
	 * @param list<array{uuid: string, old: ?string, new: ?string}> $changes  The slug changes.
	 * @param array<string, int>                                    $summary  The run's counters.
	 */
	public function __construct(
		public readonly Register $register,
		public readonly Schema $schema,
		public readonly array $changes,
		public readonly array $summary,
	) {
	}//end __construct()

	/**
	 * The same table and counters with other changes.
	 *
	 * @param list<array{uuid: string, old: ?string, new: ?string}> $changes The changes.
	 *
	 * @return self The new set.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
	 */
	public function withChanges(array $changes): self {
		return new self(register: $this->register, schema: $this->schema, changes: $changes, summary: $this->summary);
	}//end withChanges()

	/**
	 * The same table and changes with counters merged over the current ones.
	 *
	 * @param array<string, int> $counters The counters to merge in.
	 *
	 * @return self The new set.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
	 */
	public function withCounters(array $counters): self {
		return new self(register: $this->register, schema: $this->schema, changes: $this->changes, summary: array_merge($this->summary, $counters));
	}//end withCounters()
}//end class
