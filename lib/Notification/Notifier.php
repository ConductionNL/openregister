<?php
/**
 * OpenRegister Notification Provider
 *
 * This file contains the notifier class for displaying notifications
 * in the Nextcloud notification center.
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
     * L10N factory for translation.
     *
     * @var IFactory The L10N factory instance.
     */
    private IFactory $factory;


    /**
     * Constructor
     *
     * @param IFactory $factory The L10N factory instance
     */
    public function __construct(IFactory $factory)
    {
        $this->factory = $factory;

    }//end __construct()


    /**
     * Identifier of the notifier.
     *
     * Only use [a-z0-9_].
     *
     * @return string The notifier ID
     *
     * @psalm-return 'openregister'
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
                // Unknown subject.
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
            \OC::$server->getURLGenerator()->imagePath(app: 'openregister', file: 'app.svg')
        );

        // Add action to view the configuration.
        if (($parameters['configurationId'] ?? null) !== null) {
            $action = $notification->createAction();
            $action->setLabel($l->t(text: 'View'))
                ->setPrimary(true)
                ->setLink(
                    link: \OC::$server->getURLGenerator()->linkToRouteAbsolute(
                        route: 'openregister.dashboard.page'
                    ).'#/configurations/'.$parameters['configurationId'],
                    requestType: 'GET'
                );

            $notification->addAction($action);
        }

        return $notification;

    }//end prepareConfigurationUpdate()


}//end class
