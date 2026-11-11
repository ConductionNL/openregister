<?php

/**
 * ReadableAuditTrailListerInterface: what callers outside the component use of ReadableAuditTrailLister.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
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

namespace OCA\OpenRegister\Service\Audit;

use OCA\OpenRegister\Db\AuditTrailMapper;

/**
 * The readable audit-trail page the audit trail endpoints list.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface ReadableAuditTrailListerInterface {

	/**
	 * One page of the audit trail, as far as this caller may read.
	 *
	 * The cursor is an offset into the RAW trail, not into the filtered
	 * result, because the filter is decided per row and not in SQL. That
	 * offset counts rows the caller may not read, so it is handed out sealed
	 * to the caller, filters and search it was walked under, no client-chosen
	 * offset is accepted, and no count of the walked rows is returned: a
	 * client hands back the `nextCursor` it was given and is never told how
	 * many rows were skipped to fill its page.
	 *
	 * @param string|null      $userId  The caller, or null when anonymous.
	 * @param TrailPageOptions $options The page size, a sealed cursor this list handed out under the same caller,
	 *                                  filters and search, and the filters and search from {@see query()}.
	 *
	 * @return array{results: array<int, array>, limit: int, cursor: int|string|null, nextCursor: string|null}
	 *     The page. `nextCursor` is null when the trail was exhausted, and a
	 *     sealed cursor when there may be more, including when the scan budget
	 *     ran out before the page filled.
	 *
	 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-is-readable-within-a-callers-own-scope
	 */
	public function page(?string $userId, TrailPageOptions $options=new TrailPageOptions()): array;

	/**
	 * The query a page is walked under.
	 *
	 * @param array       $filters Column filters, as `AuditTrailMapper::findAll()` takes them.
	 * @param string|null $search  Optional free-text term.
	 *
	 * @return AuditTrailQuery The query, to hand to {@see page()}.
	 *
	 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-is-readable-within-a-callers-own-scope
	 */
	public function query(array $filters = [], ?string $search = null): AuditTrailQuery;
}//end interface
