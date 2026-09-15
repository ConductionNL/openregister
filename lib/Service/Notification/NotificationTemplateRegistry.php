<?php

/**
 * OpenRegister NotificationTemplateRegistry.
 *
 * The catalogue of events the platform itself raises, each with a named,
 * editable message template and its variables written down.
 *
 * Two inputs, deliberately separate. The EVENT INVENTORY says what the platform
 * raises and what each event can put in a message; the TEMPLATES say what those
 * messages read like. Keeping them apart is what makes the gap list mean
 * something: an event added to the inventory with no template written for it is
 * named by {@see gaps()}, rather than quietly rendering a generic line that
 * nobody ever notices is generic (design D-5). Derive one list from the other
 * and the gap list can only ever be empty, which is the same as not having one.
 *
 * An administrator's edit lives in app config keyed by event and locale, and
 * wins over the shipped text. Clearing it restores what shipped.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/notification-routing-per-group-and-scope/specs/notificatie-engine/spec.md#requirement-every-platform-event-ships-an-editable-template-req-nrg-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Notification;

use OCP\IConfig;

/**
 * Lists, renders and edits the templates for platform-raised events.
 */
class NotificationTemplateRegistry {
	/**
	 * App id used for the config namespace.
	 */
	private const APP_NAME = 'openregister';

	/**
	 * Prefix for an administrator's edit of one event's template.
	 */
	private const KEY_PREFIX = 'notif_tpl/';

	/**
	 * Maximum length of a Nextcloud `appconfig.configkey` value.
	 */
	private const MAX_KEY_LENGTH = 64;

	/**
	 * The locale used when the reader's own is not among a template's.
	 */
	public const DEFAULT_LOCALE = 'en';

	/**
	 * Every event the platform raises, and what it can put in a message.
	 *
	 * The inventory, not the texts. An entry here with no entry in
	 * {@see SHIPPED} is a gap, and that is the point.
	 *
	 * @var array<string, array{group: string, variables: array<string, string>}>
	 */
	private const EVENTS = [
		'object_created' => [
			'group' => 'object',
			'variables' => [
				'title' => 'The object\'s title, or its uuid when it has none',
				'schema' => 'The schema the object lives on',
				'actor' => 'Who created it',
			],
		],
		'object_updated' => [
			'group' => 'object',
			'variables' => [
				'title' => 'The object\'s title, or its uuid when it has none',
				'schema' => 'The schema the object lives on',
				'actor' => 'Who changed it',
			],
		],
		'object_transitioned' => [
			'group' => 'object',
			'variables' => [
				'title' => 'The object\'s title, or its uuid when it has none',
				'from' => 'The state it left',
				'to' => 'The state it reached',
			],
		],
		'configuration_update_available' => [
			'group' => 'platform',
			'variables' => [
				'name' => 'The configuration that has an update',
				'version' => 'The version now available',
			],
		],
		'handoff_drain_failed' => [
			'group' => 'platform',
			'variables' => [
				'target' => 'The handoff target that could not be drained',
				'reason' => 'Why the drain stopped',
			],
		],
		'scheduled_report_delivered' => [
			'group' => 'reporting',
			'variables' => [
				'report' => 'The report that was delivered',
				'path' => 'Where it was written',
			],
		],
		'scheduled_report_failed' => [
			'group' => 'reporting',
			'variables' => [
				'report' => 'The report that did not run',
				'reason' => 'Why it did not run',
			],
		],
		'delegation_consent_requested' => [
			'group' => 'delegation',
			'variables' => [
				'requester' => 'Who is asking',
				'scope' => 'What they are asking for',
			],
		],
		'credential_relink_needed' => [
			'group' => 'platform',
			'variables' => [
				'connection' => 'The connection whose credential expired',
			],
		],
		'retention_holds_skipped' => [
			'group' => 'archival',
			'variables' => [
				'schemaSlug' => 'The schema the sweep ran on',
				'skippedCount' => 'How many records were left in place',
			],
		],
		'destruction_holds_skipped' => [
			'group' => 'archival',
			'variables' => [
				'schemaSlug' => 'The schema the sweep ran on',
				'skippedCount' => 'How many records were left in place',
			],
		],
		'destruction_review_pending' => [
			'group' => 'archival',
			'variables' => [
				'schemaSlug' => 'The schema the review is on',
				'pendingCount' => 'How many records are waiting on a reviewer',
			],
		],
	];

