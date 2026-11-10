<?php

/**
 * What a caller asks of the audit trail: its column filters and its free-text term.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md
 */


declare(strict_types=1);

namespace OCA\OpenRegister\Service\Audit;

/**
 * The filters and the search a page of the readable audit trail is walked under.
 *
 * A sealed cursor is bound to exactly this pair: a cursor handed out under one
 * query does not resume a walk under another. Built by
 * {@see ReadableAuditTrailListerInterface::query()}, so callers outside this
 * component never name it.
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-is-readable-within-a-callers-own-scope
 */
final class AuditTrailQuery {
	/**
	 * Hold the query.
	 *
	 * @param array       $filters Column filters, as `AuditTrailMapper::findAll()` takes them.
	 * @param string|null $search  Optional free-text term.
	 *
	 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-is-readable-within-a-callers-own-scope
	 */
	public function __construct(
		public readonly array $filters = [],
		public readonly ?string $search = null,
	) {
	}//end __construct()
}//end class
