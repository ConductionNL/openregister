<?php

/**
 * OpenRegister Activity Provider.
 *
 * Provider for parsing and rendering OpenRegister activity events.
 *
 * @category Activity
 * @package  OCA\OpenRegister\Activity
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Activity;

use OCA\OpenRegister\AppInfo\Application;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\Activity\IProvider;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;

/**
 * Activity provider for parsing OpenRegister events.
 */
class Provider implements IProvider
{
    /**
     * Subjects that are handled by this provider.
     *
     * @var string[]
     */
    private const HANDLED_SUBJECTS = [
        'object_created',
        'object_updated',
        'object_deleted',
        'register_created',
        'register_updated',
        'register_deleted',
        'schema_created',
        'schema_updated',
        'schema_deleted',
    ];

    /**
     * Constructor.
     *
     * @param IFactory               $l10nFactory    The l10n factory.
     * @param IURLGenerator          $urlGenerator   The URL generator.
     * @param ProviderSubjectHandler $subjectHandler The subject handler.
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-2
     */
    public function __construct(
        private IFactory $l10nFactory,
        private IURLGenerator $urlGenerator,
        private ProviderSubjectHandler $subjectHandler,
    ) {
    }//end __construct()

    /**
     * Parse an activity event into a human-readable format.
     *
     * @param string  $language      The language code.
     * @param IEvent  $event         The event to parse.
     * @param ?IEvent $previousEvent The previous event or null.
     *
     * @return IEvent The parsed event.
     *
     * @throws UnknownActivityException If the event cannot be parsed.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) — $previousEvent required by IProvider interface
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-2
     */
    public function parse($language, IEvent $event, ?IEvent $previousEvent=null): IEvent
    {
        if ($event->getApp() !== Application::APP_ID) {
            throw new UnknownActivityException();
        }

        if (in_array($event->getSubject(), self::HANDLED_SUBJECTS, true) === false) {
            throw new UnknownActivityException();
        }

        $l      = $this->l10nFactory->get(Application::APP_ID, $language);
        $params = $event->getSubjectParameters();

        $this->subjectHandler->applySubjectText(
            event: $event,
            l: $l,
            params: $params
        );

        $event->setIcon(
            $this->urlGenerator->getAbsoluteURL(
                $this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg')
            )
        );

        return $event;
    }//end parse()
}//end class
