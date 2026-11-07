<?php

/**
 * Renders the notifications of scheduled reports.
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
 * Tells the owner of a scheduled report how its last run went.
 *
 * @spec openspec/specs/scheduled-report-jobs/spec.md
 */
class ReportSubjectRenderer extends SubjectRenderer {

	/**
	 * Render a scheduled report notification.
	 *
	 * @param INotification $notification The notification to prepare
	 * @param IL10N $l The localization instance
	 *
	 * @return INotification|null The prepared notification, or null for another subject
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 */
	public function render(INotification $notification, IL10N $l): ?INotification {
		return match ($notification->getSubject()) {
			'scheduled_report_delivered' => $this->delivered(notification: $notification, l: $l),
			'scheduled_report_failed' => $this->failed(notification: $notification, l: $l),
			default => null,
		};
	}//end render()

	/**
	 * Render a delivered report (scheduled-report-email-delivery).
	 *
	 * The report went to Nextcloud Files, email, or both, per its `deliveryMode`. This
	 * one subject serves every mode: `mode` and `emailFailureReason` (set when Files
	 * succeeded but the email leg failed, `lastStatus: email_failed`) are optional
	 * parameters.
	 *
	 * @param INotification $notification The notification to prepare
	 * @param IL10N $l The localization instance
	 *
	 * @return INotification The prepared notification
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 */
	private function delivered(INotification $notification, IL10N $l): INotification {
		$parameters = $notification->getSubjectParameters();
		$reportName = $parameters['reportName'] ?? 'Scheduled report';
		$notification->setParsedSubject($l->t('Scheduled report "%s" delivered', [$reportName]));
		$notification->setParsedMessage($this->deliveredMessage(parameters: $parameters, l: $l));
		$this->withAppIcon(notification: $notification);

		if (($parameters['mode'] ?? 'files') !== 'email') {
			$folder = '/' . trim((string)($parameters['folder'] ?? 'Reports/'), '/');
			$filesUrl = $this->urlGenerator->linkToRouteAbsolute(routeName: 'files.view.index') . '?dir=' . rawurlencode($folder);
			$notification->addParsedAction(
				$this->primaryAction(notification: $notification, label: $l->t('Open Files'))->setLink(link: $filesUrl, requestType: 'GET')
			);
		}

		return $notification;
	}//end delivered()

	/**
	 * The message of a delivered scheduled report, by delivery mode, with any email failure noted.
	 *
	 * @param array<string, mixed> $parameters The notification's subject parameters
	 * @param IL10N $l The localization instance
	 *
	 * @return string The message
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 */
	private function deliveredMessage(array $parameters, IL10N $l): string {
		$reportName = $parameters['reportName'] ?? 'Scheduled report';
		$saved = [$reportName, $parameters['folder'] ?? 'Reports/', $parameters['filename'] ?? ''];
		$message = match ($parameters['mode'] ?? 'files') {
			'email' => $l->t('Your scheduled report "%s" ran and was emailed to its recipients.', [$reportName]),
			'both' => $l->t('Your scheduled report "%s" ran, was saved to %s%s, and emailed to its recipients.', $saved),
			default => $l->t('Your scheduled report "%s" ran and was saved to %s%s', $saved),
		};

		$emailFailureReason = $parameters['emailFailureReason'] ?? null;
		if (is_string($emailFailureReason) === true && $emailFailureReason !== '') {
			$message .= ' ' . $l->t('Note: email delivery failed (%s).', [$emailFailureReason]);
		}

		return $message;
	}//end deliveredMessage()

	/**
	 * Render a failed report: the run failed (row cap exceeded or any other error) and is not retried.
	 *
	 * @param INotification $notification The notification to prepare
	 * @param IL10N $l The localization instance
	 *
	 * @return INotification The prepared notification
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 */
	private function failed(INotification $notification, IL10N $l): INotification {
		$parameters = $notification->getSubjectParameters();
		$reportName = $parameters['reportName'] ?? 'Scheduled report';
		$reason = $parameters['reason'] ?? 'Unknown error';

		$notification->setParsedSubject($l->t('Scheduled report "%s" failed', [$reportName]));
		$notification->setParsedMessage($l->t('Your scheduled report "%s" could not be generated: %s', [$reportName, $reason]));

		return $this->withAppIcon(notification: $notification);
	}//end failed()
}//end class
