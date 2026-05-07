<?php

/**
 * OpenRegister ProviderSubjectHandler.
 *
 * Handler for applying activity subject text and rich parameters to events.
 *
 * @category Activity
 * @package  OCA\OpenRegister\Activity
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Activity;

use OCP\Activity\IEvent;

/**
 * Handler for applying activity subject text and rich parameters.
 */
class ProviderSubjectHandler
{
    /**
     * Simple subject map: subject => [parsedKey, richKey].
     *
     * @var array<string, array{string, string}>
     */
    private const SIMPLE_SUBJECTS = [
        'object_created'   => ['Object created: %s', 'Object created: {title}'],
        'object_updated'   => ['Object updated: %s', 'Object updated: {title}'],
        'object_deleted'   => ['Object deleted: %s', 'Object deleted: {title}'],
        'register_created' => ['Register created: %s', 'Register created: {title}'],
        'register_updated' => ['Register updated: %s', 'Register updated: {title}'],
        'register_deleted' => ['Register deleted: %s', 'Register deleted: {title}'],
        'schema_created'   => ['Schema created: %s', 'Schema created: {title}'],
        'schema_updated'   => ['Schema updated: %s', 'Schema updated: {title}'],
        'schema_deleted'   => ['Schema deleted: %s', 'Schema deleted: {title}'],
    ];

    /**
     * Apply subject text and rich parameters to the event based on its subject type.
     *
     * @param IEvent $event  The event to modify.
     * @param object $l      The l10n translator.
     * @param array  $params The subject parameters.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-2
     */
    public function applySubjectText(IEvent $event, object $l, array $params): void
    {
        $title      = $params['title'] ?? '';
        $richParams = $this->buildRichParams(
            event: $event,
            title: $title
        );

        $subject = $event->getSubject();

        if (isset(self::SIMPLE_SUBJECTS[$subject]) === true) {
            $this->applySimpleSubject(
                event: $event,
                l: $l,
                parsedKey: self::SIMPLE_SUBJECTS[$subject][0],
                richKey: self::SIMPLE_SUBJECTS[$subject][1],
                title: $title,
                richParams: $richParams
            );
        }
    }//end applySubjectText()

    /**
     * Build rich parameters for an event.
     *
     * @param IEvent $event The event.
     * @param string $title The entity title.
     *
     * @return array The rich parameters.
     *
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-2
     */
    private function buildRichParams(IEvent $event, string $title): array
    {
        return [
            'title' => [
                'type' => 'highlight',
                'id'   => (string) $event->getObjectId(),
                'name' => $title,
            ],
        ];
    }//end buildRichParams()

    /**
     * Apply a simple parsed and rich subject to the event.
     *
     * @param IEvent $event      The event.
     * @param object $l          The l10n translator.
     * @param string $parsedKey  The parsed subject translation key.
     * @param string $richKey    The rich subject translation key.
     * @param string $title      The entity title.
     * @param array  $richParams The rich parameters.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-2
     */
    private function applySimpleSubject(
        IEvent $event,
        object $l,
        string $parsedKey,
        string $richKey,
        string $title,
        array $richParams,
    ): void {
        $event->setParsedSubject($l->t($parsedKey, [$title]));
        $event->setRichSubject(
            $l->t($richKey),
            $richParams
        );
    }//end applySimpleSubject()
}//end class
