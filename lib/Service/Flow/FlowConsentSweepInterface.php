<?php

/**
 * The consent sweep: frees or fails runs parked awaiting a delegation answer.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/delegation-grants/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use DateTime;

/**
 * Settles every parked run whose delegation has been answered.
 *
 * The capability the run worker needs from consent parking, so the cron job
 * names a contract rather than the parking service and its delegation store.
 *
 * @spec openspec/specs/delegation-grants/spec.md
 */
interface FlowConsentSweepInterface {

	/**
	 * How long a parked run waits before it is failed, in hours.
	 *
	 * Generous on purpose. The failure mode being avoided is failing work while
	 * its prompt is unread, and a person who has not answered by the next working
	 * day has effectively declined — but a person who has not answered in an hour
	 * is at lunch. Bounded all the same: no run may wait forever, or "waiting for
	 * consent" becomes indistinguishable from "lost".
	 *
	 * @var integer
	 */
	public const DEFAULT_WAIT_HOURS = 72;

	/**
	 * Release or fail every parked run whose answer has arrived.
	 *
	 * @param DateTime $now       The moment to judge liveness and waiting at.
	 * @param integer  $waitHours How long a run may wait before it is failed.
	 *
	 * @return array{released: int, failed: int} What the pass did.
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function sweep(DateTime $now, int $waitHours = self::DEFAULT_WAIT_HOURS): array;
}//end interface
