<?php

/**
 * Renders the request alert notifications of the observability thresholds.
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
 *
 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-alerting-threshold-configuration
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notification;

use OCA\OpenRegister\Service\Observability\RequestAlertEvaluator;
use OCP\IL10N;
use OCP\Notification\INotification;

/**
 * Tells an administrator that the error rate or the p95 response time crossed its threshold.
 *
 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-alerting-threshold-configuration
 */
class ObservabilitySubjectRenderer extends SubjectRenderer {

	/**
	 * Render a request alert notification.
	 *
	 * @param INotification $notification The notification to prepare
	 * @param IL10N         $l            The localization instance
	 *
	 * @return INotification|null The prepared notification, or null for another subject
	 *
	 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-alerting-threshold-configuration
	 */
	public function render(INotification $notification, IL10N $l): ?INotification {
		$parameters = $notification->getSubjectParameters();
		$minutes = (string) ($parameters['windowMinutes'] ?? 5);

		if ($notification->getSubject() === RequestAlertEvaluator::SUBJECT_ERROR_RATE) {
			$notification->setParsedSubject($l->t('OpenRegister error rate is over its alert threshold'));
			$notification->setParsedMessage(
				$l->t(
					'%1$s%% of the requests in the last %2$s minutes failed with a server error; the threshold is %3$s%%.',
					[(string) ($parameters['ratePercent'] ?? '?'), $minutes, (string) ($parameters['thresholdPercent'] ?? '?')]
				)
			);
			return $this->withAppIcon(notification: $notification);
		}

		if ($notification->getSubject() === RequestAlertEvaluator::SUBJECT_RESPONSE_TIME) {
			$notification->setParsedSubject($l->t('OpenRegister response time is over its alert threshold'));
			$notification->setParsedMessage(
				$l->t(
					'The 95th percentile response time in the last %1$s minutes was %2$s ms; the threshold is %3$s ms.',
					[$minutes, (string) ($parameters['p95Ms'] ?? '?'), (string) ($parameters['thresholdMs'] ?? '?')]
				)
			);
			return $this->withAppIcon(notification: $notification);
		}

		return null;
	}//end render()
}//end class