	/**
	 * The shipped text for each event, per locale.
	 *
	 * Dutch and English, because those are the two the dispatcher's own subject
	 * resolution already supports. A locale absent from an event falls back to
	 * {@see DEFAULT_LOCALE}; an EVENT absent from this map is a gap.
	 *
	 * @var array<string, array<string, array{subject: string, body: string}>>
	 */
	private const SHIPPED = [
		'object_created' => [
			'nl' => [
				'subject' => '{{title}} is aangemaakt',
				'body' => '{{actor}} heeft {{title}} aangemaakt in {{schema}}.',
			],
			'en' => [
				'subject' => '{{title}} was created',
				'body' => '{{actor}} created {{title}} in {{schema}}.',
			],
		],
		'object_updated' => [
			'nl' => [
				'subject' => '{{title}} is gewijzigd',
				'body' => '{{actor}} heeft {{title}} gewijzigd in {{schema}}.',
			],
			'en' => [
				'subject' => '{{title}} was changed',
				'body' => '{{actor}} changed {{title}} in {{schema}}.',
			],
		],
		'object_transitioned' => [
			'nl' => [
				'subject' => '{{title}} staat nu op {{to}}',
				'body' => '{{title}} ging van {{from}} naar {{to}}.',
			],
			'en' => [
				'subject' => '{{title}} is now {{to}}',
				'body' => '{{title}} moved from {{from}} to {{to}}.',
			],
		],
		'configuration_update_available' => [
			'nl' => [
				'subject' => 'Er is een update voor {{name}}',
				'body' => 'Versie {{version}} van {{name}} staat klaar.',
			],
			'en' => [
				'subject' => 'An update is available for {{name}}',
				'body' => 'Version {{version}} of {{name}} is ready to install.',
			],
		],
		'handoff_drain_failed' => [
			'nl' => [
				'subject' => 'Overdracht naar {{target}} is gestopt',
				'body' => 'De overdracht naar {{target}} stopte: {{reason}}.',
			],
			'en' => [
				'subject' => 'The handoff to {{target}} stopped',
				'body' => 'The handoff to {{target}} stopped: {{reason}}.',
			],
		],
		'scheduled_report_delivered' => [
			'nl' => [
				'subject' => '{{report}} is klaar',
				'body' => '{{report}} staat in {{path}}.',
			],
			'en' => [
				'subject' => '{{report}} is ready',
				'body' => '{{report}} was written to {{path}}.',
			],
		],
		'scheduled_report_failed' => [
			'nl' => [
				'subject' => '{{report}} is niet gedraaid',
				'body' => '{{report}} draaide niet: {{reason}}.',
			],
			'en' => [
				'subject' => '{{report}} did not run',
				'body' => '{{report}} did not run: {{reason}}.',
			],
		],
		'delegation_consent_requested' => [
			'nl' => [
				'subject' => '{{requester}} vraagt toestemming',
				'body' => '{{requester}} vraagt toestemming voor {{scope}}.',
			],
			'en' => [
				'subject' => '{{requester}} is asking for your consent',
				'body' => '{{requester}} is asking for consent to {{scope}}.',
			],
		],
		'credential_relink_needed' => [
			'nl' => [
				'subject' => '{{connection}} moet opnieuw gekoppeld worden',
				'body' => 'De koppeling met {{connection}} is verlopen en moet opnieuw gelegd worden.',
			],
			'en' => [
				'subject' => '{{connection}} needs to be linked again',
				'body' => 'The link to {{connection}} has expired and needs to be made again.',
			],
		],
		'retention_holds_skipped' => [
			'nl' => [
				'subject' => 'De bewaartermijn liet stukken staan die vastliggen',
				'body' => 'De opschoning op {{schemaSlug}} liet {{skippedCount}} stukken staan, omdat er een '
					. 'bewaarplicht op ligt.',
			],
			'en' => [
				'subject' => 'The retention sweep kept records that are on hold',
				'body' => 'The sweep on {{schemaSlug}} left {{skippedCount}} records in place, because a legal '
					. 'hold is on them.',
			],
		],
		'destruction_holds_skipped' => [
			'nl' => [
				'subject' => 'De vernietiging liet stukken staan die vastliggen',
				'body' => 'De vernietiging op {{schemaSlug}} liet {{skippedCount}} stukken staan, omdat er een '
					. 'bewaarplicht op ligt.',
			],
			'en' => [
				'subject' => 'The destruction run kept records that are on hold',
				'body' => 'The destruction run on {{schemaSlug}} left {{skippedCount}} records in place, because '
					. 'a legal hold is on them.',
			],
		],
		'destruction_review_pending' => [
			'nl' => [
				'subject' => 'Er wachten stukken op een beoordeling',
				'body' => 'Op {{schemaSlug}} wachten {{pendingCount}} stukken op een beoordeling voor '
					. 'vernietiging.',
			],
			'en' => [
				'subject' => 'Records are waiting on a review',
				'body' => '{{pendingCount}} records on {{schemaSlug}} are waiting on a review before '
					. 'destruction.',
			],
		],
	];

	/**
	 * The event inventory this instance answers about.
	 *
	 * @var array<string, array{group: string, variables: array<string, string>}>
	 */
	private array $events;

