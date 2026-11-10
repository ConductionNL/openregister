<?php

/**
 * OpenRegister ProviderSubjectHandler.
 *
 * Handler for applying activity subject text and rich parameters to events.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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

use OCA\OpenRegister\Kernel\Contracts\AppIdentity;
use OCP\Activity\IEvent;
use OCP\L10N\IFactory;

/**
 * Handler for applying activity subject text and rich parameters.
 */
class ProviderSubjectHandler {
	/**
	 * Simple subject map: subject => [parsedKey, richKey].
	 *
	 * @var array<string, array{string, string}>
	 */
	private const SIMPLE_SUBJECTS = [
		'object_created' => ['Object created: %s', 'Object created: {title}'],
		'object_updated' => ['Object updated: %s', 'Object updated: {title}'],
		'object_deleted' => ['Object deleted: %s', 'Object deleted: {title}'],
		'register_created' => ['Register created: %s', 'Register created: {title}'],
		'register_updated' => ['Register updated: %s', 'Register updated: {title}'],
		'register_deleted' => ['Register deleted: %s', 'Register deleted: {title}'],
		'schema_created' => ['Schema created: %s', 'Schema created: {title}'],
		'schema_updated' => ['Schema updated: %s', 'Schema updated: {title}'],
		'schema_deleted' => ['Schema deleted: %s', 'Schema deleted: {title}'],
	];

	/**
	 * Object subjects that name the schema too: subject => [parsedKey, richKey].
	 *
	 * Used when the activity carries a `schema` parameter; without it the
	 * simple map above applies, so older rows keep rendering.
	 *
	 * @var array<string, array{string, string}>
	 */
	private const SCHEMA_SUBJECTS = [
		'object_created' => ['%1$s %2$s created', '{schema} {title} created'],
		'object_updated' => ['%1$s %2$s updated', '{schema} {title} updated'],
		'object_deleted' => ['%1$s %2$s deleted', '{schema} {title} deleted'],
	];

	/**
	 * Constructor.
	 *
	 * @param IFactory $l10nFactory The l10n factory the subject text is translated with.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function __construct(
		private readonly IFactory $l10nFactory,
	) {
	}//end __construct()

	/**
	 * Apply subject text and rich parameters to the event based on its subject type.
	 *
	 * @param IEvent $event    The event to modify; its subject parameters carry the title and schema.
	 * @param string $language The language code to translate the subject into.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 * @spec openspec/specs/activity-provider/spec.md#requirement-an-object-activity-names-the-schema-and-the-object
	 */
	public function applySubjectText(IEvent $event, string $language): void {
		$l = $this->l10nFactory->get(AppIdentity::APP_ID, $language);
		$params = $event->getSubjectParameters();
		$title = $this->subjectTitle(event: $event);
		$richParams = $this->buildRichParams(
			event: $event,
			title: $title
		);

		$subject = $event->getSubject();
		$schema = $params['schema'] ?? '';

		if (isset(self::SCHEMA_SUBJECTS[$subject]) === true && is_string($schema) === true && $schema !== '') {
			$richParams['schema'] = [
				'type' => 'highlight',
				'id' => $schema,
				'name' => $schema,
			];
			$event->setParsedSubject($l->t(self::SCHEMA_SUBJECTS[$subject][0], [$schema, $title]));
			$event->setRichSubject($l->t(self::SCHEMA_SUBJECTS[$subject][1]), $richParams);
			return;
		}

		if (isset(self::SIMPLE_SUBJECTS[$subject]) === true) {
			$this->applySimpleSubject(event: $event, l: $l);
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
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	private function buildRichParams(IEvent $event, string $title): array {
		return [
			'title' => [
				'type' => 'highlight',
				'id' => (string)$event->getObjectId(),
				'name' => $title,
			],
		];
	}//end buildRichParams()

	/**
	 * Apply a simple parsed and rich subject to the event.
	 *
	 * @param IEvent $event The event; its subject picks the translation keys.
	 * @param object $l     The l10n translator.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	private function applySimpleSubject(IEvent $event, object $l): void {
		$keys = self::SIMPLE_SUBJECTS[$event->getSubject()];
		$title = $this->subjectTitle(event: $event);
		$event->setParsedSubject($l->t($keys[0], [$title]));
		$event->setRichSubject(
			$l->t($keys[1]),
			$this->buildRichParams(event: $event, title: $title)
		);
	}//end applySimpleSubject()

	/**
	 * The entity title the event carries, empty when it carries none.
	 *
	 * @param IEvent $event The event.
	 *
	 * @return string The title.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	private function subjectTitle(IEvent $event): string {
		return $event->getSubjectParameters()['title'] ?? '';
	}//end subjectTitle()
}//end class
