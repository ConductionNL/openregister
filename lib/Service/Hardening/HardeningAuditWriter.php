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

use OCA\OpenRegister\Db\AuditTrail\HardeningChange as HardeningAuditChange;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
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
	 * @param AuditTrailMapperInterface $auditTrailMapper The hash-chained trail.
	 * @param LoggerInterface  $logger           Records a row that could not be written.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly AuditTrailMapperInterface $auditTrailMapper,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Record a hardening fact that was allowed to happen.
	 *
	 * @param HardeningFact $change What happened, as `area.event`, and the states before and after.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md
	 */
	public function accepted(HardeningFact $change): void {
		$this->write(change: $change, refusal: null);
	}//end accepted()

	/**
	 * Record a hardening fact that was refused.
	 *
	 * @param HardeningFact $change  What was asked, as `area.event`, the state before and the state asked for.
	 * @param string        $refusal The refusal.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md
	 */
	public function refused(HardeningFact $change, string $refusal): void {
		$this->write(change: $change, refusal: $refusal);
	}//end refused()

	/**
	 * Write one entry; a failure is logged and never undoes what happened.
	 *
	 * @param HardeningFact $change  The fact and the states around it.
	 * @param string|null   $refusal The refusal, or null when the fact was allowed.
	 *
	 * @return void
	 */
	private function write(HardeningFact $change, ?string $refusal): void {
		try {
			$this->auditTrailMapper->recordHardeningChange(
				change: new HardeningAuditChange(control: $change->fact, before: $change->before, after: $change->after, refusal: ($refusal ?? '')),
				accepted: $refusal === null
			);
		} catch (Throwable $failure) {
			$this->logger->error(
				'HardeningAuditWriter: the audit entry for ' . $change->fact . ' could not be written: ' . $failure->getMessage()
			);
		}
	}//end write()
}//end class
