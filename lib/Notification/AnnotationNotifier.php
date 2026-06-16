<?php

/**
 * OpenRegister AnnotationNotifier
 *
 * Renders annotation-driven, object-lifecycle notifications fired by
 * AnnotationNotificationDispatcher. The dispatcher emits a canonical
 * subject (object_created / object_updated / object_transitioned), the
 * routing parameters for the object-detail action link (registerId,
 * schemaId, objectUuid, objectTitle), and — when the schema declared a
 * custom per-locale `subject` — the already-interpolated text under the
 * `_text` parameter.
 *
 * This notifier renders the recipient-localised subject (the schema's
 * custom `_text` wins; otherwise a canonical localised string from the
 * openregister l10n files), sets the OpenRegister icon, and adds a primary
 * "View" action deep-linking to the object. Subjects it does not own (no
 * `_text` and not a canonical object subject — e.g. configuration_update_available,
 * which lib/Notification/Notifier.php renders) raise UnknownNotificationException
 * so the manager passes the notification on to the next notifier untouched.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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

use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

class AnnotationNotifier implements INotifier
{
    /**
     * Canonical object-lifecycle subjects mapped to their English source
     * string (Dutch comes from l10n/nl.json via IFactory). Used to render a
     * localised subject when the schema declared no custom `subject`.
     *
     * @var array<string, string>
     */
    private const SUBJECT_TEMPLATES = [
        'object_created'      => 'Object "%1$s" created in register "%2$s"',
        'object_updated'      => 'Object "%1$s" updated in register "%2$s"',
        'object_transitioned' => 'Object "%1$s" assigned to you in register "%2$s"',
    ];

    /**
     * Constructor.
     *
     * @param IFactory      $factory      L10N factory for localised subjects.
     * @param IURLGenerator $urlGenerator URL generator for the icon and action link.
     */
    public function __construct(
        private readonly IFactory $factory,
        private readonly IURLGenerator $urlGenerator
    ) {
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
     * Render the notification subject and action for the given language.
     *
     * @param INotification $notification Notification to prepare.
     * @param string        $languageCode Active language code.
     *
     * @return INotification Prepared notification.
     *
     * @throws UnknownNotificationException When the notification is not an
     *                                      annotation/object notification this
     *                                      notifier owns.
     *
     * @spec openspec/changes/openregister-web-push-engine/specs/notificatie-engine/spec.md
     */
    public function prepare(INotification $notification, string $languageCode): INotification
    {
        if ($notification->getApp() !== 'openregister') {
            throw new UnknownNotificationException();
        }

        $subject  = $notification->getSubject();
        $params   = $notification->getSubjectParameters();
        $text     = ($params['_text'] ?? null);
        $hasText  = (is_string($text) === true && $text !== '');
        $isObject = array_key_exists($subject, self::SUBJECT_TEMPLATES);

        // Subjects this notifier does not own (e.g. configuration_update_available,
        // rendered by Notifier) are passed on untouched.
        if ($isObject === false && $hasText === false) {
            throw new UnknownNotificationException();
        }

        $l = $this->factory->get('openregister', $languageCode);

        // The schema's custom per-locale subject (already interpolated by the
        // dispatcher for this recipient) wins; otherwise render the canonical
        // localised string with the object title + register name substituted.
        $objectTitle   = (string) ($params['objectTitle'] ?? $l->t('object'));
        $registerName  = (string) ($params['registerName'] ?? ($params['registerId'] ?? ''));
        $parsedSubject = $l->t(self::SUBJECT_TEMPLATES[$subject], [$objectTitle, $registerName]);
        if ($hasText === true) {
            $parsedSubject = $text;
        }

        $notification->setParsedSubject($parsedSubject);

        // Icon: when the rule resolved an originApp, point at the hex-composite
        // raster endpoint (the originApp's white glyph on the cobalt hexagon)
        // instead of the static openregister app image — so the OS popup
        // carries the originating app's identity. Falls back to app.svg.
        $originApp = (string) ($params['originApp'] ?? 'openregister');
        if ($originApp !== '' && $originApp !== 'openregister') {
            $notification->setIcon(
                $this->urlGenerator->linkToRouteAbsolute(
                    'openregister.webPush.hexIcon',
                    ['app' => $originApp]
                )
            );
        } else {
            $notification->setIcon(
                $this->urlGenerator->imagePath(appName: 'openregister', file: 'app.svg')
            );
        }

        // Render declared action buttons when the rule provided any; otherwise
        // keep the implicit single "View" action (back-compat — existing rules
        // are unchanged).
        $actions = ($params['_actions'] ?? []);
        if (is_array($actions) === true && count($actions) > 0) {
            $this->addDeclaredActions(notification: $notification, actions: $actions, languageCode: $languageCode);
        } else {
            $this->addViewAction(notification: $notification, params: $params, label: $l->t('View'));
        }

        return $notification;
    }//end prepare()

    /**
     * Render the schema-declared action buttons via addAction().
     *
     * Each action carries a per-locale `label` map, a `primary` flag, and a
     * pre-resolved absolute `url` (resolved server-side by the dispatcher
     * through OR RBAC). The recipient's locale label wins, falling back to
     * `en` then the first available locale.
     *
     * @param INotification     $notification Notification to attach actions to.
     * @param array<int, mixed> $actions      Resolved actions (each element validated at runtime).
     * @param string            $languageCode Active recipient locale.
     *
     * @return void
     *
     * @spec openspec/changes/openregister-web-push-engine/specs/notificatie-engine/spec.md
     */
    private function addDeclaredActions(INotification $notification, array $actions, string $languageCode): void
    {
        foreach ($actions as $action) {
            if (is_array($action) === false) {
                continue;
            }

            $url = (string) ($action['url'] ?? '');
            if ($url === '') {
                continue;
            }

            $labelMap = ($action['label'] ?? []);
            if (is_array($labelMap) === false) {
                $labelMap = [];
            }

            $fallbackLabel = 'Open';
            $firstLabel    = reset($labelMap);
            if ($firstLabel !== false) {
                $fallbackLabel = $firstLabel;
            }

            $label = (string) ($labelMap[$languageCode] ?? ($labelMap['en'] ?? $fallbackLabel));

            $actionObject = $notification->createAction();
            $actionObject->setLabel($label)
                ->setPrimary((bool) ($action['primary'] ?? false))
                ->setLink($url, 'GET');
            $notification->addAction($actionObject);
        }//end foreach
    }//end addDeclaredActions()

    /**
     * Attach a "View" deep-link action to the notification when all routing
     * parameters (registerId, schemaId, objectUuid) are present and non-empty.
     *
     * @param INotification       $notification Notification to attach the action to.
     * @param array<string,mixed> $params       Subject parameters from the notification.
     * @param string              $label        Localised label for the action button.
     *
     * @return void
     */
    private function addViewAction(INotification $notification, array $params, string $label): void
    {
        $registerId = ($params['registerId'] ?? null);
        $schemaId   = ($params['schemaId'] ?? null);
        $objectUuid = ($params['objectUuid'] ?? null);
        if ($registerId === null || $schemaId === null || $objectUuid === null || (string) $objectUuid === '') {
            return;
        }

        $action = $notification->createAction();
        $action->setLabel($label)
            ->setPrimary(true)
            ->setLink(
                $this->urlGenerator->linkToRouteAbsolute('openregister.dashboard.page')
                .sprintf('#/registers/%s/schemas/%s/objects/%s', $registerId, $schemaId, $objectUuid),
                'GET'
            );
        $notification->addAction($action);
    }//end addViewAction()
}//end class
