<?php

/**
 * HistoryEntry: one row for the notification history.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Notifications\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Db;

/**
 * One notification history row: the delivery, its target, its notice and, when it is not
 * the object, what it is about.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class HistoryEntry {

	/**
	 * Constructor.
	 *
	 * @param HistoryDelivery     $delivery The rule, channel, recipient and status.
	 * @param HistoryTarget       $target   The object and the firing.
	 * @param HistoryNotice       $notice   The subject, the error message and the locale.
	 * @param HistorySubject|null $about    What the notice is about; null means the target object.
	 */
	public function __construct(
		public readonly HistoryDelivery $delivery,
		public readonly HistoryTarget $target,
		public readonly HistoryNotice $notice,
		public readonly ?HistorySubject $about = null,
	) {
	}//end __construct()
}//end class
