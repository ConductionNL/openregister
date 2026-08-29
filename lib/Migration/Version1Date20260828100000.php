<?php

/**
 * Migration consolidating the leaf apps' Organisation onto OpenRegister's.
 *
 * OpenRegister already owns a first-class tenant `Organisation`
 * (ADR-002: the organisation UUID is the ONLY tenant key). What it did not own
 * was the rest of what an organisation IS, so every leaf app grew its own copy:
 * OpenCatalogi a `organization` publisher record (oin/tooi/rsin/pki/image) and
 * Stackiq a vendor/provider record (registrationStatus, merges, participations).
 * Per ADR-022 §3 — "If OR already defines a `contact` or `case` or
 * `organisation` model, an app using those concepts MUST reuse the OR schema" —
 * those copies are the defect, and this migration gives OR the columns that make
 * reuse possible.
 *
 * ## Two live defects repaired here, both silent
 *
 * 1. `groups` HAS NO COLUMN. `Version1Date20250102000000` adds it, guarded by
 *    `hasTable('openregister_organisations') === true` — but that class sorts
 *    FIVE MONTHS BEFORE `Version1Date20250622212509`, which CREATES the table.
 *    Nextcloud runs migrations in class-name order, so the guard is false, the
 *    step succeeds, and the column is never added. On any instance, ever.
 *    Verified against a live database: `UPDATE oc_openregister_organisations
 *    SET groups='[]'` -> `ERROR: column "groups" ... does not exist`.
 *
 * 2. `storage_quota` / `bandwidth_quota` / `request_quota` HAVE NO COLUMN
 *    ANYWHERE. The entity declares all three and `__construct()` calls
 *    `addType()` on them, but the only migration creating those columns
 *    (`Version1Date20251101120000`) targets `openregister_applications`. They
 *    were copy-pasted from `Db/Application`.
 *
 * Both are reachable, not theoretical. `QBMapper::update()` builds its SET list
 * from `getUpdatedFields()`, so a marked field with no column is an SQL error at
 * write time, not a no-op:
 *   - `TenantLifecycleService::provision()` calls `setGroups([...])` — so
 *     provisioning a tenant could never have succeeded.
 *   - `PUT /api/organisations/{uuid}` whitelists `storageQuota`,
 *     `bandwidthQuota`, `requestQuota` and `groups` in
 *     `OrganisationController::applySimpleFieldUpdates()` /
 *     `applyArrayFieldUpdates()` — each a guaranteed 500.
 *
 * Adding the columns is a prerequisite for the consolidation regardless: the
 * consolidated organisation carries groups and quotas as part of its tenant
 * facet.
 *
 * ## Additive only
 *
 * Every column is nullable (or defaulted) and no existing row is rewritten.
 *
 * This migration adds the columns ONLY. It does NOT backfill the leaf apps'
 * records: that needs a ruling on what happens when the same legal entity
 * exists in both OpenCatalogi and Stackiq under different UUIDs, and
 * identifiers already written into stored data are FROZEN, so any backfill
 * must preserve the existing uuid/slug rather than mint new ones. It will land
 * as its own repair step so it can be inspected and re-run independently --
 * see openspec/changes/consolidate-organisation-on-or/tasks.md task 5.1.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/consolidate-organisation-on-or/specs/consolidated-organisation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Gives the tenant organisation its identity and relationship facets.
 *
 * @spec openspec/changes/consolidate-organisation-on-or/specs/consolidated-organisation/spec.md
 */
class Version1Date20260828100000 extends SimpleMigrationStep {

	/**
	 * The table extended here.
	 *
	 * @var string
	 */
	private const TABLE = 'openregister_organisations';

