<?php

/**
 * What a dispatch writes down about itself.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

use OCA\OpenRegister\Notifications\Db\NotificationDispatchLogMapper;
use OCA\OpenRegister\Notifications\Db\NotificationHistoryMapper;
use OCA\OpenRegister\Service\AutomationRecordWriter;
use Psr\Log\LoggerInterface;

/**
 * The dispatcher's own records: the idempotency claim, the delivery history,
 * rules that reached nobody, and the diagnostics log.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final readonly class DeliveryLedger {
	/**
	 * Constructor.
	 *
	 * @param LoggerInterface                $logger Logger for dispatch diagnostics.
	 * @param ?NotificationHistoryMapper     $historyMapper History mapper for delivery audit rows.
	 * @param ?NotificationDispatchLogMapper $dispatchLogMapper Dispatch-log mapper for idempotency dedup.
	 * @param ?RuleReachRecorder             $reachRecorder Notes rules that reached nobody; built from the logger when absent.
	 * @param ?AutomationRecordWriter        $runRecords Writes one automation run record per evaluation.
	 */
	public function __construct(
		public LoggerInterface $logger,
		public ?NotificationHistoryMapper $historyMapper = null,
		public ?NotificationDispatchLogMapper $dispatchLogMapper = null,
		public ?RuleReachRecorder $reachRecorder = null,
		public ?AutomationRecordWriter $runRecords = null,
	) {
	}//end __construct()
}//end class
