<?php

/**
 * The one writer of the hardening facts on the audit trail.
 *
 * WHY A SEPARATE CLASS. {@see \OCA\OpenRegister\Db\AuditTrailMapper} already
 * carries more methods than the analyser allows, and every control in this
 * change writes a row. Adding eight more reads and writes to the mapper would
 * grow a class that is already over its limit, so the mapper keeps the one
 * generic hardening row and this class names the facts that use it.
 *
 * 🔑 THE WRITE NEVER DECIDES ANYTHING. A refusal has already happened by the
 * time a row is written, so a broken audit chain must not turn a refusal into a
 * 500 that a reader takes for success. Every write here is logged and
 * swallowed, exactly as {@see HardeningSettingsService} does.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Hardening
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Hardening;

use OCA\OpenRegister\Db\AuditTrailMapper;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Records what the hardening controls did, and what they refused.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Hardening
 */
class HardeningAuditWriter {

	/**
	 * Constructor.
	 *
	 * @param AuditTrailMapper $auditTrailMapper The hash-chained trail.
	 * @param LoggerInterface  $logger           Records a row that could not be written.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly AuditTrailMapper $auditTrailMapper,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Record one hardening fact.
	 *
	 * @param string                 $fact     What happened, as `area.event`.
	 * @param int|string|array       $before   The state before.
	 * @param int|string|array       $after    The state after, or asked for.
	 * @param bool                   $accepted Whether it was allowed to happen.
	 * @param string                 $refusal  The refusal, when there was one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md
	 */
	public function record(
		string $fact,
		int|string|array $before,
		int|string|array $after,
		bool $accepted,
		string $refusal = '',
	): void {
		try {
			$this->auditTrailMapper->createHardeningChangeEntry(
				control: $fact,
				before: $before,
				after: $after,
				accepted: $accepted,
				refusal: $refusal,
			);
		} catch (Throwable $failure) {
			$this->logger->error(
				'HardeningAuditWriter: the audit entry for ' . $fact . ' could not be written: ' . $failure->getMessage()
			);
		}

	}//end record()
}//end class
