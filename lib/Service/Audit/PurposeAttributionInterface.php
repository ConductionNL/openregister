<?php

/**
 * PurposeAttributionInterface: what callers outside the component use of PurposeAttribution.
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

use OCA\OpenRegister\Db\AuditTrail;

/**
 * Declares only what callers outside the component use of PurposeAttribution; the rest
 * of the class stays its own.
 */
interface PurposeAttributionInterface {

	/**
	 * Stamp the accepted purpose onto a row being built.
	 *
	 * Fail-soft. An audit row is evidence and must survive a bookkeeping
	 * problem; a row with no purpose is honest about what it does not know,
	 * and the guard has already refused the reads that were required to carry
	 * one.
	 *
	 * @param AuditTrail $auditTrail The row being built.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function apply(AuditTrail $auditTrail): void;
}//end interface