	/**
	 * The shipped texts this instance answers with.
	 *
	 * @var array<string, array<string, array{subject: string, body: string}>>
	 */
	private array $shipped;

	/**
	 * Constructor.
	 *
	 * The inventory and the texts are injectable so a test can declare an event
	 * nobody wrote a template for and watch the gap list name it — a gap list
	 * that can only ever be empty proves nothing.
	 *
	 * @param IConfig $config Nextcloud config, holding administrators' edits.
	 * @param array<string, array{group: string, variables: array<string, string>}>|null $events The inventory, or null for the platform's own.
	 * @param array<string, array<string, array{subject: string, body: string}>>|null $shipped The texts, or null for the shipped ones.
	 */
	public function __construct(
		private readonly IConfig $config,
		?array $events = null,
		?array $shipped = null,
	) {
		$this->events = ($events ?? self::EVENTS);
		$this->shipped = ($shipped ?? self::SHIPPED);
	}//end __construct()

	/**
	 * Every event, with its template, its variables and where the text came from.
	 *
	 * @return array<int, array<string, mixed>> One entry per event, event name first.
	 *
	 * @spec openspec/changes/notification-routing-per-group-and-scope/specs/notificatie-engine/spec.md#requirement-every-platform-event-ships-an-editable-template-req-nrg-006
	 */
	public function listAll(): array {
		$rows = [];
		foreach ($this->events as $event => $meta) {
			$edited = $this->editedTemplate(event: (string)$event);
			$shipped = ($this->shipped[$event] ?? null);

			$rows[] = [
				'event' => (string)$event,
				'group' => $meta['group'],
				'variables' => $meta['variables'],
				'shipped' => $shipped,
				'edited' => $edited,
				'template' => ($edited ?? $shipped),
				'source' => $this->sourceOf(edited: $edited, shipped: $shipped),
				// The honest answer to "does this event have anything to say":
				// false is what `gaps()` lists, and what nothing papers over.
				'hasTemplate' => (($edited ?? $shipped) !== null),
			];
		}

		return $rows;
	}//end listAll()

	/**
	 * The events that have no template at all.
	 *
	 * @return array<int, string> The event names, in inventory order.
	 *
	 * @spec openspec/changes/notification-routing-per-group-and-scope/specs/notificatie-engine/spec.md#requirement-every-platform-event-ships-an-editable-template-req-nrg-006
	 */
	public function gaps(): array {
		$missing = [];
		foreach (array_keys($this->events) as $event) {
			if ($this->templateFor(event: (string)$event) === null) {
				$missing[] = (string)$event;
			}
		}

		return $missing;
	}//end gaps()

	/**
	 * Whether the platform declares this event at all.
	 *
	 * @param string $event The event name.
	 *
	 * @return boolean True when the event is in the inventory.
	 *
	 * @spec openspec/changes/notification-routing-per-group-and-scope/specs/notificatie-engine/spec.md#requirement-every-platform-event-ships-an-editable-template-req-nrg-006
	 */
	public function knows(string $event): bool {
		return array_key_exists($event, $this->events);
	}//end knows()

	/**
	 * Whether an administrator has changed this event's words.
	 *
	 * Distinct from "has a template": every platform event ships one, and the
	 * question a renderer needs answered is whether somebody replaced it.
	 *
	 * @param string $event The event name.
	 *
	 * @return boolean True when an edit is stored for this event.
	 *
	 * @spec openspec/changes/notification-routing-per-group-and-scope/specs/notificatie-engine/spec.md#requirement-every-platform-event-ships-an-editable-template-req-nrg-006
	 */
	public function hasEdit(string $event): bool {
		return ($this->editedTemplate(event: $event) !== null);
	}//end hasEdit()

	/**
	 * The template that applies for one event, the edit winning over the shipped text.
	 *
	 * @param string $event The event name.
	 *
	 * @return array<string, array{subject: string, body: string}>|null The per-locale template, or null when there is none.
	 *
	 * @spec openspec/changes/notification-routing-per-group-and-scope/specs/notificatie-engine/spec.md#requirement-every-platform-event-ships-an-editable-template-req-nrg-006
	 */
	public function templateFor(string $event): ?array {
		return ($this->editedTemplate(event: $event) ?? $this->shipped[$event] ?? null);
	}//end templateFor()

