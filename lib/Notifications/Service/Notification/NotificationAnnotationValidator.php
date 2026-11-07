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

namespace OCA\OpenRegister\Notifications\Service\Notification;

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
 * @spec openspec/specs/notificatie-engine/spec.md
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
final class NotificationAnnotationValidator {

	private const VALID_CHANNELS = ['nc-notification', 'email', 'activity', 'webhook', 'talk', 'web-push'];

	/**
	 * Every diagnostic this class reports: fault => [error code, sprintf format].
	 */
	private const FAULTS = [
		'notifications-empty'  => ['notifications-empty', 'x-openregister-notifications must declare at least one notification.'],
		'bad-name'             => ['notification-bad-name', 'Notification names must be non-empty strings.'],
		'malformed'            => ['notification-malformed', 'Notification "%s" must be an object.'],
		'channels-empty'       => ['notification-channels-empty', 'Notification "%s" must declare at least one channel.'],
		'bad-channel'          => ['notification-bad-channel', 'Notification "%s" channel "%s" is not in [%s].'],
		'bad-opt-in-channel'   => ['notification-bad-opt-in-channel', 'Notification "%s" opt-in channel "%s" is not in [%s].'],
		'bad-message-category' => [
			'notification-bad-message-category',
			'Notification "%s" messageCategory "%s" is not in [%s].',
		],
		'bad-origin-app'       => ['notification-bad-origin-app', 'Notification "%s" originApp must be a non-empty string app id.'],
		'subject-empty'        => ['notification-no-subject', 'Notification "%s" requires a non-empty subject string.'],
		'subject-type'         => ['notification-no-subject', 'Notification "%s" requires a subject string or per-locale map.'],
		'subject-no-locale'    => [
			'notification-no-subject',
			'Notification "%s" subject map must declare at least one locale (e.g. nl, en).',
		],
		'bad-subject-locale'   => [
			'notification-bad-subject-locale',
			'Notification "%s" subject for locale "%s" must be a non-empty string.',
		],
		'bad-default-locale'   => [
			'notification-bad-default-locale',
			'Notification "%s" defaultLocale "%s" is not declared in the subject map.',
		],
		'webhook-no-url'       => [
			'notification-webhook-no-url',
			'Notification "%s" declares the `webhook` channel but webhook.url is missing or malformed.',
		],
		'talk-no-token'        => [
			'notification-talk-no-token',
			'Notification "%s" declares the `talk` channel but talk.token is missing or not a string.',
		],
		'domain-malformed'     => ['notification-domain-malformed', 'Notification "%s" domain must be a non-empty string.'],
		'critical-not-boolean' => ['notification-critical-not-boolean', 'Notification "%s" `critical` must be a boolean.'],
	];

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
	 * Validates each rule's `digest` block.
	 *
	 * @var NotificationDigestValidator
	 */
	private NotificationDigestValidator $digests;

	/**
	 * Validates each rule's `transports` block.
	 *
	 * @var NotificationTransportValidator
	 */
	private NotificationTransportValidator $transports;

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
		$this->digests        = new NotificationDigestValidator();
		$this->transports     = new NotificationTransportValidator();

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
		$notifications = ($schema['x-openregister-notifications'] ?? null);
		if ($notifications === null) {
			return [];
		}

		if (is_array($notifications) === false || count($notifications) === 0) {
			return [$this->fault(fault: 'notifications-empty')];
		}

