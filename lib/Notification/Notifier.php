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
 *
 * @spec openspec/changes/retrofit-2026-04-28-notificatie-engine/tasks.md#task-1
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
     */
    public function getID(): string
    {
        return 'openregister';
    }//end getID()

    /**
     * Human readable name describing the notifier.
     *
     * @return string The notifier name
     *
     * @spec openspec/changes/retrofit-2026-04-28-notificatie-engine/tasks.md#task-1
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

            case 'handoff_drain_failed':
                return $this->prepareHandoffDrainFailed(notification: $notification, l: $l);

            case 'scheduled_report_delivered':
                return $this->prepareScheduledReportDelivered(notification: $notification, l: $l);

            case 'scheduled_report_failed':
                return $this->prepareScheduledReportFailed(notification: $notification, l: $l);

            default:
                // Unknown subject. Object-lifecycle subjects
                // (object_created / object_updated / object_transitioned)
                // are rendered by AnnotationNotifier, not here.
                throw new InvalidArgumentException('Unknown subject');
        }//end switch
    }//end prepare()

    /**
     * Prepare configuration update notification.
     *
     * @param INotification $notification The notification to prepare
     * @param mixed         $l            The localization instance
     *
     * @return INotification The prepared notification
     *
     * @spec openspec/changes/retrofit-2026-04-28-notificatie-engine/tasks.md#task-1
     */
    private function prepareConfigurationUpdate(INotification $notification, $l): INotification
    {
        $parameters = $notification->getSubjectParameters();

        $configurationTitle = $parameters['configurationTitle'] ?? 'Configuration';
        $currentVersion     = $parameters['currentVersion'] ?? 'unknown';
        $newVersion         = $parameters['newVersion'] ?? 'unknown';

        $notification->setParsedSubject(
            $l->t('Configuration update available: %s', [$configurationTitle])
        );

        $notification->setParsedMessage(
            $l->t(
                'A new version (%s) of configuration "%s" is available. Current version: %s',
                [$newVersion, $configurationTitle, $currentVersion]
            )
        );

        $notification->setIcon(
            $this->urlGenerator->imagePath(appName: 'openregister', file: 'app.svg')
        );

        // Add action to view the configuration.
        if (($parameters['configurationId'] ?? null) !== null) {
            $action = $notification->createAction();
            $action->setLabel($l->t('View'))
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

    /**
     * Prepare the queue-mode handoff drain-failure notification (ADR-051):
     * a parked handoff could not execute when a provider appeared — the
     * requester lost create permission or the mapped object failed target
     * validation. The requester is informed so the parked work is never
     * silently lost.
     *
     * @param INotification $notification The notification to prepare
     * @param mixed         $l            The localization instance
     *
     * @return INotification The prepared notification
     *
     * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
     *   (Scenario: No provider installed, queue mode)
     */
    private function prepareHandoffDrainFailed(INotification $notification, $l): INotification
    {
        $parameters = $notification->getSubjectParameters();

        $handoffId  = $parameters['handoffId'] ?? 'handoff';
        $targetKind = $parameters['targetKind'] ?? '';
        $status     = $parameters['status'] ?? 'failed';

        $notification->setParsedSubject(
            $l->t('Queued handoff "%s" could not be executed', [$handoffId])
        );

        $reason = $l->t('The target schema rejected the converted object.');
        if ($status === 'failed-permission') {
            $reason = $l->t('You no longer have permission to create objects in the providing schema.');
        }

        $notification->setParsedMessage(
            $l->t(
                'Your queued handoff to "%s" was attempted when a provider became available, but failed: %s',
                [$targetKind, $reason]
            )
        );

        $notification->setIcon(
            $this->urlGenerator->imagePath(appName: 'openregister', file: 'app.svg')
        );

        return $notification;
    }//end prepareHandoffDrainFailed()

    /**
     * Prepare the scheduled-report success notification (scheduled-report-jobs,
     * extended by scheduled-report-email-delivery): a recurring export ran
     * and was delivered to Nextcloud Files, email, or both, per the report's
     * `deliveryMode`. Reuses this single subject for every mode — `mode` and
     * `emailFailureReason` (set when Files succeeded but the email leg
     * failed, i.e. `lastStatus: email_failed`) are optional parameters so no
     * new subject was needed.
     *
     * @param INotification $notification The notification to prepare
     * @param mixed         $l            The localization instance
     *
     * @return INotification The prepared notification
     *
     * @spec openspec/changes/scheduled-report-jobs/specs/scheduled-report-jobs/spec.md
     * @spec openspec/changes/scheduled-report-email-delivery/specs/scheduled-report-jobs/spec.md
     */
    private function prepareScheduledReportDelivered(INotification $notification, $l): INotification
    {
        $parameters = $notification->getSubjectParameters();

        $reportName = $parameters['reportName'] ?? 'Scheduled report';
        $folder     = $parameters['folder'] ?? 'Reports/';
        $filename   = $parameters['filename'] ?? '';
        $mode       = $parameters['mode'] ?? 'files';
        $emailFailureReason = $parameters['emailFailureReason'] ?? null;

        $notification->setParsedSubject(
            $l->t('Scheduled report "%s" delivered', [$reportName])
        );

        $message = match ($mode) {
            'email' => $l->t('Your scheduled report "%s" ran and was emailed to its recipients.', [$reportName]),
            'both'  => $l->t(
                'Your scheduled report "%s" ran, was saved to %s%s, and emailed to its recipients.',
                [$reportName, $folder, $filename]
            ),
            default => $l->t(
                'Your scheduled report "%s" ran and was saved to %s%s',
                [$reportName, $folder, $filename]
            ),
        };

        if (is_string($emailFailureReason) === true && $emailFailureReason !== '') {
            $message .= ' '.$l->t('Note: email delivery failed (%s).', [$emailFailureReason]);
        }

        $notification->setParsedMessage($message);

        $notification->setIcon(
            $this->urlGenerator->imagePath(appName: 'openregister', file: 'app.svg')
        );

        if ($mode !== 'email') {
            $action = $notification->createAction();
            $action->setLabel($l->t('Open Files'))
                ->setPrimary(true)
                ->setLink(
                    link: $this->urlGenerator->linkToRouteAbsolute(
                        routeName: 'files.view.index'
                    ).'?dir='.rawurlencode('/'.trim((string) $folder, '/')),
                    requestType: 'GET'
                );

            $notification->addAction($action);
        }

        return $notification;
    }//end prepareScheduledReportDelivered()

    /**
     * Prepare the scheduled-report failure notification (scheduled-report-jobs):
     * a recurring export failed (row-cap exceeded or any other error) and was
     * not retried automatically.
     *
     * @param INotification $notification The notification to prepare
     * @param mixed         $l            The localization instance
     *
     * @return INotification The prepared notification
     *
     * @spec openspec/changes/scheduled-report-jobs/specs/scheduled-report-jobs/spec.md
     */
    private function prepareScheduledReportFailed(INotification $notification, $l): INotification
    {
        $parameters = $notification->getSubjectParameters();

        $reportName = $parameters['reportName'] ?? 'Scheduled report';
        $reason     = $parameters['reason'] ?? 'Unknown error';

        $notification->setParsedSubject(
            $l->t('Scheduled report "%s" failed', [$reportName])
        );

        $notification->setParsedMessage(
            $l->t('Your scheduled report "%s" could not be generated: %s', [$reportName, $reason])
        );

        $notification->setIcon(
            $this->urlGenerator->imagePath(appName: 'openregister', file: 'app.svg')
        );

        return $notification;
    }//end prepareScheduledReportFailed()
}//end class
