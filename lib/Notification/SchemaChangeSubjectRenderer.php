<?php

/**
 * Renders the notice of a breaking schema change.
 *
 * @category Notification
 * @package  OCA\OpenRegister\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/schema-breaking-change-notice/specs/schema-migration/spec.md#requirement-an-acknowledged-breaking-change-tells-the-people-who-build-on-the-schema
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notification;

use OCP\IL10N;
use OCP\Notification\INotification;

/**
 * `schema_breaking_change`: names the schema and the new version, carries the
 * administrator's note when there is one, and links to the changelog. Rendered
 * in the recipient's language by the IL10N the Notifier passes.
 *
 * @spec openspec/changes/schema-breaking-change-notice/specs/schema-migration/spec.md#requirement-an-acknowledged-breaking-change-tells-the-people-who-build-on-the-schema
 */
class SchemaChangeSubjectRenderer extends SubjectRenderer {

	/**
	 * Render the notification when it is a breaking-change notice.
	 *
	 * @param INotification $notification The notification.
	 * @param IL10N         $l            The recipient's language.
	 *
	 * @return INotification|null The rendered notification, or null when it is another subject.
	 *
	 * @spec openspec/changes/schema-breaking-change-notice/specs/schema-migration/spec.md#requirement-an-acknowledged-breaking-change-tells-the-people-who-build-on-the-schema
	 */
	public function render(INotification $notification, IL10N $l): ?INotification {
		if ($notification->getSubject() !== 'schema_breaking_change') {
			return null;
		}

		$parameters = $notification->getSubjectParameters();
		$schema = (string)($parameters['schema'] ?? '');
		$version = (string)($parameters['version'] ?? '');
		$note = trim((string)($parameters['note'] ?? ''));

		$notification->setParsedSubject($l->t('Schema "%1$s" changed in a way that can break your use of it (version %2$s)', [$schema, $version]));

		$message = $l->t('A breaking change to schema "%1$s" was applied as version %2$s. Check the changelog before your next call.', [$schema, $version]);
		if ($note !== '') {
			$message .= ' ' . $l->t('Note from the administrator: %s', [$note]);
		}

		$notification->setParsedMessage($message);
		$this->withAppIcon(notification: $notification);

		$changelog = $this->urlGenerator->linkToRouteAbsolute(
			routeName: 'openregister.schemaMigration.changelog',
			arguments: ['id' => (int)($parameters['schemaId'] ?? 0)]
		);
		$notification->setLink($changelog);
		$notification->addParsedAction(
			$this->primaryAction(notification: $notification, label: $l->t('Open changelog'))->setLink(link: $changelog, requestType: 'GET')
		);

		return $notification;
	}//end render()
}//end class
