<?php

/**
 * Where a queued audit trail export saves its file and who it tells
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/audit-log-page/specs/audit-trail-immutable/spec.md#requirement-the-filtered-audit-list-exports-with-its-hash-chain
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCP\Files\IRootFolder;
use OCP\Notification\IManager as INotificationManager;

/**
 * The requester's Files and the notification manager a queued audit trail
 * export delivers through.
 *
 * @spec openspec/changes/audit-log-page/specs/audit-trail-immutable/spec.md#requirement-the-filtered-audit-list-exports-with-its-hash-chain
 */
final class AuditExportDelivery {
	/**
	 * Bundle the services.
	 *
	 * @param IRootFolder          $rootFolder    The requester's Files.
	 * @param INotificationManager $notifications Tells the requester.
	 */
	public function __construct(
		public readonly IRootFolder $rootFolder,
		public readonly INotificationManager $notifications,
	) {
	}//end __construct()
}//end class
