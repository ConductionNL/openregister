<?php

/**
 * OpenRegister ReadableTrailPageRequest
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

/**
 * One request for a page of the audit trail the caller may read.
 *
 * The operation object ReadableAuditTrailLister::page() builds from its
 * interface signature.
 */
final class ReadableTrailPageRequest {

	/**
	 * Constructor.
	 *
	 * @param string          $userId The caller.
	 * @param int             $limit  The page size, already clamped.
	 * @param int|string|null $cursor The cursor the caller sent, if any.
	 * @param AuditTrailQuery $query  The filters and search of the request.
	 */
	public function __construct(
		public readonly string $userId,
		public readonly int $limit,
		public readonly int|string|null $cursor,
		public readonly AuditTrailQuery $query,
	) {
	}//end __construct()
}//end class
