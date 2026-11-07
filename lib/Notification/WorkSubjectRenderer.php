<?php

/**
 * Renders the notifications about day-to-day work in the app.
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
 * Configuration updates, handoffs, mentions, view alerts and forms taken offline.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
class WorkSubjectRenderer extends SubjectRenderer {

	/**
	 * Render a notification about work in the app.
	 *
	 * @param INotification $notification The notification to prepare
	 * @param IL10N $l The localization instance
	 *
	 * @return INotification|null The prepared notification, or null for another subject
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function render(INotification $notification, IL10N $l): ?INotification {
		return match ($notification->getSubject()) {
			'configuration_update_available' => $this->configurationUpdate(notification: $notification, l: $l),
			'handoff_drain_failed' => $this->handoffDrainFailed(notification: $notification, l: $l),
			'timeline_mention' => $this->timelineMention(notification: $notification, l: $l),
			'view_alert_crossed' => $this->viewAlertCrossed(notification: $notification, l: $l),
			'form_unpublished' => $this->formUnpublished(notification: $notification, l: $l),
			default => null,
		};
	}//end render()

	/**
	 * Render "a configuration has a new version".
	 *
	 * @param INotification $notification The notification to prepare
	 * @param IL10N $l The localization instance
	 *
	 * @return INotification The prepared notification
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function configurationUpdate(INotification $notification, IL10N $l): INotification {
		$parameters = $notification->getSubjectParameters();
		$title = $parameters['configurationTitle'] ?? 'Configuration';
		$notification->setParsedSubject($l->t('Configuration update available: %s', [$title]));
		$versions = [$parameters['newVersion'] ?? 'unknown', $title, $parameters['currentVersion'] ?? 'unknown'];
		$notification->setParsedMessage($l->t('A new version (%s) of configuration "%s" is available. Current version: %s', $versions));
		$this->withAppIcon(notification: $notification);

		if (($parameters['configurationId'] ?? null) !== null) {
			$url = $this->urlGenerator->linkToRouteAbsolute(routeName: 'openregister.dashboard.page') . '#/configurations/' . $parameters['configurationId'];
			$notification->addParsedAction($this->primaryAction(notification: $notification, label: $l->t('View'))->setLink(link: $url, requestType: 'GET'));
		}

		return $notification;
	}//end configurationUpdate()

	/**
	 * Render a queued handoff that failed (ADR-051).
	 *
	 * A parked handoff could not execute when a provider appeared: the requester lost
	 * create permission or the mapped object failed target validation. The requester
	 * is informed so the parked work is never silently lost.
	 *
	 * @param INotification $notification The notification to prepare
	 * @param IL10N $l The localization instance
	 *
	 * @return INotification The prepared notification
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Scenario: No provider installed, queue mode)
	 */
	private function handoffDrainFailed(INotification $notification, IL10N $l): INotification {
		$parameters = $notification->getSubjectParameters();
		$handoffId = $parameters['handoffId'] ?? 'handoff';
		$targetKind = $parameters['targetKind'] ?? '';

		$notification->setParsedSubject($l->t('Queued handoff "%s" could not be executed', [$handoffId]));
		$notification->setParsedMessage(
			$l->t(
				'Your queued handoff to "%s" was attempted when a provider became available, but failed: %s',
				[$targetKind, $this->handoffFailureReason(status: $parameters['status'] ?? 'failed', l: $l)]
			)
		);

		return $this->withAppIcon(notification: $notification);
	}//end handoffDrainFailed()

	/**
	 * Why a queued handoff failed.
	 *
	 * @param mixed $status The status of the handoff
	 * @param IL10N $l The localization instance
	 *
	 * @return string The reason
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 */
	private function handoffFailureReason(mixed $status, IL10N $l): string {
		if ($status === 'failed-permission') {
			return $l->t('You no longer have permission to create objects in the providing schema.');
		}

		return $l->t('The target schema rejected the converted object.');
	}//end handoffFailureReason()

	/**
	 * Render "somebody named you in a note".
	 *
	 * @param INotification $notification The notification to prepare
	 * @param IL10N $l The localization instance
	 *
	 * @return INotification The prepared notification
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	private function timelineMention(INotification $notification, IL10N $l): INotification {
		$parameters = $notification->getSubjectParameters();
		$objectTitle = (string) ($parameters['objectTitle'] ?? '');
		$author = (string) ($parameters['author'] ?? '');

		$notification->setParsedSubject($l->t('You were named in a note'));

		$notification->setParsedMessage(
			$l->t('%1$s named you in a note on "%2$s". You now follow it, so you will hear about what happens next.', [$author, $objectTitle])
		);

		if ($author === '') {
			$notification->setParsedMessage(
				$l->t('A note on "%1$s" names you. You now follow it, so you will hear about what happens next.', [$objectTitle])
			);
		}

		return $this->withAppIcon(notification: $notification);
	}//end timelineMention()

	/**
	 * Render "a saved view's count crossed its threshold".
	 *
	 * The sentence says which side of the line the count is on, because a
	 * team lead watching a backlog grow and a planner watching free beds run
	 * out read the same numbers in opposite directions.
	 *
	 * @param INotification $notification The notification to prepare
	 * @param IL10N $l The localization instance
	 *
	 * @return INotification The prepared notification
	 *
	 * @spec openspec/changes/saved-view-count-alert/specs/saved-search-views/spec.md#requirement-a-view-alert-fires-once-per-crossing-and-re-arms
	 */
	private function viewAlertCrossed(INotification $notification, IL10N $l): INotification {
		$parameters = $notification->getSubjectParameters();
		$view = (string)($parameters['view'] ?? '');
		$count = (string)($parameters['count'] ?? '');
		$threshold = (string)($parameters['threshold'] ?? '');

		$notification->setParsedSubject($l->t('%1$s is at %2$s', [$view, $count]));

		$message = $l->t('The view %1$s counts %2$s, at or below its threshold of %3$s.', [$view, $count, $threshold]);
		if (($parameters['operator'] ?? '') === 'gte') {
			$message = $l->t('The view %1$s counts %2$s, at or above its threshold of %3$s.', [$view, $count, $threshold]);
		}

		$notification->setParsedMessage($message);

		return $this->withAppIcon(notification: $notification);
	}//end viewAlertCrossed()

	/**
	 * Render "your form was taken offline" for the author of a form a schema change broke.
	 *
	 * @param INotification $notification The notification to prepare
	 * @param IL10N $l The localization instance
	 *
	 * @return INotification The prepared notification
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-saving-a-schema-must-re-check-the-forms-that-submit-into-it
	 */
	private function formUnpublished(INotification $notification, IL10N $l): INotification {
		$parameters = $notification->getSubjectParameters();
		$title = (string)($parameters['formTitle'] ?? '');
		$schema = (string)($parameters['schema'] ?? '');
		$count = (string)($parameters['count'] ?? '');

		$notification->setParsedSubject($l->t('Your form "%1$s" was taken offline', [$title]));
		$notification->setParsedMessage(
			$l->t('A change to "%1$s" left %2$s problems in the form. Fix them and publish the form again.', [$schema, $count])
		);

		return $this->withAppIcon(notification: $notification);
	}//end formUnpublished()
}//end class