	/**
	 * The columns this migration adds, in the order they are applied.
	 *
	 * Kept as data rather than as a run of `if (hasColumn)` blocks: the list is
	 * pure description, and expressing it as control flow made changeSchema() a
	 * 20-branch method whose every branch was identical in shape. Split by facet
	 * because that is how the consolidation is reasoned about - see the class
	 * docblock.
	 *
	 * @return array<int, array{name: string, type: string, options: array<string, mixed>}> The column specifications.
	 *
	 * @spec openspec/changes/consolidate-organisation-on-or/specs/consolidated-organisation/spec.md
	 */
	private function columnSpecifications(): array {
		return array_merge(
			$this->tenancyColumnSpecifications(),
			$this->identityColumnSpecifications(),
			$this->relationshipColumnSpecifications()
		);
	}//end columnSpecifications()

	/**
	 * Tenancy facet columns - repairs, not new features.
	 *
	 * `groups` and the three quotas are declared by the entity but were never
	 * created by any migration; see the class docblock for how each one escaped.
	 *
	 * @return array<int, array{name: string, type: string, options: array<string, mixed>}> The column specifications.
	 *
	 * @spec openspec/changes/consolidate-organisation-on-or/specs/consolidated-organisation/spec.md
	 */
	private function tenancyColumnSpecifications(): array {
		return [
			[
				'name' => 'groups',
				'type' => Types::JSON,
				'options' => [
					'notnull' => false,
					'comment' => 'Nextcloud group IDs attached to this organisation (RBAC principals, not tenancy - ADR-002 Rule 3)',
				],
			],
			[
				'name' => 'storage_quota',
				'type' => Types::BIGINT,
				'options' => ['notnull' => false, 'comment' => 'Storage quota in bytes (NULL = unlimited)'],
			],
			[
				'name' => 'bandwidth_quota',
				'type' => Types::BIGINT,
				'options' => ['notnull' => false, 'comment' => 'Bandwidth quota in bytes per month (NULL = unlimited)'],
			],
			[
				'name' => 'request_quota',
				'type' => Types::INTEGER,
				'options' => ['notnull' => false, 'comment' => 'API request quota per day (NULL = unlimited)'],
			],
		];
	}//end tenancyColumnSpecifications()

	/**
	 * Identity facet columns - what OpenCatalogi's publisher record carried.
	 *
	 * These answer the same question `uuid` answers ("which legal entity is
	 * this"), which is why they live on the organisation row rather than in a
	 * second store keyed differently.
	 *
	 * @return array<int, array{name: string, type: string, options: array<string, mixed>}> The column specifications.
	 *
	 * @spec openspec/changes/consolidate-organisation-on-or/specs/consolidated-organisation/spec.md
	 */
	private function identityColumnSpecifications(): array {
		return [
			[
				'name' => 'type',
				'type' => Types::STRING,
				'options' => [
					'notnull' => false,
					'length' => 64,
					'default' => 'organisation',
					'comment' => 'Discriminator: organisation|government|vendor|collaboration|department',
				],
			],
			[
				'name' => 'summary',
				'type' => Types::TEXT,
				'options' => ['notnull' => false, 'comment' => 'Short summary for overview pages (OpenCatalogi `summary`)'],
			],
			[
				'name' => 'oin',
				'type' => Types::STRING,
				'options' => ['notnull' => false, 'length' => 64, 'comment' => 'Overheidsidentificatienummer'],
			],
			[
				'name' => 'tooi',
				'type' => Types::STRING,
				'options' => ['notnull' => false, 'length' => 64, 'comment' => 'TOOI identifier for the organisation'],
			],
			[
				'name' => 'rsin',
				'type' => Types::STRING,
				'options' => ['notnull' => false, 'length' => 16, 'comment' => 'RSIN (9 digits, 11-proef) of the non-natural person'],
			],
			[
				'name' => 'kvk',
				'type' => Types::STRING,
				'options' => ['notnull' => false, 'length' => 16, 'comment' => 'Chamber-of-Commerce (KvK) number'],
			],
			[
				'name' => 'pki',
				'type' => Types::TEXT,
				'options' => ['notnull' => false, 'comment' => 'PKIoverheid certificate reference'],
			],
			[
				'name' => 'image',
				'type' => Types::TEXT,
				'options' => ['notnull' => false, 'comment' => 'Logo/avatar as a URL or base64 data URI'],
			],
		];
	}//end identityColumnSpecifications()

