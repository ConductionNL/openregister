<?php

/**
 * OpenRegister Forms App Rows
 *
 * What OpenRegister reads from the NC Forms tables in more than one place:
 * the canonical status of a form row, and its submission count.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/generic-integrations/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCP\IDBConnection;
use Throwable;

/**
 * Status and submission count of an NC Forms form, shared by FormLinkService and FormsProvider.
 *
 * @spec openspec/specs/generic-integrations/spec.md
 */
final class FormsAppRows {

	/**
	 * Map NC Forms' numeric `state` column + expiry timestamp onto
	 * the canonical `open` / `closed` / `draft` / `archived` strings
	 * used by the registry leaf row contract.
	 *
	 * NC Forms `state` values:
	 *   0 -> active (open)
	 *   1 -> archived (closed)
	 *   2 -> draft
	 *
	 * An `expires` at or before now also forces `closed`.
	 *
	 * @param integer $state   NC Forms `state` column.
	 * @param integer $expires NC Forms `expires` epoch (0 = no expiry).
	 *
	 * @return string Canonical status.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public static function status(int $state, int $expires): string {
		if ($state === 1) {
			return 'archived';
		}

		if ($state === 2) {
			return 'draft';
		}

		if ($expires > 0 && $expires <= time()) {
			return 'closed';
		}

		return 'open';
	}//end status()

	/**
	 * Best-effort submission count for a given form id.
	 *
	 * Defensive: returns 0 when the query fails.
	 *
	 * @param IDBConnection $db     The database the Forms tables live in.
	 * @param integer       $formId The NC Forms form id.
	 *
	 * @return integer
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public static function submissionCount(IDBConnection $db, int $formId): int {
		try {
			$qb = $db->getQueryBuilder();
			$qb->select($qb->createFunction('COUNT(*)'))
				->from('forms_v2_submissions')
				->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId)));
			$result = $qb->executeQuery();
			$count = (int)$result->fetchOne();
			$result->closeCursor();
			return $count;
		} catch (Throwable $e) {
			return 0;
		}
	}//end submissionCount()
}//end class