		return $this->rulesErrors(notifications: $notifications, schema: new NotificationSchemaFacts(schema: $schema));
	}//end validate()

	/**
	 * Errors for every entry of the annotation, in declaration order.
	 *
	 * @param array<int|string, mixed> $notifications The declared rules.
	 * @param NotificationSchemaFacts  $schema        The schema they are declared on.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rulesErrors(array $notifications, NotificationSchemaFacts $schema): array {
		$errors = [];
		foreach ($notifications as $name => $spec) {
			// Only runs once ruleShapeErrors() found a non-empty string name and an object spec.
			$rule   = fn (): array => $this->ruleErrors(rule: new NotificationRuleContext(name: (string)$name, spec: $spec, schema: $schema));
			$errors = array_merge($errors, ($this->ruleShapeErrors(name: $name, spec: $spec) ?? $rule()));
		}

		return $errors;
	}//end rulesErrors()

	/**
	 * One error as the annotation reports it.
	 *
	 * @param string            $fault A key of self::FAULTS.
	 * @param array<int, mixed> $args  The values its format interpolates.
	 *
	 * @return array{code: string, message: string}
	 */
	private function fault(string $fault, array $args=[]): array {
		[$code, $format] = self::FAULTS[$fault];

		return ['code' => $code, 'message' => sprintf($format, ...$args)];
	}//end fault()

	/**
	 * The error for an entry that is not a named object, null when it is one.
	 *
	 * @param int|string $name The rule key.
	 * @param mixed      $spec The rule declaration.
	 *
	 * @return array<int, array{code: string, message: string}>|null
	 */
	private function ruleShapeErrors(int|string $name, mixed $spec): ?array {
		if (is_string($name) === false || $name === '') {
			return [$this->fault(fault: 'bad-name')];
		}

		if (is_array($spec) === false) {
			return [$this->fault(fault: 'malformed', args: [$name])];
		}

		return null;
	}//end ruleShapeErrors()

	/**
	 * Errors for one well-shaped entry of the annotation.
	 *
	 * @param NotificationRuleContext $rule The rule and the schema it is declared on.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function ruleErrors(NotificationRuleContext $rule): array {
		return array_merge(
			$this->triggers->validate(name: $rule->name, trigger: ($rule->spec['trigger'] ?? null)),
			$this->validateDelivery(name: $rule->name, spec: $rule->spec),
			$this->validateContent(name: $rule->name, spec: $rule->spec),
			$this->validateScope(name: $rule->name, spec: $rule->spec),
			$this->validateCriticalAndDigest(spec: $rule->spec, name: $rule->name),
			$this->recipients->validate(rule: $rule)
		);
	}//end ruleErrors()

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
		$actions = fn (mixed $actions): array => $this->content->validateActions(actions: $actions, name: $name);

		return array_merge(
			$this->validateForcedChannels(name: $name, spec: $spec),
			$this->validateChannels(name: $name, spec: $spec),
			$this->validateOptInChannels(name: $name, spec: $spec),
			$this->validateMessageCategory(name: $name, category: ($spec['messageCategory'] ?? '')),
			$this->validateOriginApp(name: $name, spec: $spec),
			$this->declaredKeyErrors(spec: $spec, checks: ['actions' => $actions])
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
		$message = fn (mixed $message): array => $this->content->validateMessage(message: $message, name: $name);

		return array_merge(
			$this->validateSubject(name: $name, subject: ($spec['subject'] ?? null)),
			$this->declaredKeyErrors(spec: $spec, checks: ['message' => $message]),
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
		$organisation = fn (mixed $org): array => $this->organisationErrors(org: $org, name: $name);
		$transports   = fn (mixed $transports): array => $this->transports->validate(transports: $transports, name: $name);

		return array_merge(
			$this->declaredKeyErrors(spec: $spec, checks: ['organisation' => $organisation]),
			$this->validateDomain(name: $name, spec: $spec),
			$this->declaredKeyErrors(spec: $spec, checks: ['transports' => $transports])
		);
	}//end validateScope()

	/**
	 * Run each check on its optional rule key, only when the rule declares that key.
	 *
	 * @param array<string, mixed>                                    $spec   The rule declaration.
	 * @param array<string, callable(mixed): array<int, array<string, mixed>>> $checks Key => check for the key's value.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function declaredKeyErrors(array $spec, array $checks): array {
		$errors = [];
		foreach ($checks as $key => $check) {
			if (array_key_exists($key, $spec) === true) {
				$errors = array_merge($errors, $check($spec[$key]));
			}
		}

		return $errors;
	}//end declaredKeyErrors()

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
			$errors[] = $this->fault(fault: 'channels-empty', args: [$name]);
		}

		$known = implode(', ', self::VALID_CHANNELS);
		foreach ($channels as $channel) {
			if (in_array((string)$channel, self::VALID_CHANNELS, true) === false) {
				$errors[] = $this->fault(fault: 'bad-channel', args: [$name, (string)$channel, $known]);
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

		$errors = array_map(fn (mixed $channel): ?array => $this->optInChannelError(name: $name, channel: $channel), array_values($optIn));

		return array_values(array_filter($errors));
	}//end validateOptInChannels()

	/**
	 * The error for one opt-in channel, null when it is a per-recipient channel.
	 *
	 * @param string $name    The rule key.
	 * @param mixed  $channel The declared opt-in channel.
	 *
	 * @return array{code: string, message: string}|null
	 */
	private function optInChannelError(string $name, mixed $channel): ?array {
		if (is_string($channel) === true && in_array($channel, NotificationPreferenceService::PER_RECIPIENT_CHANNELS, true) === true) {
			return null;
		}

		$shownChannel = gettype($channel);
		if (is_string($channel) === true) {
			$shownChannel = $channel;
		}

		$known = implode(', ', NotificationPreferenceService::PER_RECIPIENT_CHANNELS);

		return $this->fault(fault: 'bad-opt-in-channel', args: [$name, $shownChannel, $known]);
	}//end optInChannelError()

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

		return [$this->fault(fault: 'bad-message-category', args: [$name, $shown, implode(', ', OptOutAuthority::CATEGORIES)])];
	}//end validateMessageCategory()

	/**
	 * Whether an optional key is absent or holds a non-empty string.
	 *
	 * @param array<string, mixed> $spec The rule declaration.
	 * @param string               $key  The optional key.
	 *
	 * @return bool
	 */
	private function isAbsentOrNonEmptyString(array $spec, string $key): bool {
		if (array_key_exists($key, $spec) === false) {
			return true;
		}

		return is_string($spec[$key]) === true && $spec[$key] !== '';
	}//end isAbsentOrNonEmptyString()

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
		if ($this->isAbsentOrNonEmptyString(spec: $spec, key: 'originApp') === true) {
			return [];
		}

		return [$this->fault(fault: 'bad-origin-app', args: [$name])];
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

		if (is_string($subject) === false) {
			return [$this->fault(fault: 'subject-type', args: [$name])];
		}

		if ($subject !== '') {
			return [];
		}

		return [$this->fault(fault: 'subject-empty', args: [$name])];
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
		$errors        = $this->subjectLocaleErrors(name: $name, subject: $subject);
		$defaultLocale = $this->content->undeclaredDefaultLocale(map: $subject);
		if ($defaultLocale !== null) {
			$errors[] = $this->fault(fault: 'bad-default-locale', args: [$name, $defaultLocale]);
		}

		return $errors;
	}//end validateSubjectMap()

	/**
	 * Errors for the locales of a subject map: at least one locale (every string
	 * key but `defaultLocale`), each holding a non-empty string.
	 *
	 * @param string              $name    The rule key.
	 * @param array<mixed, mixed> $subject The declared subject map.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function subjectLocaleErrors(string $name, array $subject): array {
		$localeKeys = array_diff(array_filter(array_keys($subject), 'is_string'), ['defaultLocale']);
		$errors     = [];
		if ($localeKeys === []) {
			$errors[] = $this->fault(fault: 'subject-no-locale', args: [$name]);
		}

		foreach ($localeKeys as $localeKey) {
			if (is_string($subject[$localeKey]) === false || $subject[$localeKey] === '') {
				$errors[] = $this->fault(fault: 'bad-subject-locale', args: [$name, $localeKey]);
			}
		}

		return $errors;
	}//end subjectLocaleErrors()

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
			$errors[] = $this->fault(fault: 'webhook-no-url', args: [$name]);
		}

		if (in_array('talk', $channels, true) === true && $this->isTalkTokenMissing(talk: ($spec['talk'] ?? null)) === true) {
			$errors[] = $this->fault(fault: 'talk-no-token', args: [$name]);
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
		if ($this->isAbsentOrNonEmptyString(spec: $spec, key: 'domain') === true) {
			return [];
		}

		return [$this->fault(fault: 'domain-malformed', args: [$name])];
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
			$errors[] = $this->fault(fault: 'critical-not-boolean', args: [$name]);
		}

		if (array_key_exists('digest', $spec) === false) {
			return $errors;
		}

		return array_merge($errors, $this->digests->validate(spec: $spec, name: $name));
	}//end validateCriticalAndDigest()

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
