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
 * Identifiers already written into stored data are FROZEN: the backfill that
 * copies the leaf apps' records in preserves their uuid/slug rather than
 * minting new ones, and lives in a repair step
 * ({@see \OCA\OpenRegister\Repair\ConsolidateLeafOrganisations}) so it can be
 * inspected and re-run independently of the schema change.
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
	 * Add the identity, relationship and repaired tenancy columns.
	 *
	 * @param IOutput $output The migration output.
	 * @param Closure $schemaClosure Returns the schema wrapper.
	 * @param array $options Migration options.
	 *
	 * @return ISchemaWrapper|null The altered schema, or null when nothing changed.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $options is part of the base signature.
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) A column list reads better unrolled than looped.
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

		// ---------------------------------------------------------------
		// Tenancy facet — repairs, not new features. See the class docblock.
		// ---------------------------------------------------------------

		if ($table->hasColumn('groups') === false) {
			$table->addColumn(
				'groups',
				Types::JSON,
				[
					'notnull' => false,
					'comment' => 'Nextcloud group IDs attached to this organisation (RBAC principals, not tenancy — ADR-002 Rule 3)',
				]
			);
			$added[] = 'groups';
		}

		if ($table->hasColumn('storage_quota') === false) {
			$table->addColumn(
				'storage_quota',
				Types::BIGINT,
				['notnull' => false, 'comment' => 'Storage quota in bytes (NULL = unlimited)']
			);
			$added[] = 'storage_quota';
		}

		if ($table->hasColumn('bandwidth_quota') === false) {
			$table->addColumn(
				'bandwidth_quota',
				Types::BIGINT,
				['notnull' => false, 'comment' => 'Bandwidth quota in bytes per month (NULL = unlimited)']
			);
			$added[] = 'bandwidth_quota';
		}

		if ($table->hasColumn('request_quota') === false) {
			$table->addColumn(
				'request_quota',
				Types::INTEGER,
				['notnull' => false, 'comment' => 'API request quota per day (NULL = unlimited)']
			);
			$added[] = 'request_quota';
		}

		// ---------------------------------------------------------------
		// Identity facet — what OpenCatalogi's publisher record carried.
		// These answer the same question `uuid` answers ("which legal entity
		// is this"), which is why they live on the organisation row rather
		// than in a second store keyed differently.
		// ---------------------------------------------------------------

		if ($table->hasColumn('type') === false) {
			$table->addColumn(
				'type',
				Types::STRING,
				[
					'notnull' => false,
					'length' => 64,
					'default' => 'organisation',
					'comment' => 'Discriminator: organisation|government|vendor|collaboration|department',
				]
			);
			$added[] = 'type';
		}

		if ($table->hasColumn('summary') === false) {
			$table->addColumn(
				'summary',
				Types::TEXT,
				['notnull' => false, 'comment' => 'Short summary for overview pages (OpenCatalogi `summary`)']
			);
			$added[] = 'summary';
		}

		if ($table->hasColumn('oin') === false) {
			$table->addColumn(
				'oin',
				Types::STRING,
				['notnull' => false, 'length' => 64, 'comment' => 'Overheidsidentificatienummer']
			);
			$added[] = 'oin';
		}

		if ($table->hasColumn('tooi') === false) {
			$table->addColumn(
				'tooi',
				Types::STRING,
				['notnull' => false, 'length' => 64, 'comment' => 'TOOI identifier for the organisation']
			);
			$added[] = 'tooi';
		}

		if ($table->hasColumn('rsin') === false) {
			$table->addColumn(
				'rsin',
				Types::STRING,
				['notnull' => false, 'length' => 16, 'comment' => 'RSIN (9 digits, 11-proef) of the non-natural person']
			);
			$added[] = 'rsin';
		}

		if ($table->hasColumn('kvk') === false) {
			$table->addColumn(
				'kvk',
				Types::STRING,
				['notnull' => false, 'length' => 16, 'comment' => 'Chamber-of-Commerce (KvK) number']
			);
			$added[] = 'kvk';
		}

		if ($table->hasColumn('pki') === false) {
			$table->addColumn(
				'pki',
				Types::TEXT,
				['notnull' => false, 'comment' => 'PKIoverheid certificate reference']
			);
			$added[] = 'pki';
		}

		if ($table->hasColumn('image') === false) {
			$table->addColumn(
				'image',
				Types::TEXT,
				['notnull' => false, 'comment' => 'Logo/avatar as a URL or base64 data URI']
			);
			$added[] = 'image';
		}

		// ---------------------------------------------------------------
		// Relationship facet — what Stackiq's vendor record carried.
		//
		// `merged_into` is core rather than app-specific because a merge
		// changes WHICH UUID IS AUTHORITATIVE. An organisation that has been
		// merged away must stop resolving as a tenant, or every query scoped
		// to it is a cross-tenant read of the survivor's data.
		// ---------------------------------------------------------------

		if ($table->hasColumn('registration_status') === false) {
			$table->addColumn(
				'registration_status',
				Types::STRING,
				[
					'notnull' => false,
					'length' => 32,
					'comment' => 'Registration lifecycle: concept|submitted|registered|rejected|merged',
				]
			);
			$added[] = 'registration_status';
		}

		if ($table->hasColumn('merged_into') === false) {
			$table->addColumn(
				'merged_into',
				Types::STRING,
				[
					'notnull' => false,
					'length' => 255,
					'comment' => 'UUID of the surviving organisation when this one was merged away',
				]
			);
			$added[] = 'merged_into';
		}

		if ($table->hasColumn('merged_at') === false) {
			$table->addColumn(
				'merged_at',
				Types::DATETIME,
				['notnull' => false, 'comment' => 'When this organisation was merged away']
			);
			$added[] = 'merged_at';
		}

		// Indexes for the lookups the consolidation introduces. `oin` is how a
		// publication resolves its publisher; `merged_into` is walked on every
		// tenant resolution to follow a merge chain to the survivor.
		if ($table->hasIndex('openregister_org_oin_idx') === false) {
			$table->addIndex(['oin'], 'openregister_org_oin_idx');
			$added[] = 'index:oin';
		}

		if ($table->hasIndex('openregister_org_merged_idx') === false) {
			$table->addIndex(['merged_into'], 'openregister_org_merged_idx');
			$added[] = 'index:merged_into';
		}

		if ($added === []) {
			return null;
		}

		$output->info(message: 'Organisation consolidation added: ' . implode(', ', $added));

		return $schema;
	}//end changeSchema()
}//end class