	/**
	 * Render one event's subject and body in a locale, or nothing.
	 *
	 * Returns null rather than a generic line when the event has no template.
	 * A caller that gets null must say so or fall back to something it wrote
	 * itself; what it must never do is print a sentence that reads like the
	 * template somebody forgot to write, because then nobody finds out.
	 *
	 * @param string $event The event name.
	 * @param string|null $locale The reader's locale, or null for the default.
	 * @param array<string, mixed> $variables Values for the template's variables.
	 *
	 * @return array{subject: string, body: string}|null The rendered text, or null when the event has no template.
	 *
	 * @spec openspec/changes/notification-routing-per-group-and-scope/specs/notificatie-engine/spec.md#requirement-every-platform-event-ships-an-editable-template-req-nrg-006
	 */
	public function render(string $event, ?string $locale, array $variables = []): ?array {
		$template = $this->templateFor(event: $event);
		if ($template === null) {
			return null;
		}

		$chosen = ($template[(string)$locale] ?? $template[self::DEFAULT_LOCALE] ?? null);
		if (is_array($chosen) === false) {
			// A template with locales but not this one and not the default is
			// still a template: take whichever one it does have rather than
			// answering "no template" for text that plainly exists.
			$chosen = reset($template);
		}

		if (is_array($chosen) === false) {
			return null;
		}

		return [
			'subject' => $this->interpolate(template: (string)($chosen['subject'] ?? ''), variables: $variables),
			'body' => $this->interpolate(template: (string)($chosen['body'] ?? ''), variables: $variables),
		];
	}//end render()

	/**
	 * Record or clear an administrator's edit of one event's template.
	 *
	 * @param string $event The event name.
	 * @param array<string, array{subject: string, body: string}>|null $template The per-locale text, or null to restore what shipped.
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException When the event is not one the platform raises.
	 *
	 * @spec openspec/changes/notification-routing-per-group-and-scope/specs/notificatie-engine/spec.md#requirement-every-platform-event-ships-an-editable-template-req-nrg-006
	 */
	public function edit(string $event, ?array $template): void {
		if ($this->knows(event: $event) === false) {
			throw new \InvalidArgumentException(sprintf('"%s" is not an event the platform raises.', $event));
		}

		$key = $this->configKey(event: $event);
		if ($template === null) {
			$this->config->deleteAppValue(self::APP_NAME, $key);
			return;
		}

		$clean = [];
		foreach ($template as $locale => $text) {
			if (is_string($locale) === false || $locale === '' || is_array($text) === false) {
				continue;
			}

			$clean[$locale] = [
				'subject' => (string)($text['subject'] ?? ''),
				'body' => (string)($text['body'] ?? ''),
			];
		}

		if ($clean === []) {
			throw new \InvalidArgumentException('A template needs at least one locale with a subject and a body.');
		}

		$this->config->setAppValue(self::APP_NAME, $key, json_encode($clean));
	}//end edit()

	/**
	 * Read an administrator's edit, or null when there is none.
	 *
	 * @param string $event The event name.
	 *
	 * @return array<string, array{subject: string, body: string}>|null The edited template.
	 */
	private function editedTemplate(string $event): ?array {
		$raw = $this->config->getAppValue(self::APP_NAME, $this->configKey(event: $event), '');
		if ($raw === '') {
			return null;
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false || $decoded === []) {
			return null;
		}

		return $decoded;
	}//end editedTemplate()

	/**
	 * Name where an event's text came from.
	 *
	 * @param array<string, mixed>|null $edited The administrator's edit.
	 * @param array<string, mixed>|null $shipped The shipped text.
	 *
	 * @return string `edited`, `shipped`, or `none`.
	 */
	private function sourceOf(?array $edited, ?array $shipped): string {
		if ($edited !== null) {
			return 'edited';
		}

		if ($shipped !== null) {
			return 'shipped';
		}

		return 'none';
	}//end sourceOf()

	/**
	 * The config key one event's edit is stored under.
	 *
	 * @param string $event The event name.
	 *
	 * @return string The key (<= 64 chars).
	 */
	private function configKey(string $event): string {
		$key = self::KEY_PREFIX . $event;
		if (strlen($key) <= self::MAX_KEY_LENGTH) {
			return $key;
		}

		$hash = substr(hash('sha256', $event), 0, 16);
		$budget = (self::MAX_KEY_LENGTH - strlen(self::KEY_PREFIX) - 1 - strlen($hash));
		return self::KEY_PREFIX . substr($event, 0, max($budget, 0)) . '~' . $hash;
	}//end configKey()

	/**
	 * Substitute `{{name}}` placeholders with the values given.
	 *
	 * A placeholder with no value is left standing rather than blanked, so a
	 * message that is missing something says which thing.
	 *
	 * @param string $template The text.
	 * @param array<string, mixed> $variables The values.
	 *
	 * @return string The rendered text.
	 */
	private function interpolate(string $template, array $variables): string {
		if ($template === '' || $variables === []) {
			return $template;
		}

		return (string)preg_replace_callback(
			'/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/',
			static function (array $matches) use ($variables): string {
				$value = ($variables[$matches[1]] ?? null);
				if (is_scalar($value) === false) {
					return $matches[0];
				}

				return (string)$value;
			},
			$template
		);
	}//end interpolate()
}//end class
