<?php

/**
 * AuditAggregationServiceInterface: the part of AuditAggregationService that the audit settings controller uses.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/enhanced-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Audit;

/**
 * The methods of AuditAggregationService that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface AuditAggregationServiceInterface {

	/**
	 * The longest window an administrator may set, in seconds.
	 *
	 * Equal to `AuditSealJob::INTERVAL_SECONDS`. See the class docblock for
	 * why that is the ceiling rather than a round number.
	 *
	 * AuditAggregationService inherits it, so AuditAggregationService::MAXIMUM_WINDOW_SECONDS
	 * still resolves.
	 *
	 * @var integer
	 */
	public const MAXIMUM_WINDOW_SECONDS = 300;

	/**
	 * The administered window, in seconds. Zero means no merging.
	 *
	 * @return integer The window, clamped to the seal interval.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function windowSeconds(): int;

	/**
	 * Store the window, refusing anything outside the range.
	 *
	 * @param integer $seconds The window an administrator asked for.
	 *
	 * @return integer The window that was stored.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function setWindowSeconds(int $seconds): int;
}//end interface
