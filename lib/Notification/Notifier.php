<?php

/**
 * OpenRegister Notification Provider
 *
 * This file contains the notifier class for displaying notifications
 * in the Nextcloud notification center.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Notification
 * @package  OCA\OpenRegister\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Notification;

use InvalidArgumentException;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;

/**
 * Class Notifier
 *
 * Handles the preparation of notifications for display in Nextcloud.
 *
 * @package OCA\OpenRegister\Notification
 */
class Notifier implements INotifier
{
    /**
     * Constructor
     *
     * @param IFactory      $factory      The L10N factory instance
     * @param IURLGenerator $urlGenerator URL generator for notification icons and actions
     */
    public function __construct(
        private readonly IFactory $factory,
        private readonly IURLGenerator $urlGenerator
    ) {
    }//end __construct()

    /**
     * Identifier of the notifier.
     *
     * Only use [a-z0-9_].
     *
     * @return string The notifier ID
     *
     * @psalm-return 'openregister'
     *
     * @spec openspec/changes/retrofit-2026-04-28-notificatie-engine/tasks.md#task-1
     * @spec openspec/changes/retrofit-notificatie-engine-2026-04-28/tasks.md#task-1
     */
    public function getID(): string
    {
        return 'openregister';
    }//end getID()

    /**
     * Human readable name describing the notifier.
     *
     * @return string The notifier name
     */
    public function getName(): string
    {
        return $this->factory->get('openregister')->t('OpenRegister');
    }//end getName()

    /**
     * Prepare notification for display.
     *
     * @param INotification $notification The notification to prepare
     * @param string        $languageCode The language code
     *
     * @return INotification The prepared notification
     * @throws InvalidArgumentException If the notification is not from this app
     *
     * @spec openspec/changes/retrofit-2026-04-28-notificatie-engine/tasks.md#task-1
     * @spec openspec/changes/retrofit-notificatie-engine-2026-04-28/tasks.md#task-1
     */
    public function prepare(INotification $notification, string $languageCode): INotification
    {
        if ($notification->getApp() !== 'openregister') {
            // Not our notification.
            throw new InvalidArgumentException('Unknown app');
        }

        $l = $this->factory->get('openregister', $languageCode);

        switch ($notification->getSubject()) {
            case 'configuration_update_available':
                return $this->prepareConfigurationUpdate(notification: $notification, l: $l);

            default:
<<<<<<< HEAD
                // System entity notifications use a shared template renderer.
                if (str_starts_with(haystack: $notification->getSubject(), needle: 'system_entity_') === true) {
                    return $this->prepareSystemEntityNotification(
                        notification: $notification,
                        l: $l,
                        languageCode: $languageCode
                    );
                }
=======
                // Unknown subject. Object-lifecycle subjects
                // (object_created / object_updated / object_transitioned)
                // are rendered by AnnotationNotifier, not here.
>>>>>>> origin/development
                throw new InvalidArgumentException('Unknown subject');
        }//end switch
    }//end prepare()

<<<<<<< HEAD
    /**
     * Prepare a system entity notification for display.
     *
     * Reads the bilingual subject templates stored in the notification parameters by
     * AnnotationNotificationDispatcher and renders the correct language variant.
     * All system entity notifications are metadata-only: entity title and UUID,
     * never payload contents.
     *
     * @param INotification $notification The notification to prepare.
     * @param mixed         $l            The localisation instance.
     * @param string        $languageCode The language code for the recipient.
     *
     * @return INotification The prepared notification.
     *
     * @spec openspec/changes/openregister-system-notifications/tasks.md#task-4.2
     */
    private function prepareSystemEntityNotification(INotification $notification, $l, string $languageCode): INotification
    {
        $params     = $notification->getSubjectParameters();
        $subjectKey = $languageCode === 'nl' ? 'subject_nl' : 'subject_en';

        $template    = $params[$subjectKey] ?? ($params['subject_en'] ?? '');
        $entityTitle = $params['entityTitle'] ?? '';

        // Interpolate {{title}} / {{name}} placeholders (metadata-only, no payload contents).
        $parsed = str_replace(
            search: ['{{title}}', '{{name}}'],
            replace: [$entityTitle, $entityTitle],
            subject: $template
        );

        $notification->setParsedSubject(subject: $parsed !== '' ? $parsed : $l->t('OpenRegister system notification'));
        $notification->setIcon(
            icon: $this->urlGenerator->imagePath(appName: 'openregister', file: 'app.svg')
        );

        return $notification;
    }//end prepareSystemEntityNotification()

=======
>>>>>>> origin/development
    /**
     * Prepare configuration update notification.
     *
     * @param INotification $notification The notification to prepare
     * @param mixed         $l            The localization instance
     *
     * @return INotification The prepared notification
     *
     * @spec openspec/changes/retrofit-2026-04-28-notificatie-engine/tasks.md#task-1
     * @spec openspec/changes/retrofit-notificatie-engine-2026-04-28/tasks.md#task-1
     */
    private function prepareConfigurationUpdate(INotification $notification, $l): INotification
    {
        $parameters = $notification->getSubjectParameters();

        $configurationTitle = $parameters['configurationTitle'] ?? 'Configuration';
        $currentVersion     = $parameters['currentVersion'] ?? 'unknown';
        $newVersion         = $parameters['newVersion'] ?? 'unknown';

        $notification->setParsedSubject(
            $l->t(text: 'Configuration update available: %s', args: [$configurationTitle])
        );

        $notification->setParsedMessage(
            $l->t(
                text: 'A new version (%s) of configuration "%s" is available. Current version: %s',
                args: [$newVersion, $configurationTitle, $currentVersion]
            )
        );

        $notification->setIcon(
            $this->urlGenerator->imagePath(appName: 'openregister', file: 'app.svg')
        );

        // Add action to view the configuration.
        if (($parameters['configurationId'] ?? null) !== null) {
            $action = $notification->createAction();
            $action->setLabel($l->t(text: 'View'))
                ->setPrimary(true)
                ->setLink(
                    link: $this->urlGenerator->linkToRouteAbsolute(
                        routeName: 'openregister.dashboard.page'
                    ).'#/configurations/'.$parameters['configurationId'],
                    requestType: 'GET'
                );

            $notification->addAction($action);
        }

        return $notification;
    }//end prepareConfigurationUpdate()
}//end class
