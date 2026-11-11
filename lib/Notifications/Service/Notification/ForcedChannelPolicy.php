<?php

/**
 * The two notification decisions that are not preferences.
 *
 * 🔑 A PREFERENCE ANSWERS WHAT A PERSON WANTS. Two things a municipality needs
 * are not that: a kind that always goes out on a named channel because the law
 * or the process says so, and a kind that must never reach a party outside the
 * organisation. Both are administered, and today every kind is a preference a
 * user can switch off.
 *
 * 🔑 THIS ADDS A LAYER, NOT A DISPATCHER. `NotificationPreferenceService`
 * already resolves a winner through schema default, group default and the
 * user's own value, and reports the layers it walked. A forced channel is one
 * more layer ABOVE the user's, and it is applied here so the existing
 * dispatcher keeps being the only thing that sends. A second sender would be a
 * second interpretation of the canonical dialect gate 18 enforces.
 *
 * 🔴 A FORCED CHANNEL CARRIES ITS REASON, AND THE REASON TRAVELS. A user who
 * cannot switch a notification off is owed the sentence that says why, and an
 * administrator reading the effective preferences is owed the same one. A
 * force with no reason is indistinguishable from a bug in the preference
 * merge, so it is refused at save rather than rendered as a mystery.
 *
 * 🔴 A REFUSED EXTERNAL DISPATCH IS RECORDED, NEVER DROPPED. An internal kind
 * that reaches an outside recipient must not send, and it must not fail
 * silently either: silence here is indistinguishable from a notification
 * nobody configured, and the difference matters the day somebody asks why the
 * applicant was never told.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/notification-kinds-an-administrator-forces/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

/**
 * Applies an administrator's forced channels and internal-only rule.
 *
 * @spec openspec/changes/notification-kinds-an-administrator-forces/specs/notificatie-engine/spec.md
 */
class ForcedChannelPolicy {

	/**
	 * The notification key declaring channels a user cannot switch off.
	 *
	 * @var string
	 */
	public const FORCED = 'forcedChannels';

	/**
	 * The notification key marking a kind that never leaves the organisation.
	 *
	 * @var string
	 */
	public const INTERNAL_ONLY = 'internalOnly';

	/**
	 * The layer name a forced decision is reported under.
	 *
	 * It sits above `user-override`, which is the whole point: the existing
	 * layers are preferences and this one is not.
	 *
	 * @var string
	 */
	public const LAYER = 'administrator-forced';

	/**
	 * Channels that can only reach somebody outside the organisation.
	 *
	 * A Nextcloud notification and an activity entry are accounts on this
	 * instance by construction, so they cannot carry an internal kind out of
	 * it. E-mail, a webhook and web push can.
	 *
	 * @var array<int,string>
	 */
	public const EXTERNAL_CAPABLE = ['email', 'webhook', 'web-push'];

	/**
	 * Why an internal kind was not sent.
	 *
	 * @var string
	 */
	public const REFUSED_EXTERNAL = 'internal-only-recipient-outside-organisation';

	/**
	 * The save refusal of a forced channel declared without its reason.
	 *
	 * @var array{code:string,message:string}
	 */
	private const FORCED_WITHOUT_REASON = [
		'code' => 'notification-forced-channel-without-reason',
		'message' => 'A forced channel needs the reason it is forced: a user who cannot switch a notification off is owed the sentence that says why.',
	];

	/**
	 * The save refusal of an internal-only kind whose every channel can leave the organisation.
	 *
	 * @var array{code:string,message:string}
	 */
	private const NO_INTERNAL_CHANNEL = [
		'code' => 'notification-internal-only-has-no-internal-channel',
		'message' => 'An internal-only kind declares only channels that can leave the organisation, so it would never send at all.',
	];

