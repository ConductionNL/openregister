<?php

/**
 * Renders the notifications of retention sweeps and destruction lists.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Notification
 * @package  OCA\OpenRegister\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Notification;

use OCP\IL10N;
use OCP\Notification\INotification;

/**
 * The legal hold and review notifications of the archiving process.
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */
class ArchivalSubjectRenderer extends SubjectRenderer {

	/**
	 * Render a retention or destruction notification.
	 *
	 * @param INotification $notification The notification to prepare
	 * @param IL10N $l The localization instance
	 *
	 * @return INotification|null The prepared notification, or null for another subject
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function render(INotification $notification, IL10N $l): ?INotification {
		return match ($notification->getSubject()) {
			'retention_holds_skipped' => $this->retentionHoldsSkipped(notification: $notification, l: $l),
			'destruction_holds_skipped' => $this->destructionHoldsSkipped(notification: $notification, l: $l),
			'destruction_review_pending' => $this->destructionReviewPending(notification: $notification, l: $l),
			default => null,
		};
	}//end render()

	/**
	 * Render "the retention sweep kept records that are under a legal hold".
	 *
	 * @param INotification $notification The notification to prepare
	 * @param IL10N $l The localization instance
	 *
	 * @return INotification The prepared notification
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	private function retentionHoldsSkipped(INotification $notification, IL10N $l): INotification {
		$notification->setParsedSubject($l->t('Retention sweep kept records that are under a legal hold'));
		$notification->setParsedMessage($this->retentionHoldsMessage(parameters: $notification->getSubjectParameters(), l: $l));

		return $this->withAppIcon(notification: $notification);
	}//end retentionHoldsSkipped()

	/**
	 * What the retention sweep left in place, and what the reader can do about it.
	 *
	 * @param array<string, mixed> $parameters The notification's subject parameters
	 * @param IL10N $l The localization instance
	 *
	 * @return string The message
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	private function retentionHoldsMessage(array $parameters, IL10N $l): string {
		$skippedCount = (int)($parameters['skippedCount'] ?? 0);

		return $l->n(
			'The retention sweep on "%2$s" reached %1$d record past its retention period and left '
			. 'it in place, because a legal hold is on it. Release the hold when the case it '
			. 'belongs to is closed, and the next sweep will destroy it.',
			'The retention sweep on "%2$s" reached %1$d records past their retention period and '
			. 'left them in place, because a legal hold is on them. Release the holds when the '
			. 'cases they belong to are closed, and the next sweep will destroy them.',
			$skippedCount,
			[$skippedCount, (string)($parameters['schemaSlug'] ?? 'unknown')]
		);
	}//end retentionHoldsMessage()

	/**
	 * Render "the destruction list skipped records that are under a legal hold".
	 *
	 * @param INotification $notification The notification to prepare
	 * @param IL10N $l The localization instance
	 *
	 * @return INotification The prepared notification
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	private function destructionHoldsSkipped(INotification $notification, IL10N $l): INotification {
		$notification->setParsedSubject($l->t('Destruction list kept records that are under a legal hold'));
		$notification->setParsedMessage($this->destructionHoldsMessage(parameters: $notification->getSubjectParameters(), l: $l));

		return $this->withAppIcon(notification: $notification);
	}//end destructionHoldsSkipped()

	/**
	 * What the destruction list left in place, and what the reader can do about it.
	 *
	 * @param array<string, mixed> $parameters The notification's subject parameters
	 * @param IL10N $l The localization instance
	 *
	 * @return string The message
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	private function destructionHoldsMessage(array $parameters, IL10N $l): string {
		$skippedCount = (int)($parameters['skippedCount'] ?? 0);

		return $l->n(
			'%1$d record on destruction list %2$s was not destroyed, because a legal hold is on '
			. 'it. Release the hold and run the list again, or take the record off the list.',
			'%1$d records on destruction list %2$s were not destroyed, because a legal hold is on '
			. 'them. Release the holds and run the list again, or take the records off the list.',
			$skippedCount,
			[$skippedCount, (string)($parameters['listUuid'] ?? 'unknown')]
		);
	}//end destructionHoldsMessage()

	/**
	 * Render "records are waiting on your decision".
	 *
	 * The reminder the review process rests on: a destruction list entry with a
	 * named reviewer and nobody asking is an entry that waits forever.
	 *
	 * @param INotification $notification The notification to prepare
	 * @param IL10N $l The localization instance
	 *
	 * @return INotification The prepared notification
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	private function destructionReviewPending(INotification $notification, IL10N $l): INotification {
		$notification->setParsedSubject($l->t('Records are waiting on your archiving decision'));
		$notification->setParsedMessage(
			$l->n(
				'%n record on a destruction list is assigned to you and has not been answered yet. '
				. 'Each one is destroyed, kept for longer, or transferred to an e-Depot, and the '
				. 'answer is recorded against your name.',
				'%n records on destruction lists are assigned to you and have not been answered yet. '
				. 'Each one is destroyed, kept for longer, or transferred to an e-Depot, and the '
				. 'answer is recorded against your name.',
				(int)($notification->getSubjectParameters()['pendingCount'] ?? 0)
			)
		);

		return $this->withAppIcon(notification: $notification);
	}//end destructionReviewPending()
}//end class
