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
 * @spec openspec/changes/notification-kinds-an-administrator-forces/specs/notifications/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Notification;

/**
 * Applies an administrator's forced channels and internal-only rule.
 *
 * @spec openspec/changes/notification-kinds-an-administrator-forces/specs/notifications/spec.md
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
	 * The effective decision for one recipient and one kind.
	 *
	 * @param array<string,mixed> $resolved      What `NotificationPreferenceService::resolveEffective()` returned.
	 * @param array<string,mixed> $declaration   The notification's own declaration from the schema.
	 * @param bool                $recipientIsInternal Whether this recipient belongs to the organisation.
	 *
	 * @return array{enabled:bool,channels:array<int,string>,forced:bool,reason:string,layer:string,refusal:string}
	 *         What will be sent, on what, who decided it, and why nothing is sent when nothing is.
	 *
	 * @spec openspec/changes/notification-kinds-an-administrator-forces/specs/notifications/spec.md
	 */
	public function decide(array $resolved, array $declaration, bool $recipientIsInternal = true): array {
		$channels = $this->channelsOf(value: ($resolved['channels'] ?? []));
		$enabled = (($resolved['enabled'] ?? true) === true);
		$layer = (string)($resolved['source'] ?? 'schema-default');

		$forcedChannels = $this->channelsOf(value: ($declaration[self::FORCED]['channels'] ?? ($declaration[self::FORCED] ?? [])));
		$reason = trim((string)($declaration[self::FORCED]['reason'] ?? ''));
		$forced = ($forcedChannels !== []);

		if ($forced === true) {
			// The forced channels are ADDED to whatever the preference chose,
			// not substituted for it. A person who also asked for e-mail keeps
			// e-mail; what they cannot do is remove the channel the process
			// requires.
			$channels = array_values(array_unique(array_merge($channels, $forcedChannels)));
			$enabled = true;
			$layer = self::LAYER;
		}

		$internalOnly = (($declaration[self::INTERNAL_ONLY] ?? false) === true);
		if ($internalOnly === true && $recipientIsInternal === false) {
			// Refused, and the refusal is the answer rather than an empty
			// channel list: a caller that received no channels and no reason
			// cannot tell this from a kind nobody configured.
			return [
				'enabled' => false,
				'channels' => [],
				'forced' => $forced,
				'reason' => $reason,
				'layer' => ($internalOnly === true ? self::LAYER : $layer),
				'refusal' => self::REFUSED_EXTERNAL,
			];
		}

		if ($internalOnly === true) {
			// An internal kind never goes out on a channel that can leave the
			// organisation, even to somebody inside it: the channel is the
			// leak, not the recipient. A webhook fires at whatever URL an
			// administrator configured.
			$channels = array_values(array_filter(
				$channels,
				static fn (string $channel): bool => in_array($channel, self::EXTERNAL_CAPABLE, true) === false
			));
		}

		return [
			'enabled' => ($enabled === true && $channels !== []),
			'channels' => $channels,
			'forced' => $forced,
			'reason' => $reason,
			'layer' => $layer,
			'refusal' => '',
		];
	}//end decide()

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
	 * @spec openspec/changes/notification-kinds-an-administrator-forces/specs/notifications/spec.md
	 */
	public function validate(array $declaration): array {
		$errors = [];
		$forcedChannels = $this->channelsOf(value: ($declaration[self::FORCED]['channels'] ?? ($declaration[self::FORCED] ?? [])));
		$reason = trim((string)($declaration[self::FORCED]['reason'] ?? ''));

		if ($forcedChannels !== [] && $reason === '') {
			$errors[] = [
				'code' => 'notification-forced-channel-without-reason',
				'message' => 'A forced channel needs the reason it is forced: a user who cannot switch a notification off is owed the sentence that says why.',
			];
		}

		$internalOnly = (($declaration[self::INTERNAL_ONLY] ?? false) === true);
		if ($internalOnly === false) {
			return $errors;
		}

		$declared = $this->channelsOf(value: ($declaration['channels'] ?? []));
		$usable = array_values(array_filter(
			array_merge($declared, $forcedChannels),
			static fn (string $channel): bool => in_array($channel, self::EXTERNAL_CAPABLE, true) === false
		));

		if ($usable === []) {
			$errors[] = [
				'code' => 'notification-internal-only-has-no-internal-channel',
				'message' => 'An internal-only kind declares only channels that can leave the organisation, so it would never send at all.',
			];
		}

		$forcedExternal = array_values(array_filter(
			$forcedChannels,
			static fn (string $channel): bool => in_array($channel, self::EXTERNAL_CAPABLE, true) === true
		));

		if ($forcedExternal !== []) {
			$errors[] = [
				'code' => 'notification-internal-only-forces-external-channel',
				'message' => sprintf(
					'An internal-only kind forces %s, which can carry it outside the organisation. The two declarations contradict each other.',
					implode(', ', $forcedExternal)
				),
			];
		}

		return $errors;
	}//end validate()

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