	/**
	 * The effective decision for one recipient and one kind.
	 *
	 * The audience is REQUIRED and is an enum rather than a boolean with a
	 * default. The default used to be "inside the organisation", so a caller
	 * that forgot the argument got the permissive half of the pair and the
	 * `internalOnly` refusal below never fired. See {@see RecipientAudience}.
	 *
	 * @param KindPreference    $preference What `NotificationPreferenceService::resolveEffective()` returned,
	 *                                      with the notification's own declaration from the schema.
	 * @param RecipientAudience $audience   Which side of the organisation this recipient is on.
	 *
	 * @return array{enabled:bool,channels:array<int,string>,forced:bool,reason:string,layer:string,refusal:string}
	 *         What will be sent, on what, who decided it, and why nothing is sent when nothing is.
	 *
	 * @spec openspec/changes/notification-kinds-an-administrator-forces/specs/notificatie-engine/spec.md
	 */
	public function decide(KindPreference $preference, RecipientAudience $audience): array {
		$decision = $this->forcedOver(preference: $preference);
		if (($preference->declaration[self::INTERNAL_ONLY] ?? false) !== true) {
			return $this->withEnabled(decision: $decision);
		}

		if ($audience->isInternal() === false) {
			return $this->refusedOutside(decision: $decision);
		}

		// An internal kind never goes out on a channel that can leave the
		// organisation, even to somebody inside it: the channel is the
		// leak, not the recipient. A webhook fires at whatever URL an
		// administrator configured.
		$decision['channels'] = array_values(array_filter(
			$decision['channels'],
			static fn (string $channel): bool => in_array($channel, self::EXTERNAL_CAPABLE, true) === false
		));
		return $this->withEnabled(decision: $decision);
	}//end decide()

	/**
	 * The resolved preference with the administrator's forced channels applied.
	 *
	 * The forced channels are ADDED to whatever the preference chose, not
	 * substituted for it. A person who also asked for e-mail keeps e-mail; what
	 * they cannot do is remove the channel the process requires.
	 *
	 * @param KindPreference $preference The resolved preference and the kind's declaration.
	 *
	 * @return array{enabled:bool,channels:array<int,string>,forced:bool,reason:string,layer:string,refusal:string}
	 */
	private function forcedOver(KindPreference $preference): array {
		$declaration = $preference->declaration;
		$forcedChannels = $this->channelsOf(value: ($declaration[self::FORCED]['channels'] ?? ($declaration[self::FORCED] ?? [])));
		$decision = [
			'enabled' => (($preference->resolved['enabled'] ?? true) === true),
			'channels' => $this->channelsOf(value: ($preference->resolved['channels'] ?? [])),
			'forced' => ($forcedChannels !== []),
			'reason' => trim((string)($declaration[self::FORCED]['reason'] ?? '')),
			'layer' => (string)($preference->resolved['source'] ?? 'schema-default'),
			'refusal' => '',
		];
		if ($decision['forced'] === true) {
			$decision['channels'] = array_values(array_unique(array_merge($decision['channels'], $forcedChannels)));
			$decision['enabled'] = true;
			$decision['layer'] = self::LAYER;
		}

		return $decision;
	}//end forcedOver()

	/**
	 * A decision is enabled only while it still has a channel to send on.
	 *
	 * @param array{enabled:bool,channels:array<int,string>,forced:bool,reason:string,layer:string,refusal:string} $decision The decision.
	 *
	 * @return array{enabled:bool,channels:array<int,string>,forced:bool,reason:string,layer:string,refusal:string}
	 */
	private function withEnabled(array $decision): array {
		$decision['enabled'] = ($decision['enabled'] === true && $decision['channels'] !== []);
		return $decision;
	}//end withEnabled()

	/**
	 * The refusal of an internal kind for a recipient outside the organisation.
	 *
	 * Refused, and the refusal is the answer rather than an empty channel list:
	 * a caller that received no channels and no reason cannot tell this from a
	 * kind nobody configured. The refusal is always the internal-only layer's.
	 *
	 * @param array{enabled:bool,channels:array<int,string>,forced:bool,reason:string,layer:string,refusal:string} $decision The decision so far.
	 *
	 * @return array{enabled:bool,channels:array<int,string>,forced:bool,reason:string,layer:string,refusal:string}
	 */
	private function refusedOutside(array $decision): array {
		return [
			'enabled' => false,
			'channels' => [],
			'forced' => $decision['forced'],
			'reason' => $decision['reason'],
			'layer' => self::LAYER,
			'refusal' => self::REFUSED_EXTERNAL,
		];
	}//end refusedOutside()