	/**
	 * Relationship facet columns - what Stackiq's vendor record carried.
	 *
	 * `merged_into` is core rather than app-specific because a merge changes
	 * WHICH UUID IS AUTHORITATIVE. An organisation that has been merged away must
	 * stop resolving as a tenant, or every query scoped to it is a cross-tenant
	 * read of the survivor's data.
	 *
	 * @return array<int, array{name: string, type: string, options: array<string, mixed>}> The column specifications.
	 *
	 * @spec openspec/changes/consolidate-organisation-on-or/specs/consolidated-organisation/spec.md
	 */
	private function relationshipColumnSpecifications(): array {
		return [
			[
				'name' => 'registration_status',
				'type' => Types::STRING,
				'options' => [
					'notnull' => false,
					'length' => 32,
					'comment' => 'Registration lifecycle: concept|submitted|registered|rejected|merged',
				],
			],
			[
				'name' => 'merged_into',
				'type' => Types::STRING,
				'options' => [
					'notnull' => false,
					'length' => 255,
					'comment' => 'UUID of the surviving organisation when this one was merged away',
				],
			],
			[
				'name' => 'merged_at',
				'type' => Types::DATETIME,
				'options' => ['notnull' => false, 'comment' => 'When this organisation was merged away'],
			],
		];
	}//end relationshipColumnSpecifications()

	/**
	 * The indexes the consolidation introduces.
	 *
	 * `oin` is how a publication resolves its publisher; `merged_into` is walked
	 * on every tenant resolution to follow a merge chain to the survivor.
	 *
	 * @return array<string, array<int, string>> Index name mapped to its columns.
	 *
	 * @spec openspec/changes/consolidate-organisation-on-or/specs/consolidated-organisation/spec.md
	 */
	private function indexSpecifications(): array {
		return [
			'openregister_org_oin_idx' => ['oin'],
			'openregister_org_merged_idx' => ['merged_into'],
		];
	}//end indexSpecifications()

	/**
	 * Add the identity, relationship and repaired tenancy columns.
	 *
	 * @param IOutput $output The migration output.
	 * @param Closure $schemaClosure Returns the schema wrapper.
	 * @param array $options Migration options.
	 *
	 * @return ISchemaWrapper|null The altered schema, or null when nothing changed.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $options is part of the base signature.
	 *
	 * @spec openspec/changes/consolidate-organisation-on-or/specs/consolidated-organisation/spec.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/* @var ISchemaWrapper $schema The schema wrapper. */
		$schema = $schemaClosure();

		// Unlike Version1Date20250102000000, this class sorts AFTER the one that
		// creates the table, so the guard below is the ordinary "already ran"
		// check rather than a permanent skip.
		if ($schema->hasTable(self::TABLE) === false) {
			$output->warning(message: 'openregister_organisations is absent; skipping organisation consolidation columns');
			return null;
		}

		$table = $schema->getTable(self::TABLE);
		$added = [];

		foreach ($this->columnSpecifications() as $column) {
			if ($table->hasColumn($column['name']) === true) {
				continue;
			}

			$table->addColumn($column['name'], $column['type'], $column['options']);
			$added[] = $column['name'];
		}

		foreach ($this->indexSpecifications() as $indexName => $indexColumns) {
			if ($table->hasIndex($indexName) === true) {
				continue;
			}

			$table->addIndex($indexColumns, $indexName);
			$added[] = 'index:' . implode(',', $indexColumns);
		}

		if ($added === []) {
			return null;
		}

		$output->info(message: 'Organisation consolidation added: ' . implode(', ', $added));

		return $schema;
	}//end changeSchema()
}//end class
