<?php

/**
 * OpenRegister AnnotationNotifier
 *
 * Renders annotation-driven notifications. The dispatcher stores the
 * already-interpolated subject under the `_text` parameter; this notifier
 * surfaces it as the notification's parsed subject.
 *
 * @category Notification
 * @package  OCA\OpenRegister\Notification
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

namespace OCA\OpenRegister\Notification;

use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

class AnnotationNotifier implements INotifier
{
    /**
     * No-op constructor, kept explicit so DI can resolve the notifier.
     *
     * @return void
     */
    public function __construct()
    {
    }//end __construct()

    /**
     * Return the unique identifier for this notifier.
     *
     * @return string Notifier identifier consumed by Nextcloud.
     */
    public function getID(): string
    {
        return 'openregister';
    }//end getID()

    /**
     * Return the human-readable notifier name.
     *
     * @return string Notifier display name.
     */
    public function getName(): string
    {
        return 'OpenRegister';
    }//end getName()

    /**
     * Render the notification subject for the given language.
     *
     * @param INotification $notification Notification to prepare.
     * @param string        $languageCode Active language code.
     *
     * @return INotification Prepared notification.
     *
     * @throws UnknownNotificationException When the notification does not belong to OpenRegister.
     */
    public function prepare(INotification $notification, string $languageCode): INotification
    {
        if ($notification->getApp() !== 'openregister') {
            throw new UnknownNotificationException();
        }

        $params = $notification->getSubjectParameters();
        $text   = ($params['_text'] ?? null);

        if (is_string($text) === true && $text !== '') {
            $notification->setParsedSubject($text);
        } else {
            $notification->setParsedSubject($notification->getSubject());
        }

        return $notification;
    }//end prepare()
}//end class