	/**
	 * What is wrong with a declaration, or an empty list when nothing is.
	 *
	 * Checked at schema save, because both failures are silent at send time: a
	 * force with no reason renders as a preference a user cannot explain, and
	 * an internal kind whose only channels leave the organisation renders as a
	 * kind that never sends at all.
	 *
	 * @param array<string,mixed> $declaration The notification's declaration.
	 *
	 * @return array<int,array{code:string,message:string}> The refusals.
	 *
	 * @spec openspec/changes/notification-kinds-an-administrator-forces/specs/notificatie-engine/spec.md
	 */
	public function validate(array $declaration): array {
		$errors = [];
		$forcedChannels = $this->channelsOf(value: ($declaration[self::FORCED]['channels'] ?? ($declaration[self::FORCED] ?? [])));
		$reason = trim((string)($declaration[self::FORCED]['reason'] ?? ''));

		if ($forcedChannels !== [] && $reason === '') {
			$errors[] = self::FORCED_WITHOUT_REASON;
		}

		$internalOnly = (($declaration[self::INTERNAL_ONLY] ?? false) === true);
		if ($internalOnly === false) {
			return $errors;
		}

		return array_merge($errors, $this->internalOnlyErrors(declaration: $declaration, forcedChannels: $forcedChannels));
	}//end validate()

	/**
	 * What is wrong with an internal-only declaration's channels.
	 *
	 * @param array<string,mixed> $declaration    The notification's declaration.
	 * @param array<int,string>   $forcedChannels Its forced channels.
	 *
	 * @return array<int,array{code:string,message:string}> The refusals.
	 */
	private function internalOnlyErrors(array $declaration, array $forcedChannels): array {
		$errors = [];
		$declared = $this->channelsOf(value: ($declaration['channels'] ?? []));
		$usable = $this->internalChannels(channels: array_merge($declared, $forcedChannels));
		if ($usable === []) {
			$errors[] = self::NO_INTERNAL_CHANNEL;
		}

		return array_merge($errors, $this->forcedExternalErrors(forcedChannels: $forcedChannels));
	}//end internalOnlyErrors()

	/**
	 * The channels that cannot leave the organisation, in their order.
	 *
	 * @param array<int,string> $channels The channels.
	 *
	 * @return array<int,string> The channels not in EXTERNAL_CAPABLE.
	 */
	private function internalChannels(array $channels): array {
		return array_values(array_filter(
			$channels,
			static fn (string $channel): bool => in_array($channel, self::EXTERNAL_CAPABLE, true) === false
		));
	}//end internalChannels()

	/**
	 * The channels that can leave the organisation, in their order.
	 *
	 * @param array<int,string> $channels The channels.
	 *
	 * @return array<int,string> The channels in EXTERNAL_CAPABLE.
	 */
	private function externalChannels(array $channels): array {
		return array_values(array_filter(
			$channels,
			static fn (string $channel): bool => in_array($channel, self::EXTERNAL_CAPABLE, true) === true
		));
	}//end externalChannels()

	/**
	 * The refusal of an internal-only kind that forces a channel able to leave the organisation.
	 *
	 * @param array<int,string> $forcedChannels The forced channels.
	 *
	 * @return array<int,array{code:string,message:string}> The refusal, or nothing.
	 */
	private function forcedExternalErrors(array $forcedChannels): array {
		$forcedExternal = $this->externalChannels(channels: $forcedChannels);
		if ($forcedExternal === []) {
			return [];
		}

		return [
			[
				'code' => 'notification-internal-only-forces-external-channel',
				'message' => sprintf(
					'An internal-only kind forces %s, which can carry it outside the organisation. The two declarations contradict each other.',
					implode(', ', $forcedExternal)
				),
			],
		];
	}//end forcedExternalErrors()

	/**
	 * A channel list, however the declaration spells it.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return array<int,string> The channels.
	 */
	private function channelsOf(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$channels = [];
		foreach ($value as $channel) {
			if (is_string($channel) === false) {
				continue;
			}

			$channel = trim($channel);
			if ($channel !== '' && in_array($channel, $channels, true) === false) {
				$channels[] = $channel;
			}
		}

		return $channels;
	}//end channelsOf()
}//end class
