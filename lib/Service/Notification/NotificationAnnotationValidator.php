<?php

/**
 * OpenRegister NotificationAnnotationValidator
 *
 * Schema-save validation for the `x-openregister-notifications` annotation.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Notification;

use DateTimeZone;

/**
 * Validates the shape of the `x-openregister-notifications` annotation.
 *
 * Each notification entry has:
 * - `trigger`: { type: created|updated|transition, action?: string }
 * - `recipients`: [{ kind: users|field, users?: [...] | field?: "name" }]
 *   plus the watchers block `{"watchers": true}`, which addresses whoever
 *   follows the triggering object (`object-watchers`)
 * - `channels`: ["nc-notification"]   (v1)
 * - `subject`: string template OR per-locale map
 *   ({nl: "...", en: "...", defaultLocale?: "nl"}; supports {{field}}
 *   interpolation; recipient locale via `core.lang` user preference)
 * - optional `message`: notification body — same shape as `subject`
 *   (string template OR per-locale map, with {{field}} interpolation).
 *   Title=`subject`, body=`message`. When absent, the body is auto-derived
 *   ("Open in {AppName}.") if `actions` are declared, else left empty.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
final class NotificationAnnotationValidator {

	private const VALID_CHANNELS = ['nc-notification', 'email', 'activity', 'webhook', 'talk', 'web-push'];

	/**
	 * Transport kinds a rule may declare beside its channels.
	 */
	private const VALID_TRANSPORT_KINDS = ['outbound'];

	/**
	 * Validates each rule's `trigger`.
	 *
	 * @var NotificationTriggerValidator
	 */
	private NotificationTriggerValidator $triggers;

	/**
	 * Validates each rule's `recipients`.
	 *
	 * @var NotificationRecipientValidator
	 */
	private NotificationRecipientValidator $recipients;

	/**
	 * Validates each rule's `actions` and `message`.
	 *
	 * @var NotificationContentValidator
	 */
	private NotificationContentValidator $content;

	/**
	 * The administered decisions that are not preferences.
	 *
	 * @var ForcedChannelPolicy
	 */
	private ForcedChannelPolicy $forcedChannels;

	/**
	 * Constructor.
	 *
	 * Both collaborators are injectable but defaulted, because this class is
	 * constructed directly (`new NotificationAnnotationValidator()`) in several
	 * call sites that predate any container wiring.
	 *
	 * @param ScheduledFilterParser|null $filterParser  Parser for scheduled filters.
	 * @param ForcedChannelPolicy|null   $forcedChannels The administered channel decisions.
	 */
	public function __construct(
		?ScheduledFilterParser $filterParser = null,
		?ForcedChannelPolicy $forcedChannels = null,
	) {
		$this->forcedChannels = ($forcedChannels ?? new ForcedChannelPolicy());
		$this->triggers       = new NotificationTriggerValidator(filterParser: ($filterParser ?? new ScheduledFilterParser()));
		$this->recipients     = new NotificationRecipientValidator();
		$this->content        = new NotificationContentValidator();

	}//end __construct()

	/**
	 * Validate the `x-openregister-notifications` annotation.
	 *
	 * @param array<string, mixed> $schema Full schema (must include `properties`).
	 *
	 * @return array<int, array{code: string, message: string}>
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid1/tasks.md#task-5
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function validate(array $schema): array {
		if (isset($schema['x-openregister-notifications']) === false) {
			return [];
		}

		$notifications = $schema['x-openregister-notifications'];
		if (is_array($notifications) === false || count($notifications) === 0) {
			return [
				[
					'code' => 'notifications-empty',
					'message' => 'x-openregister-notifications must declare at least one notification.',
				],
			];
		}

		$facts  = new NotificationSchemaFacts(schema: $schema);
		$errors = [];
		foreach ($notifications as $name => $spec) {
			$errors = array_merge($errors, $this->validateRule(name: $name, spec: $spec, schema: $facts));
		}

		return $errors;
	}//end validate()

	/**
	 * Errors for one entry of the annotation.
	 *
	 * @param int|string              $name   The rule key.
	 * @param mixed                   $spec   The rule declaration.
	 * @param NotificationSchemaFacts $schema The schema the rule is declared on.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function validateRule(int|string $name, mixed $spec, NotificationSchemaFacts $schema): array {
		if (is_string($name) === false || $name === '') {
			return [['code' => 'notification-bad-name', 'message' => 'Notification names must be non-empty strings.']];
		}

		if (is_array($spec) === false) {
			return [['code' => 'notification-malformed', 'message' => sprintf('Notification "%s" must be an object.', $name)]];
		}

		return array_merge(
			$this->triggers->validate(name: $name, trigger: ($spec['trigger'] ?? null)),
			$this->validateDelivery(name: $name, spec: $spec),
			$this->validateContent(name: $name, spec: $spec),
			$this->validateScope(name: $name, spec: $spec),
			$this->validateCriticalAndDigest(spec: $spec, name: $name),
			$this->recipients->validate(rule: new NotificationRuleContext(name: $name, spec: $spec, schema: $schema))
		);

	}//end validateRule()

	/**
	 * Errors for how a rule is delivered: forced and declared channels,
	 * opt-in channels, message category, origin app and action buttons.
	 *
	 * @param string               $name The rule key.
	 * @param array<string, mixed> $spec The rule declaration.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function validateDelivery(string $name, array $spec): array {
		return array_merge(
			$this->validateForcedChannels(name: $name, spec: $spec),
			$this->validateChannels(name: $name, spec: $spec),
			$this->validateOptInChannels(name: $name, spec: $spec),
			$this->validateMessageCategory(name: $name, category: ($spec['messageCategory'] ?? '')),
			$this->validateOriginApp(name: $name, spec: $spec),
			$this->validateIfDeclared(
				spec: $spec,
				key: 'actions',
				check: fn (mixed $actions): array => $this->content->validateActions(actions: $actions, name: $name)
			)
		);
	}//end validateDelivery()

	/**
	 * Errors for what a rule says: subject, message and the settings its
	 * webhook or Talk channel needs.
	 *
	 * @param string               $name The rule key.
	 * @param array<string, mixed> $spec The rule declaration.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function validateContent(string $name, array $spec): array {
		return array_merge(
			$this->validateSubject(name: $name, subject: ($spec['subject'] ?? null)),
			$this->validateIfDeclared(
				spec: $spec,
				key: 'message',
				check: fn (mixed $message): array => $this->content->validateMessage(message: $message, name: $name)
			),
			$this->validateChannelTargets(name: $name, spec: $spec)
		);
	}//end validateContent()

	/**
	 * Errors for where a rule applies: organisation gate, domain and transports.
	 *
	 * @param string               $name The rule key.
	 * @param array<string, mixed> $spec The rule declaration.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function validateScope(string $name, array $spec): array {
		return array_merge(
			$this->validateIfDeclared(
				spec: $spec,
				key: 'organisation',
				check: fn (mixed $org): array => $this->organisationErrors(org: $org, name: $name)
			),
			$this->validateDomain(name: $name, spec: $spec),
			$this->validateIfDeclared(
				spec: $spec,
				key: 'transports',
				check: fn (mixed $transports): array => $this->validateTransports(transports: $transports, name: $name)
			)
		);
	}//end validateScope()

	/**
	 * Run a check on an optional rule key, only when the rule declares it.
	 *
	 * @param array<string, mixed>                          $spec  The rule declaration.
	 * @param string                                        $key   The optional key.
	 * @param callable(mixed): array<int, array<string, mixed>> $check The check for the key's value.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function validateIfDeclared(array $spec, string $key, callable $check): array {
		if (array_key_exists($key, $spec) === false) {
			return [];
		}

		return $check($spec[$key]);
	}//end validateIfDeclared()

	/**
	 * Errors for the administered decisions that are not preferences.
	 *
	 * Both fail silently at send time: a force with no reason renders as a
	 * preference a user cannot explain, and an internal kind whose only
	 * channels leave the organisation renders as a kind that never sends. The
	 * rules live in ForcedChannelPolicy so save and send read one
	 * interpretation of them.
	 *
	 * @param string               $name The rule key.
	 * @param array<string, mixed> $spec The rule declaration.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function validateForcedChannels(string $name, array $spec): array {
		$errors = [];
		foreach ($this->forcedChannels->validate(declaration: $spec) as $forcedError) {
			$errors[] = [
				'code' => $forcedError['code'],
				'message' => sprintf('Notification "%s": %s', $name, $forcedError['message']),
			];
		}

		return $errors;
	}//end validateForcedChannels()

	/**
	 * The rule's declared channels, empty when absent or not a list.
	 *
	 * @param array<string, mixed> $spec The rule declaration.
	 *
	 * @return array<int|string, mixed>
	 */
	private function declaredChannels(array $spec): array {
		$channels = ($spec['channels'] ?? []);
		if (is_array($channels) === false) {
			return [];
		}

		return $channels;
	}//end declaredChannels()

	/**
	 * Errors for `channels`: at least one, each a known channel.
	 *
	 * @param string               $name The rule key.
	 * @param array<string, mixed> $spec The rule declaration.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function validateChannels(string $name, array $spec): array {
		$channels = $this->declaredChannels(spec: $spec);
		$errors   = [];
		if ($channels === []) {
			$errors[] = [
				'code' => 'notification-channels-empty',
				'message' => sprintf('Notification "%s" must declare at least one channel.', $name),
			];
		}

		foreach ($channels as $channel) {
			if (in_array((string)$channel, self::VALID_CHANNELS, true) === false) {
				$errors[] = [
					'code' => 'notification-bad-channel',
					'message' => sprintf(
						'Notification "%s" channel "%s" is not in [%s].',
						$name,
						(string)$channel,
						implode(', ', self::VALID_CHANNELS)
					),
				];
			}
		}

		return $errors;
	}//end validateChannels()

	/**
	 * Errors for the optional `optInChannels`.
	 *
	 * Opt-in channels stay off until a person switches them on, so only a
	 * per-recipient channel qualifies; webhook and Talk fire once.
	 *
	 * @param string               $name The rule key.
	 * @param array<string, mixed> $spec The rule declaration.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function validateOptInChannels(string $name, array $spec): array {
		if (array_key_exists('optInChannels', $spec) === false) {
			return [];
		}

		$optIn = $spec['optInChannels'];
		if (is_array($optIn) === false) {
			$optIn = [null];
		}

		$errors = [];
		foreach ($optIn as $channel) {
			if (is_string($channel) === true && in_array($channel, NotificationPreferenceService::PER_RECIPIENT_CHANNELS, true) === true) {
				continue;
			}

			$shownChannel = gettype($channel);
			if (is_string($channel) === true) {
				$shownChannel = $channel;
			}

			$errors[] = [
				'code' => 'notification-bad-opt-in-channel',
				'message' => sprintf(
					'Notification "%s" opt-in channel "%s" is not in [%s].',
					$name,
					$shownChannel,
					implode(', ', NotificationPreferenceService::PER_RECIPIENT_CHANNELS)
				),
			];
		}//end foreach

		return $errors;
	}//end validateOptInChannels()

	/**
	 * Errors for the optional `messageCategory`.
	 *
	 * The category tells integriq's opt-out question what kind of message a
	 * `parties` mail is. Absent reads as `service`; anything outside the fleet
	 * list is refused rather than silently asked as `service`.
	 *
	 * @param string $name     The rule key.
	 * @param mixed  $category The declared category, '' when absent.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function validateMessageCategory(string $name, mixed $category): array {
		$shown = '';
		if (is_scalar($category) === true) {
			$shown = strtolower(trim((string)$category));
		}

		if (($shown === '' && is_scalar($category) === true) || in_array($shown, OptOutAuthority::CATEGORIES, true) === true) {
			return [];
		}

		return [
			[
				'code' => 'notification-bad-message-category',
				'message' => sprintf(
					'Notification "%s" messageCategory "%s" is not in [%s].',
					$name,
					$shown,
					implode(', ', OptOutAuthority::CATEGORIES)
				),
			],
		];
	}//end validateMessageCategory()

	/**
	 * Errors for the optional `originApp`, the leaf app that owns the rule.
	 *
	 * It drives the notification icon and the deeplink base. Absence is valid
	 * and defaults to the register-owning app at dispatch.
	 *
	 * @param string               $name The rule key.
	 * @param array<string, mixed> $spec The rule declaration.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function validateOriginApp(string $name, array $spec): array {
		if (array_key_exists('originApp', $spec) === false
			|| (is_string($spec['originApp']) === true && $spec['originApp'] !== '')
		) {
			return [];
		}

		return [
			[
				'code' => 'notification-bad-origin-app',
				'message' => sprintf('Notification "%s" originApp must be a non-empty string app id.', $name),
			],
		];
	}//end validateOriginApp()

	/**
	 * Errors for `subject`: a non-empty template string or a per-locale map.
	 *
	 * The dispatcher resolves the recipient's locale at delivery; broadcast
	 * channels use the default locale fallback chain.
	 *
	 * @param string $name    The rule key.
	 * @param mixed  $subject The declared subject, null when absent.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function validateSubject(string $name, mixed $subject): array {
		if (is_array($subject) === true) {
			return $this->validateSubjectMap(name: $name, subject: $subject);
		}

		$message = 'Notification "%s" requires a non-empty subject string.';
		if (is_string($subject) === false) {
			$message = 'Notification "%s" requires a subject string or per-locale map.';
		} elseif ($subject !== '') {
			return [];
		}

		return [['code' => 'notification-no-subject', 'message' => sprintf($message, $name)]];
	}//end validateSubject()

	/**
	 * Errors for a per-locale subject map.
	 *
	 * @param string              $name    The rule key.
	 * @param array<mixed, mixed> $subject The declared subject map.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function validateSubjectMap(string $name, array $subject): array {
		$errors     = [];
		$localeKeys = array_filter(
			array_keys($subject),
			static fn ($key): bool => $key !== 'defaultLocale' && is_string($key) === true
		);
		if (count($localeKeys) === 0) {
			$errors[] = [
				'code' => 'notification-no-subject',
				'message' => sprintf('Notification "%s" subject map must declare at least one locale (e.g. nl, en).', $name),
			];
		}

		foreach ($localeKeys as $localeKey) {
			if (is_string($subject[$localeKey]) === false || $subject[$localeKey] === '') {
				$errors[] = [
					'code' => 'notification-bad-subject-locale',
					'message' => sprintf('Notification "%s" subject for locale "%s" must be a non-empty string.', $name, $localeKey),
				];
			}
		}

		$defaultLocale = $this->content->undeclaredDefaultLocale(map: $subject);
		if ($defaultLocale !== null) {
			$errors[] = [
				'code' => 'notification-bad-default-locale',
				'message' => sprintf('Notification "%s" defaultLocale "%s" is not declared in the subject map.', $name, $defaultLocale),
			];
		}

		return $errors;
	}//end validateSubjectMap()

	/**
	 * Errors for the settings a declared `webhook` or `talk` channel needs.
	 *
	 * @param string               $name The rule key.
	 * @param array<string, mixed> $spec The rule declaration.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function validateChannelTargets(string $name, array $spec): array {
		$channels = $this->declaredChannels(spec: $spec);
		$errors   = [];
		if (in_array('webhook', $channels, true) === true && $this->isWebhookUrlMissing(hook: ($spec['webhook'] ?? null)) === true) {
			$errors[] = [
				'code' => 'notification-webhook-no-url',
				'message' => sprintf('Notification "%s" declares the `webhook` channel but webhook.url is missing or malformed.', $name),
			];
		}

		if (in_array('talk', $channels, true) === true && $this->isTalkTokenMissing(talk: ($spec['talk'] ?? null)) === true) {
			$errors[] = [
				'code' => 'notification-talk-no-token',
				'message' => sprintf('Notification "%s" declares the `talk` channel but talk.token is missing or not a string.', $name),
			];
		}

		return $errors;
	}//end validateChannelTargets()

	/**
	 * Whether a `webhook` block lacks a valid `url`.
	 *
	 * @param mixed $hook The declared webhook block.
	 *
	 * @return bool
	 */
	private function isWebhookUrlMissing(mixed $hook): bool {
		if (is_array($hook) === false) {
			return true;
		}

		return empty($hook['url']) === true || filter_var($hook['url'], FILTER_VALIDATE_URL) === false;
	}//end isWebhookUrlMissing()

	/**
	 * Whether a `talk` block lacks a string `token`.
	 *
	 * @param mixed $talk The declared talk block.
	 *
	 * @return bool
	 */
	private function isTalkTokenMissing(mixed $talk): bool {
		if (is_array($talk) === false) {
			return true;
		}

		return empty($talk['token']) === true || is_string($talk['token']) === false;
	}//end isTalkTokenMissing()

	/**
	 * The organisation gate's error as a list.
	 *
	 * The dispatcher skips the rule unless the saved object's organisation
	 * matches: one string (UUID or slug) or a list of strings (any-of).
	 *
	 * @param mixed  $org  The declared organisation gate.
	 * @param string $name The rule key.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function organisationErrors(mixed $org, string $name): array {
		$error = $this->validateOrganisationGate(org: $org, name: $name);
		if ($error === null) {
			return [];
		}

		return [$error];
	}//end organisationErrors()

	/**
	 * Errors for the optional `domain`, the leaf app's own word for a slice of its work.
	 *
	 * Only the shape is checked: the vocabulary belongs to the app.
	 *
	 * @param string               $name The rule key.
	 * @param array<string, mixed> $spec The rule declaration.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function validateDomain(string $name, array $spec): array {
		if (array_key_exists('domain', $spec) === false
			|| (is_string($spec['domain']) === true && $spec['domain'] !== '')
		) {
			return [];
		}

		return [
			[
				'code' => 'notification-domain-malformed',
				'message' => sprintf('Notification "%s" domain must be a non-empty string.', $name),
			],
		];
	}//end validateDomain()

	/**
	 * Validate the optional `critical` bypass flag and the optional
	 * fixed-time `digest` schedule block.
	 *
	 * Rules:
	 *  - `critical`, when present, MUST be a boolean.
	 *  - `digest.schedule`, when the block is present, MUST be
	 *    `daily` | `weekly`.
	 *  - `digest.at` MUST be an `HH:MM` 24h time string.
	 *  - `digest.weekday` (0-6) is REQUIRED when `schedule: "weekly"` and
	 *    FORBIDDEN otherwise.
	 *  - `digest.timezone`, when present, MUST be a value `DateTimeZone`
	 *    accepts.
	 *  - A rule MUST NOT declare both a `digest` block and a rolling
	 *    `coalesce` window — the two "hold and batch" mechanisms are
	 *    mutually exclusive per rule (design.md "Digest scheduling is
	 *    additive to, not a replacement for, the rolling digest window").
	 *
	 * @param array<string, mixed> $spec The full notification spec block.
	 * @param string               $name Notification name (for diagnostics).
	 *
	 * @return array<int, array{code: string, message: string}>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function validateCriticalAndDigest(array $spec, string $name): array {
		$errors = [];

		if (array_key_exists('critical', $spec) === true && is_bool($spec['critical']) === false) {
			$errors[] = [
				'code' => 'notification-critical-not-boolean',
				'message' => sprintf('Notification "%s" `critical` must be a boolean.', $name),
			];
		}

		if (array_key_exists('digest', $spec) === false) {
			return $errors;
		}

		if (is_array($spec['digest']) === false) {
			$errors[] = [
				'code' => 'notification-digest-malformed',
				'message' => sprintf('Notification "%s" `digest` must be an object.', $name),
			];
			return $errors;
		}

		$errors = array_merge($errors, $this->digestErrors(digest: $spec['digest'], name: $name));

		// A rule picks one "hold and batch" mechanism, not both.
		if (is_array($spec['coalesce'] ?? null) === true) {
			$errors[] = [
				'code' => 'notification-digest-and-coalesce-mutually-exclusive',
				'message' => sprintf(
					'Notification "%s" declares both a rolling `coalesce` window and a fixed-time '
					. '`digest` schedule; a rule may declare at most one batching mechanism.',
					$name
				),
			];
		}

		return $errors;
	}//end validateCriticalAndDigest()

	/**
	 * Errors for a `digest` block: schedule, time, weekday and timezone, in that order.
	 *
	 * @param array<string, mixed> $digest The digest block.
	 * @param string               $name   Notification name (for diagnostics).
	 *
	 * @return array<int, array{code: string, message: string}>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function digestErrors(array $digest, string $name): array {
		$errors = [];

		if (in_array(($digest['schedule'] ?? null), ['daily', 'weekly'], true) === false) {
			$errors[] = [
				'code' => 'notification-digest-bad-schedule',
				'message' => sprintf('Notification "%s" `digest.schedule` must be "daily" or "weekly".', $name),
			];
		}

		$at = ($digest['at'] ?? null);
		if (is_string($at) === false || preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $at) !== 1) {
			$errors[] = [
				'code' => 'notification-digest-bad-time',
				'message' => sprintf('Notification "%s" `digest.at` must be an "HH:MM" time string.', $name),
			];
		}

		$errors = array_merge($errors, $this->digestWeekdayErrors(digest: $digest, name: $name));

		$timezone = ($digest['timezone'] ?? null);
		if ($timezone !== null && $this->isValidTimezone(timezone: $timezone) === false) {
			$errors[] = [
				'code' => 'notification-digest-bad-timezone',
				'message' => sprintf('Notification "%s" `digest.timezone` must be a valid IANA timezone name.', $name),
			];
		}

		return $errors;
	}//end digestErrors()

	/**
	 * Errors for `digest.weekday`: required (0-6) for a weekly schedule, forbidden otherwise.
	 *
	 * @param array<string, mixed> $digest The digest block.
	 * @param string               $name   Notification name (for diagnostics).
	 *
	 * @return array<int, array{code: string, message: string}>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function digestWeekdayErrors(array $digest, string $name): array {
		$hasWeekday = array_key_exists('weekday', $digest);
		if (($digest['schedule'] ?? null) !== 'weekly') {
			if ($hasWeekday === false) {
				return [];
			}

			return [
				[
					'code' => 'notification-digest-weekday-not-allowed',
					'message' => sprintf('Notification "%s" `digest.weekday` is only allowed when `schedule` is "weekly".', $name),
				],
			];
		}

		$weekday = ($digest['weekday'] ?? null);
		if ($hasWeekday === true && is_int($weekday) === true && $weekday >= 0 && $weekday <= 6) {
			return [];
		}

		return [
			[
				'code' => 'notification-digest-weekly-missing-weekday',
				'message' => sprintf('Notification "%s" `digest.weekday` (0-6) is required when `schedule` is "weekly".', $name),
			],
		];
	}//end digestWeekdayErrors()

	/**
	 * Whether a value is a timezone name `DateTimeZone` accepts.
	 *
	 * @param mixed $timezone The raw value.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function isValidTimezone(mixed $timezone): bool {
		if (is_string($timezone) === false || $timezone === '') {
			return false;
		}

		try {
			new DateTimeZone($timezone);
			return true;
		} catch (\Throwable $e) {
			return false;
		}
	}//end isValidTimezone()

	/**
	 * Validate the optional `transports` block.
	 *
	 * Only `outbound` is a transport a rule declares here; the in-app notice,
	 * the e-mail and the activity entry stay in `channels`, which is where
	 * every existing rule already spells them. A handler is required because a
	 * transport naming none is an integration that silently never runs.
	 *
	 * @param mixed $transports Raw value of the `transports` key.
	 * @param string $name The notification name (for error messages).
	 *
	 * @return array<int, array{code: string, message: string}> One entry per fault.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-one-rule-reaches-a-person-and-an-integration-recorded-once-req-nrg-004
	 */
	private function validateTransports(mixed $transports, string $name): array {
		if (is_array($transports) === false) {
			return [
				[
					'code' => 'notification-transports-malformed',
					'message' => sprintf('Notification "%s" transports must be an array.', $name),
				],
			];
		}

		$errors = [];
		foreach ($transports as $i => $transport) {
			if (is_array($transport) === false) {
				$errors[] = [
					'code' => 'notification-transport-malformed',
					'message' => sprintf('Notification "%s" transports[%s] must be an object.', $name, (string)$i),
				];
				continue;
			}

			$kind = (string)($transport['kind'] ?? '');
			if (in_array($kind, self::VALID_TRANSPORT_KINDS, true) === false) {
				$errors[] = [
					'code' => 'notification-transport-bad-kind',
					'message' => sprintf(
						'Notification "%s" transports[%s] kind "%s" not in [%s].',
						$name,
						(string)$i,
						$kind,
						implode(', ', self::VALID_TRANSPORT_KINDS)
					),
				];
				continue;
			}

			// `outbound` is the only kind, so reaching here means it: the kind
			// check above already refused everything else. Kept as one branch
			// rather than two so adding a second kind adds its own guard here
			// instead of silently inheriting this one.
			if ((string)($transport['handler'] ?? '') === '') {
				$errors[] = [
					'code' => 'notification-transport-no-handler',
					'message' => sprintf(
						'Notification "%s" transports[%s] kind=outbound requires a handler (DI tag or FQCN).',
						$name,
						(string)$i
					),
				];
			}
		}//end foreach

		return $errors;
	}//end validateTransports()

	/**
	 * Validate the optional `organisation` gate.
	 *
	 * @param mixed $org Raw value of the `organisation` key.
	 * @param string $name Notification name (for diagnostics).
	 *
	 * @return array{code: string, message: string}|null
	 */
	private function validateOrganisationGate(mixed $org, string $name): ?array {
		$code = 'notification-bad-organisation';

		if (is_string($org) === true) {
			if ($org === '') {
				return [
					'code' => $code,
					'message' => sprintf(
						'Notification "%s" organisation must be a non-empty string.',
						$name
					),
				];
			}

			return null;
		}

		if (is_array($org) === true) {
			if (count($org) === 0) {
				return [
					'code' => $code,
					'message' => sprintf(
						'Notification "%s" organisation array must declare at least one entry.',
						$name
					),
				];
			}

			foreach ($org as $candidate) {
				if (is_string($candidate) === false || $candidate === '') {
					return [
						'code' => $code,
						'message' => sprintf(
							'Notification "%s" organisation array entries must be non-empty strings.',
							$name
						),
					];
				}
			}//end foreach

			return null;
		}//end if

		return [
			'code' => $code,
			'message' => sprintf(
				'Notification "%s" organisation must be a string or an array of strings.',
				$name
			),
		];
	}//end validateOrganisationGate()

}//end class
