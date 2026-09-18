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

	private const VALID_TRIGGERS = ['created', 'updated', 'transition', 'scheduled', 'threshold', 'calculatedChange'];

	private const VALID_RECIPIENT_KINDS = ['users', 'field', 'groups', 'role', 'relation', 'object-acl', 'expression', 'watchers'];

	private const VALID_CHANNELS = ['nc-notification', 'email', 'activity', 'webhook', 'talk', 'web-push'];

	/**
	 * Transport kinds a rule may declare beside its channels.
	 */
	private const VALID_TRANSPORT_KINDS = ['outbound'];

	/**
	 * Valid action `target.kind` values (foundation contract / ADR-031 dialect).
	 *
	 * @var array<int, string>
	 */
	private const VALID_ACTION_TARGET_KINDS = ['object-detail', 'route', 'url', 'task-verb'];

	/**
	 * The lifecycle verbs a `task-verb` action target may name. A target
	 * names a VERB, never an author-composed URL, so a rule cannot aim a
	 * notification button anywhere it likes (flow-task-inbox-projections,
	 * design D-1).
	 *
	 * @var array<int, string>
	 */
	private const VALID_TASK_VERBS = ['claim', 'unclaim', 'complete', 'resolve', 'cancel'];

	/**
	 * Hard cap on declared action buttons — the Web Notification API renders
	 * at most two action buttons on the desktop OS popup.
	 *
	 * @var int
	 */
	private const MAX_ACTIONS = 2;

	/**
	 * Parses scheduled-trigger filters; the sole authority on what one may say.
	 *
	 * @var ScheduledFilterParser
	 */
	private ScheduledFilterParser $filterParser;

	/**
	 * Construct a validator.
	 *
	 * The parser is injectable but defaulted, because this class is constructed
	 * directly (`new NotificationAnnotationValidator()`) in several call sites
	 * that predate any container wiring.
	 *
	 * @param ScheduledFilterParser|null $filterParser Parser for scheduled filters.
	 */
	/**
	 * The administered decisions that are not preferences.
	 *
	 * @var ForcedChannelPolicy
	 */
	private ForcedChannelPolicy $forcedChannels;

	public function __construct(
		?ScheduledFilterParser $filterParser = null,
		?ForcedChannelPolicy $forcedChannels = null,
	) {
		$this->forcedChannels = ($forcedChannels ?? new ForcedChannelPolicy());
		$this->filterParser = ($filterParser ?? new ScheduledFilterParser());

	}//end __construct()

	/**
	 * Validate an app-defined trigger event name.
	 *
	 * An app event is namespaced with a dot — `booking.confirmed`,
	 * `generation.draft`, `lifecycle.optIn`. The dot is the whole safeguard, and
	 * the only part of the name this rule polices: it is required so an app event
	 * can never
	 * collide with a reserved trigger type, present or future: `created` is
	 * always the engine's, `booking.created` is always the app's. Without that
	 * rule, adding a trigger type later would silently capture somebody's
	 * event.
	 *
	 * @param string $ruleKey The notification rule key, for diagnostics.
	 * @param mixed  $event   The declared event name.
	 *
	 * @return array<int, array<string, mixed>> Errors, empty when the name is well-formed.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function validateAppEvent(string $ruleKey, $event): array {
		if (is_string($event) === false || $event === '') {
			return [
				[
					'code' => 'notification-bad-app-event',
					'ruleKey' => $ruleKey,
					'field' => 'trigger',
					'value' => $event,
					'message' => sprintf(
						'Notification "%s" declares an app event that is not a non-empty string.',
						$ruleKey
					),
				],
			];
		}

		if (in_array($event, self::VALID_TRIGGERS, true) === true) {
			return [
				[
					'code' => 'notification-app-event-reserved',
					'ruleKey' => $ruleKey,
					'field' => 'trigger',
					'value' => $event,
					'message' => sprintf(
						'Notification "%s" names "%s" as an app event, but that is a reserved trigger type; '
						. 'use the object form {"type": "%s"} instead.',
						$ruleKey,
						$event,
						$event
					),
				],
			];
		}

		if (preg_match('/^[a-z][A-Za-z0-9-]*(\.[A-Za-z0-9-]+)+$/', $event) !== 1) {
			return [
				[
					'code' => 'notification-bad-app-event',
					'ruleKey' => $ruleKey,
					'field' => 'trigger',
					'value' => $event,
					'message' => sprintf(
						'Notification "%s" app event "%s" must be namespaced with a dot and start lowercase '
						. '(e.g. "booking.confirmed", "lifecycle.optIn"), so it can never collide with a '
						. 'reserved trigger type.',
						$ruleKey,
						$event
					),
				],
			];
		}

		return [];

	}//end validateAppEvent()

	/**
	 * Validate the `x-openregister-notifications` annotation.
	 *
	 * @param array<string, mixed> $schema Full schema (must include `properties`).
	 *
	 * @return array<int, array{code: string, message: string}>
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid1/tasks.md#task-5
	 * @spec openspec/changes/openregister-web-push-engine/specs/notificatie-engine/spec.md
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

		$properties = ($schema['properties'] ?? []);
		$propKeys = [];
		if (is_array($properties) === true) {
			$propKeys = array_keys($properties);
		}

		// The role names this schema assigns, read from the same
		// `authorization.roles` map the permission handler expands. A rule may
		// only address a role the schema actually assigns; when the schema
		// carries no assignment at all the check stands down rather than
		// refusing every role, because the assignment may live on a register
		// this validator is not given.
		$declaredRoles = [];
		$authorization = ($schema['authorization'] ?? null);
		if (is_array($authorization) === true && is_array(($authorization['roles'] ?? null)) === true) {
			$declaredRoles = array_values(array_filter(array_keys($authorization['roles']), 'is_string'));
		}

		$errors = [];
		foreach ($notifications as $name => $spec) {
			if (is_string($name) === false || $name === '') {
				$errors[] = [
					'code' => 'notification-bad-name',
					'message' => 'Notification names must be non-empty strings.',
				];
				continue;
			}

			if (is_array($spec) === false) {
				$errors[] = [
					'code' => 'notification-malformed',
					'message' => sprintf('Notification "%s" must be an object.', $name),
				];
				continue;
			}

			$trigger = ($spec['trigger'] ?? null);
			$triggerType = '';
			$appEvent = null;
			if (is_array($trigger) === true) {
				$triggerType = (string)($trigger['type'] ?? '');
				if (isset($trigger['event']) === true) {
					$appEvent = $trigger['event'];
				}
			} elseif (is_string($trigger) === true) {
				// Bare-string shorthand for an app-defined event.
				$appEvent = $trigger;
			}

			if ($appEvent !== null) {
				foreach ($this->validateAppEvent(ruleKey: (string)$name, event: $appEvent) as $eventError) {
					$errors[] = $eventError;
				}
			} elseif (in_array($triggerType, self::VALID_TRIGGERS, true) === false) {
				$errors[] = [
					'code' => 'notification-bad-trigger',
					'message' => sprintf(
						'Notification "%s" trigger.type must be one of [%s], or the trigger must name an '
						. 'app-defined event as a namespaced string (e.g. "booking.confirmed").',
						$name,
						implode(', ', self::VALID_TRIGGERS)
					),
				];
			}

			if ($triggerType === 'scheduled') {
				$intervalSec = null;
				if (is_array($trigger) === true) {
					$intervalSec = ($trigger['intervalSec'] ?? null);
				}

				if (is_int($intervalSec) === false || $intervalSec < 60) {
					$errors[] = [
						'code' => 'notification-scheduled-bad-interval',
						'message' => sprintf(
							'Notification "%s" trigger.type=scheduled requires trigger.intervalSec (integer >= 60).',
							$name
						),
					];
				}

				// Validate the filter by parsing it with the same parser the
				// evaluator runs. This class enumerates no operators of its own:
				// while it did, it accepted "any array without an `operator` key"
				// as a scalar shortcut, which let 24 unexecutable filter entries
				// across three apps save cleanly and then match nothing.
				if (is_array($trigger) === true && isset($trigger['filter']) === true) {
					$filter = $trigger['filter'];
					if (is_array($filter) === false) {
						$errors[] = [
							'code' => 'notification-scheduled-bad-filter',
							'ruleKey' => $name,
							'field' => 'trigger.filter',
							'value' => $filter,
							'message' => sprintf(
								'Notification "%s" trigger.filter must be an object/map.',
								$name
							),
						];
					} else {
						// One parse of the whole map, not one call per entry:
						// `all` / `any` are whole-filter constructs, and parsing
						// here is what guarantees the shapes this validator
						// accepts are exactly the shapes the evaluator executes.
						$parsed = $this->filterParser->parse(filter: $filter, ruleKey: $name);
						foreach ($parsed['errors'] as $entryError) {
							$errors[] = $entryError;
						}
					}//end if
				}//end if

				if (is_array($trigger) === true && isset($trigger['dedupeFields']) === true) {
					$dedupeFields = $trigger['dedupeFields'];
					if (is_array($dedupeFields) === false || $dedupeFields === []) {
						$errors[] = [
							'code' => 'notification-scheduled-bad-dedupe-fields',
							'ruleKey' => $name,
							'field' => 'trigger.dedupeFields',
							'value' => $dedupeFields,
							'message' => sprintf(
								'Notification "%s" trigger.dedupeFields must be a non-empty array of strings.',
								$name
							),
						];
					} else {
						foreach ($dedupeFields as $idx => $field) {
							if (is_string($field) === false || $field === '') {
								$errors[] = [
									'code' => 'notification-scheduled-bad-dedupe-fields',
									'ruleKey' => $name,
									'field' => sprintf('trigger.dedupeFields[%d]', (int)$idx),
									'value' => $field,
									'message' => sprintf(
										'Notification "%s" trigger.dedupeFields entries must be non-empty strings.',
										$name
									),
								];
								break;
							}
						}//end foreach
					}//end if
				}//end if
			}//end if

			if ($triggerType === 'threshold') {
				$aggregation = '';
				$op = '';
				if (is_array($trigger) === true) {
					$aggregation = (string)($trigger['aggregation'] ?? '');
					$op = (string)($trigger['op'] ?? '');
				}

				if ($aggregation === '') {
					$errors[] = [
						'code' => 'notification-threshold-no-aggregation',
						'message' => sprintf(
							'Notification "%s" trigger.type=threshold requires trigger.aggregation referencing a declared aggregation.',
							$name
						),
					];
				}

				if (in_array($op, ['gt', 'gte', 'lt', 'lte', 'eq', 'ne'], true) === false) {
					$errors[] = [
						'code' => 'notification-threshold-bad-op',
						'message' => sprintf(
							'Notification "%s" trigger.type=threshold trigger.op must be one of [gt, gte, lt, lte, eq, ne]; got "%s".',
							$name,
							$op
						),
					];
				}

				if (is_array($trigger) === true && array_key_exists('value', $trigger) === false) {
					$errors[] = [
						'code' => 'notification-threshold-no-value',
						'message' => sprintf(
							'Notification "%s" trigger.type=threshold requires trigger.value.',
							$name
						),
					];
				}
			}//end if

			if ($triggerType === 'calculatedChange') {
				$field = null;
				if (is_array($trigger) === true) {
					$field = ($trigger['field'] ?? null);
				}

				if (is_string($field) === false || $field === '') {
					$errors[] = [
						'code' => 'notification-calculated-change-no-field',
						'message' => sprintf(
							'Notification "%s" trigger.type=calculatedChange requires trigger.field (non-empty string).',
							$name
						),
					];
				}

				$validOps = ['lt', 'lte', 'gt', 'gte', 'eq', 'ne'];

				foreach (['condition', 'previously'] as $clauseKey) {
					$clause = null;
					if (is_array($trigger) === true) {
						$clause = ($trigger[$clauseKey] ?? null);
					}

					if ($clause === null) {
						continue;
					}

					if (is_array($clause) === false || count($clause) === 0) {
						$errors[] = [
							'code' => 'notification-calculated-change-bad-clause',
							'message' => sprintf(
								'Notification "%s" trigger.%s must be a non-empty operator map.',
								$name,
								$clauseKey
							),
						];
						continue;
					}

					foreach (array_keys($clause) as $op) {
						if (in_array((string)$op, $validOps, true) === false) {
							$errors[] = [
								'code' => 'notification-calculated-change-bad-op',
								'message' => sprintf(
									'Notification "%s" trigger.%s operator "%s" must be one of [%s].',
									$name,
									$clauseKey,
									(string)$op,
									implode(', ', $validOps)
								),
							];
						}
					}
				}//end foreach
			}//end if

			// The two administered decisions that are not preferences
			// (notification-kinds-an-administrator-forces). Both fail SILENTLY
			// at send time: a force with no reason renders as a preference a
			// user cannot explain, and an internal kind whose only channels
			// leave the organisation renders as a kind that never sends. The
			// rules live in ForcedChannelPolicy so the save and the send read
			// one interpretation of them rather than two.
			foreach ($this->forcedChannels->validate(declaration: $spec) as $forcedError) {
				$errors[] = [
					'code' => $forcedError['code'],
					'message' => sprintf('Notification "%s": %s', $name, $forcedError['message']),
				];
			}

			$channels = ($spec['channels'] ?? []);
			if (is_array($channels) === false || count($channels) === 0) {
				$errors[] = [
					'code' => 'notification-channels-empty',
					'message' => sprintf(
						'Notification "%s" must declare at least one channel.',
						$name
					),
				];
				$channels = [];
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

			// Optional `originApp` (foundation contract / ADR-031): identifies
			// the leaf app that owns the rule. Drives the notification
			// icon/badge and the deeplink base. When present it MUST be a
			// non-empty string; absence is valid (defaults to the
			// register-owning app at dispatch).
			if (array_key_exists('originApp', $spec) === true) {
				$originApp = $spec['originApp'];
				if (is_string($originApp) === false || $originApp === '') {
					$errors[] = [
						'code' => 'notification-bad-origin-app',
						'message' => sprintf(
							'Notification "%s" originApp must be a non-empty string app id.',
							$name
						),
					];
				}
			}

			// Optional `actions[]` (foundation contract / ADR-031): rich
			// action buttons rendered in the OS notification. Hard cap of 2
			// (Web Notification API desktop limit). Each action declares an
			// i18n `label` map, an optional `primary` bool, and a `target`
			// of kind object-detail | route | url.
			if (array_key_exists('actions', $spec) === true) {
				foreach ($this->validateActions(actions: $spec['actions'], name: $name) as $actionError) {
					$errors[] = $actionError;
				}
			}

			// `subject` accepts either a single template string OR a
			// per-locale map ({nl: "...", en: "..."} optionally prefixed
			// with `defaultLocale: <code>`). The dispatcher resolves
			// the active recipient's locale at delivery time; the
			// broadcast channels (webhook/talk) use the default locale
			// fallback chain.
			$subject = ($spec['subject'] ?? null);
			$subjectString = is_string($subject) === true;
			$subjectArray = is_array($subject) === true;
			if ($subjectString === false && $subjectArray === false) {
				$errors[] = [
					'code' => 'notification-no-subject',
					'message' => sprintf(
						'Notification "%s" requires a subject string or per-locale map.',
						$name
					),
				];
			}

			if ($subjectString === true && $subject === '') {
				$errors[] = [
					'code' => 'notification-no-subject',
					'message' => sprintf(
						'Notification "%s" requires a non-empty subject string.',
						$name
					),
				];
			}

			if ($subjectArray === true) {
				$localeKeys = array_filter(
					array_keys($subject),
					static fn ($key): bool => $key !== 'defaultLocale' && is_string($key) === true
				);
				if (count($localeKeys) === 0) {
					$errors[] = [
						'code' => 'notification-no-subject',
						'message' => sprintf(
							'Notification "%s" subject map must declare at least one locale (e.g. nl, en).',
							$name
						),
					];
				}

				foreach ($localeKeys as $localeKey) {
					if (is_string($subject[$localeKey]) === false || $subject[$localeKey] === '') {
						$errors[] = [
							'code' => 'notification-bad-subject-locale',
							'message' => sprintf(
								'Notification "%s" subject for locale "%s" must be a non-empty string.',
								$name,
								$localeKey
							),
						];
					}
				}

				if (isset($subject['defaultLocale']) === true) {
					$defaultLocale = $subject['defaultLocale'];
					$defaultLocaleBad = is_string($defaultLocale) === false;
					$defaultLocaleBad |= isset($subject[$defaultLocale]) === false;
					if ((bool)$defaultLocaleBad === true) {
						$errors[] = [
							'code' => 'notification-bad-default-locale',
							'message' => sprintf(
								'Notification "%s" defaultLocale "%s" is not declared in the subject map.',
								$name,
								(string)$defaultLocale
							),
						];
					}
				}
			}//end if

			// Optional `message` (foundation contract / ADR-031): the
			// notification BODY, distinct from the title (`subject`). Same
			// shape + locale-resolution + `{{prop}}` interpolation as
			// `subject` — a single template string OR a per-locale map.
			// Absence is valid (back-compat): rules with `actions` get an
			// auto-derived "Open in {AppName}." body, rules without get an
			// empty body. Malformed → notification-bad-message.
			if (array_key_exists('message', $spec) === true) {
				foreach ($this->validateMessage(message: $spec['message'], name: $name) as $messageError) {
					$errors[] = $messageError;
				}
			}

			// When the `webhook` channel is declared, the spec MUST include a `webhook.url` value.
			if (in_array('webhook', $channels, true) === true) {
				$hook = ($spec['webhook'] ?? null);
				$hookBad = is_array($hook) === false;
				if ($hookBad === false) {
					$hookBad = empty($hook['url']) === true
						|| filter_var($hook['url'], FILTER_VALIDATE_URL) === false;
				}

				if ($hookBad === true) {
					$errors[] = [
						'code' => 'notification-webhook-no-url',
						'message' => sprintf(
							'Notification "%s" declares the `webhook` channel but webhook.url is missing or malformed.',
							$name
						),
					];
				}
			}

			// When the `talk` channel is declared, the spec MUST include a `talk.token`.
			if (in_array('talk', $channels, true) === true) {
				$talk = ($spec['talk'] ?? null);
				$talkBad = is_array($talk) === false;
				if ($talkBad === false) {
					$talkBad = empty($talk['token']) === true || is_string($talk['token']) === false;
				}

				if ($talkBad === true) {
					$errors[] = [
						'code' => 'notification-talk-no-token',
						'message' => sprintf(
							'Notification "%s" declares the `talk` channel but talk.token is missing or not a string.',
							$name
						),
					];
				}
			}

			// Optional `organisation` gate — the dispatcher skips this
			// notification unless the saved object's organisation
			// matches. Accepts a single string (UUID or slug) or an
			// array of strings (any-of). Closes the spec's
			// "Notifications MUST be scoped to organisations" item by
			// letting schema authors pin a rule explicitly without
			// writing a custom expression resolver.
			if (array_key_exists('organisation', $spec) === true) {
				$orgError = $this->validateOrganisationGate(org: $spec['organisation'], name: $name);
				if ($orgError !== null) {
					$errors[] = $orgError;
				}
			}

			// Optional `domain` — the leaf app's own word for a slice of its
			// work, which a preference may be scoped to. Only its shape is
			// checked: the vocabulary belongs to the app, not to this engine.
			if (array_key_exists('domain', $spec) === true
				&& (is_string($spec['domain']) === false || $spec['domain'] === '')
			) {
				$errors[] = [
					'code' => 'notification-domain-malformed',
					'message' => sprintf('Notification "%s" domain must be a non-empty string.', $name),
				];
			}

			// Optional `transports` — the outbound calls this rule runs in the
			// same firing as its notices. Refused at save when malformed,
			// because a transport that names no handler is an integration
			// nobody will notice is missing.
			if (array_key_exists('transports', $spec) === true) {
				$errors = array_merge($errors, $this->validateTransports(transports: $spec['transports'], name: $name));
			}

			// Optional `critical` bypass flag and optional fixed-time
			// `digest` schedule (notification-delivery-windows dialect
			// additions). See "Users MUST be able to manage their
			// notification preferences" / "Notifications MUST support
			// batching and digest delivery" in the notificatie-engine spec.
			$errors = array_merge($errors, $this->validateCriticalAndDigest(spec: $spec, name: $name));

			$recipients = ($spec['recipients'] ?? []);
			if (is_array($recipients) === false || count($recipients) === 0) {
				$errors[] = [
					'code' => 'notification-no-recipients',
					'message' => sprintf(
						'Notification "%s" must declare at least one recipient.',
						$name
					),
				];
				continue;
			}

			foreach ($recipients as $i => $recipient) {
				if (is_array($recipient) === false) {
					$errors[] = [
						'code' => 'notification-recipient-malformed',
						'message' => sprintf(
							'Notification "%s" recipient[%d] must be an object.',
							$name,
							$i
						),
					];
					continue;
				}

				$kind = (string)($recipient['kind'] ?? '');

				// The watchers block is spelled `{"watchers": true}` — it names
				// a subscription list rather than a value to look up, so it
				// carries no `kind`. Anything but boolean true is refused
				// rather than coerced: `"yes"` is how a rule quietly addresses
				// nobody, and a rule that addresses nobody should say so at
				// save time, not at midnight.
				if (array_key_exists('watchers', $recipient) === true) {
					if ($recipient['watchers'] !== true) {
						$errors[] = [
							'code' => 'notification-recipient-watchers-not-true',
							'message' => sprintf(
								'Notification "%s" recipient[%d] watchers must be boolean true.',
								$name,
								$i
							),
						];
						continue;
					}

					$kind = 'watchers';
				}

				if (in_array($kind, self::VALID_RECIPIENT_KINDS, true) === false) {
					$errors[] = [
						'code' => 'notification-bad-recipient-kind',
						'message' => sprintf(
							'Notification "%s" recipient[%d] kind "%s" not in [%s].',
							$name,
							$i,
							$kind,
							implode(', ', self::VALID_RECIPIENT_KINDS)
						),
					];
					continue;
				}

				if ($kind === 'field') {
					$field = (string)($recipient['field'] ?? '');
					if ($field === '' || in_array($field, $propKeys, true) === false) {
						$errors[] = [
							'code' => 'notification-recipient-field-unknown',
							'message' => sprintf(
								'Notification "%s" recipient[%d] field "%s" is not declared on the schema.',
								$name,
								$i,
								$field
							),
						];
					}
				}

				if ($kind === 'object-acl') {
					$perm = (string)($recipient['permission'] ?? '');
					if (in_array($perm, ['read', 'manage'], true) === false) {
						$errors[] = [
							'code' => 'notification-recipient-acl-bad-permission',
							'message' => sprintf(
								'Notification "%s" recipient[%d] kind=object-acl requires permission in [read, manage]; got "%s".',
								$name,
								$i,
								$perm
							),
						];
					}
				}

				if ($kind === 'role') {
					// A role entry that names no role addresses nobody. Refusing
					// it at save is the same posture the dispatcher takes at
					// midnight: a rule that reaches nobody says so, here first.
					$role = (string)($recipient['role'] ?? '');
					if ($role === '') {
						$errors[] = [
							'code' => 'notification-recipient-role-missing',
							'message' => sprintf(
								'Notification "%s" recipient[%d] kind=role requires a non-empty role name.',
								$name,
								$i
							),
						];
					} elseif (in_array($role, $declaredRoles, true) === false && $declaredRoles !== []) {
						$errors[] = [
							'code' => 'notification-recipient-role-unknown',
							'message' => sprintf(
								'Notification "%s" recipient[%d] role "%s" is not assigned by the schema authorization; declared: [%s].',
								$name,
								$i,
								$role,
								implode(', ', $declaredRoles)
							),
						];
					}
				}

				if ($kind === 'expression') {
					$resolver = (string)($recipient['resolver'] ?? '');
					if ($resolver === '') {
						$errors[] = [
							'code' => 'notification-recipient-expression-no-resolver',
							'message' => sprintf(
								'Notification "%s" recipient[%d] kind=expression requires a resolver string (DI tag or FQCN).',
								$name,
								$i
							),
						];
					}
				}
			}//end foreach
		}//end foreach

		return $errors;
	}//end validate()

	/**
	 * Validate the optional `actions[]` array (foundation contract / ADR-031).
	 *
	 * Rules:
	 *  - `actions` MUST be an array; more than 2 entries → notification-too-many-actions.
	 *  - each action's `label` MUST be a per-locale map with at least one
	 *    non-empty locale value → otherwise notification-action-bad-label.
	 *  - each action's `target.kind` MUST be one of object-detail | route | url | task-verb
	 *    → otherwise notification-action-bad-target.
	 *  - a `task-verb` target MUST name a known lifecycle verb in `verb`
	 *    → otherwise notification-action-bad-target, naming the value.
	 *
	 * @param mixed $actions Raw value of the `actions` key.
	 * @param string $name Notification name (for diagnostics).
	 *
	 * @return array<int, array{code: string, message: string}>
	 *
	 * @spec openspec/changes/openregister-web-push-engine/specs/notificatie-engine/spec.md
	 */
	private function validateActions(mixed $actions, string $name): array {
		$errors = [];

		if (is_array($actions) === false) {
			return [
				[
					'code' => 'notification-action-bad-target',
					'message' => sprintf('Notification "%s" actions must be an array.', $name),
				],
			];
		}

		if (count($actions) > self::MAX_ACTIONS) {
			$errors[] = [
				'code' => 'notification-too-many-actions',
				'message' => sprintf(
					'Notification "%s" declares %d actions; the Web Notification API renders at most %d.',
					$name,
					count($actions),
					self::MAX_ACTIONS
				),
			];
		}

		foreach ($actions as $idx => $action) {
			if (is_array($action) === false) {
				$errors[] = [
					'code' => 'notification-action-bad-target',
					'message' => sprintf('Notification "%s" action[%s] must be an object.', $name, (string)$idx),
				];
				continue;
			}

			// Label MUST be a per-locale map with at least one non-empty string value.
			$label = ($action['label'] ?? null);
			$labelOk = false;
			if (is_array($label) === true && count($label) > 0) {
				foreach ($label as $localeValue) {
					if (is_string($localeValue) === true && $localeValue !== '') {
						$labelOk = true;
						break;
					}
				}
			}

			if ($labelOk === false) {
				$errors[] = [
					'code' => 'notification-action-bad-label',
					'message' => sprintf(
						'Notification "%s" action[%s] label must be a per-locale map with at least one non-empty value.',
						$name,
						(string)$idx
					),
				];
			}

			// `primary` (optional) MUST be a boolean when present.
			if (array_key_exists('primary', $action) === true && is_bool($action['primary']) === false) {
				$errors[] = [
					'code' => 'notification-action-bad-target',
					'message' => sprintf(
						'Notification "%s" action[%s] primary must be a boolean.',
						$name,
						(string)$idx
					),
				];
			}

			// Target MUST be an object with a recognised kind.
			$target = ($action['target'] ?? null);
			$targetKind = '';
			if (is_array($target) === true) {
				$targetKind = (string)($target['kind'] ?? '');
			}

			if (in_array($targetKind, self::VALID_ACTION_TARGET_KINDS, true) === false) {
				$errors[] = [
					'code' => 'notification-action-bad-target',
					'message' => sprintf(
						'Notification "%s" action[%s] target.kind "%s" is not in [%s].',
						$name,
						(string)$idx,
						$targetKind,
						implode(', ', self::VALID_ACTION_TARGET_KINDS)
					),
				];
				continue;
			}

			if ($targetKind === 'task-verb') {
				$errors = array_merge($errors, $this->validateTaskVerbTarget(target: $target, name: $name, idx: (string)$idx));
			}
		}//end foreach

		return $errors;
	}//end validateActions()

	/**
	 * Validate a `task-verb` action target: it MUST name a lifecycle verb from
	 * the closed list, never an author-composed URL.
	 *
	 * @param mixed $target The raw target (already known to be kind task-verb).
	 * @param string $name Notification name (for diagnostics).
	 * @param string $idx Action index (for diagnostics).
	 *
	 * @return array<int, array{code: string, message: string}>
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-a-binary-decision-is-decidable-from-the-notification
	 */
	private function validateTaskVerbTarget(mixed $target, string $name, string $idx): array {
		$verb = '';
		if (is_array($target) === true) {
			$verb = (string)($target['verb'] ?? '');
		}

		if (in_array($verb, self::VALID_TASK_VERBS, true) === true) {
			return [];
		}

		return [
			[
				'code' => 'notification-action-bad-target',
				'message' => sprintf(
					'Notification "%s" action[%s] target.verb "%s" is not a task lifecycle verb in [%s].',
					$name,
					$idx,
					$verb,
					implode(', ', self::VALID_TASK_VERBS)
				),
			],
		];
	}//end validateTaskVerbTarget()

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
	 * @param string $name Notification name (for diagnostics).
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

		$digest = $spec['digest'];
		if (is_array($digest) === false) {
			$errors[] = [
				'code' => 'notification-digest-malformed',
				'message' => sprintf('Notification "%s" `digest` must be an object.', $name),
			];
			return $errors;
		}

		$schedule = ($digest['schedule'] ?? null);
		if (in_array($schedule, ['daily', 'weekly'], true) === false) {
			$errors[] = [
				'code' => 'notification-digest-bad-schedule',
				'message' => sprintf(
					'Notification "%s" `digest.schedule` must be "daily" or "weekly".',
					$name
				),
			];
		}

		$at = ($digest['at'] ?? null);
		if (is_string($at) === false || preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $at) !== 1) {
			$errors[] = [
				'code' => 'notification-digest-bad-time',
				'message' => sprintf('Notification "%s" `digest.at` must be an "HH:MM" time string.', $name),
			];
		}

		$hasWeekday = array_key_exists('weekday', $digest);
		if ($schedule === 'weekly') {
			$weekday = ($digest['weekday'] ?? null);
			if ($hasWeekday === false || is_int($weekday) === false || $weekday < 0 || $weekday > 6) {
				$errors[] = [
					'code' => 'notification-digest-weekly-missing-weekday',
					'message' => sprintf(
						'Notification "%s" `digest.weekday` (0-6) is required when `schedule` is "weekly".',
						$name
					),
				];
			}
		} elseif ($hasWeekday === true) {
			$errors[] = [
				'code' => 'notification-digest-weekday-not-allowed',
				'message' => sprintf(
					'Notification "%s" `digest.weekday` is only allowed when `schedule` is "weekly".',
					$name
				),
			];
		}

		$timezone = ($digest['timezone'] ?? null);
		if ($timezone !== null) {
			$timezoneValid = false;
			if (is_string($timezone) === true && $timezone !== '') {
				try {
					new DateTimeZone($timezone);
					$timezoneValid = true;
				} catch (\Throwable $e) {
					$timezoneValid = false;
				}
			}

			if ($timezoneValid === false) {
				$errors[] = [
					'code' => 'notification-digest-bad-timezone',
					'message' => sprintf(
						'Notification "%s" `digest.timezone` must be a valid IANA timezone name.',
						$name
					),
				];
			}
		}//end if

		// Mutually exclusive with the rolling `coalesce` window — a rule
		// picks one "hold and batch" mechanism, not both.
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
	 * Validate the optional `message` field (notification body).
	 *
	 * Mirrors the `subject` shape contract exactly: either a single
	 * non-empty template string OR a per-locale map ({nl: "...", en: "..."}
	 * optionally prefixed with `defaultLocale: <code>`). Every malformed
	 * shape returns a single `notification-bad-message` error so leaf
	 * authors get one canonical error code for a broken body template.
	 *
	 * @param mixed $message Raw value of the `message` key.
	 * @param string $name Notification name (for diagnostics).
	 *
	 * @return array<int, array{code: string, message: string}>
	 *
	 * @spec openspec/changes/openregister-notification-body/specs/notificatie-engine/spec.md
	 */
	private function validateMessage(mixed $message, string $name): array {
		$code = 'notification-bad-message';

		if (is_string($message) === true) {
			if ($message === '') {
				return [
					[
						'code' => $code,
						'message' => sprintf(
							'Notification "%s" message must be a non-empty string when present.',
							$name
						),
					],
				];
			}

			return [];
		}

		if (is_array($message) === false) {
			return [
				[
					'code' => $code,
					'message' => sprintf(
						'Notification "%s" message must be a string or a per-locale map.',
						$name
					),
				],
			];
		}

		$errors = [];
		$localeKeys = array_filter(
			array_keys($message),
			static fn ($key): bool => $key !== 'defaultLocale' && is_string($key) === true
		);
		if (count($localeKeys) === 0) {
			$errors[] = [
				'code' => $code,
				'message' => sprintf(
					'Notification "%s" message map must declare at least one locale (e.g. nl, en).',
					$name
				),
			];
		}

		foreach ($localeKeys as $localeKey) {
			if (is_string($message[$localeKey]) === false || $message[$localeKey] === '') {
				$errors[] = [
					'code' => $code,
					'message' => sprintf(
						'Notification "%s" message for locale "%s" must be a non-empty string.',
						$name,
						$localeKey
					),
				];
			}
		}

		if (isset($message['defaultLocale']) === true) {
			$defaultLocale = $message['defaultLocale'];
			$defaultLocaleBad = is_string($defaultLocale) === false;
			$defaultLocaleBad |= isset($message[$defaultLocale]) === false;
			if ((bool)$defaultLocaleBad === true) {
				$errors[] = [
					'code' => $code,
					'message' => sprintf(
						'Notification "%s" message defaultLocale "%s" is not declared in the message map.',
						$name,
						(string)$defaultLocale
					),
				];
			}
		}

		return $errors;
	}//end validateMessage()

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
	 * @spec openspec/changes/notification-routing-per-group-and-scope/specs/notificatie-engine/spec.md#requirement-one-rule-reaches-a-person-and-an-integration-recorded-once-req-nrg-004
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
