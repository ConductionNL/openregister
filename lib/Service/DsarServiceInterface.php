<?php

/**
 * DsarServiceInterface: what callers outside the component use of DsarService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Archival\ArchivalRetentionGuard;

/**
 * The DSAR object lookups, erasure and rectification the DSAR endpoints call.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface DsarServiceInterface {

	/**
	 * Soft-delete every object matching a subject (Art 17 vergetelheid).
	 *
	 * The deletion itself is audit-logged for legal defence (the
	 * verwerkingsactiviteit configured under `dsar_processing_activity`
	 * provides the legal basis for keeping a deletion record); the
	 * object's personal data is removed from active records by setting
	 * the `deleted` metadata column.
	 *
	 * Returns a summary envelope:
	 *
	 *   [
	 *     'subject'      => '<echo>',
	 *     'type'         => '<echo|null>',
	 *     'dryRun'       => bool,
	 *     'matchedCount' => int,
	 *     'erased'       => [
	 *       ['uuid' => '<object-uuid>', 'register' => '<...>', 'schema' => '<...>'],
	 *       ...
	 *     ],
	 *     'withheld'     => [
	 *       ['uuid' => '...', 'schema' => '...', 'ground' => 'ARCHIVAL_RETENTION_OBLIGATION',
	 *        'message' => '...', 'basis' => '...', 'action' => '...'],
	 *       ...
	 *     ],
	 *     'withheldCount' => <int>,
	 *   ]
	 *
	 * RETENTION WINS OVER ERASURE. A record on a schema declaring
	 * `x-openregister-archival` is kept under a legal obligation, and art-17(3)(b)
	 * stands down for it. Those rows are refused, recorded and reported in
	 * `withheld` with the ground and the wording to pass back to the data subject.
	 * The refusal is decided by
	 * {@see \OCA\OpenRegister\Service\Archival\ArchivalRetentionGuard}, which asks
	 * {@see \OCA\OpenRegister\Db\Schema::hasArchivalAnnotation()}, the same single
	 * definition the four HTTP delete doors already use.
	 *
	 * Withholding one record never blocks the rest: every non-archival match in
	 * the same request is still erased. A run that withheld anything reports
	 * `complete: false`, because the data is still there.
	 *
	 * @param string $subject Subject identifier.
	 * @param string|null $type Optional GdprEntity type filter.
	 * @param bool $dryRun When true, returns matches without erasing.
	 *
	 * @return array<string, mixed>
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-11
	 */
	public function eraseObjectsForSubject(string $subject, ?string $type = null, bool $dryRun = false): array;

	/**
	 * Find every object that contains personal data for the given subject.
	 *
	 * Walks `oc_openregister_entities` for rows whose `value` matches
	 * the subject (case-insensitive exact match by default; pass
	 * `mode='ilike'` for substring), then joins
	 * `oc_openregister_entity_relations.object_id` to dedupe and load
	 * the owning ObjectEntity rows.
	 *
	 * Returns a list of envelopes:
	 *
	 *   [
	 *     [
	 *       'object'        => <ObjectEntity::jsonSerialize() output>,
	 *       'gdprEntities'  => [{type, value, category, detectedAt}, ...]
	 *     ],
	 *     ...
	 *   ]
	 *
	 * @param string $subject Subject identifier value (email, BSN, …).
	 * @param string|null $type Optional GdprEntity type filter (e.g. `email`).
	 * @param string $mode `exact` (default) or `ilike`.
	 *
	 * @return array<int, array{object: array, gdprEntities: array}>
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-11
	 */
	public function findObjectsForSubject(string $subject, ?string $type = null, string $mode = 'exact'): array;

	/**
	 * Update a single object with DSAR attribution (Art 16 rectificatie).
	 *
	 * Thin wrapper around the existing object update path that pins
	 * the audit row to the configured DSAR activity. Use this when a
	 * data subject requests correction of specific fields rather than
	 * a wholesale erasure.
	 *
	 * @param int $objectId Internal id of the object.
	 * @param array<string, mixed> $changes Property → new value map.
	 *
	 * @return array<string, mixed>|null Updated object envelope or null on miss.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-12
	 */
	public function rectifyObjectForSubject(int $objectId, array $changes): ?array;
}//end interface
