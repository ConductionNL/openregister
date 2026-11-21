<?php

/**
 * Whether a schema offers emergency self-granted access, and on what terms.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/grants-that-follow-a-slot-a-relation-or-a-reason/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use DateInterval;
use DateTimeImmutable;
use OCA\OpenRegister\Db\Schema;
use Throwable;

/**
 * The `x-openregister-break-glass` declaration of one schema, read and checked.
 *
 * The declaration lives in the schema's configuration:
 *
 *   "x-openregister-break-glass": {
 *     "verbs": ["read"],
 *     "maxDuration": "PT4H",
 *     "notify": {"users": ["teamlead"], "groups": ["beheer"]},
 *     "groups": ["balie"]
 *   }
 *
 * DECLARED PER SCHEMA, SO IT IS NOT AVAILABLE BY DEFAULT (design D-4). A schema
 * without the key offers nothing, and a malformed declaration offers nothing
 * either: {@see fromSchema()} answers null for both, and the save path refuses
 * the malformed one with {@see errorsOf()} so the author learns at save time.
 *
 * NARROW. Only the declared verbs are granted, and `manage` can never be one of
 * them: emergency access is not a route to administration.
 *
 * ANNOUNCED. A declaration must name at least one recipient, because taking it
 * notifies somebody at that moment; an emergency grant nobody hears of is the
 * failure the declaration exists to prevent.
 *
 * `groups` is optional and names who may take it; absent, any signed-in user
 * may, which is the counter scenario the requirement describes.
 *
 * @spec openspec/changes/grants-that-follow-a-slot-a-relation-or-a-reason/specs/rbac-scopes/spec.md#requirement-emergency-self-granted-access-is-declared-bounded-and-announced-req-gsr-004
 */
final class BreakGlassDeclaration {

	/**
	 * The schema configuration key.
	 *
	 * @var string
	 */
	public const ANNOTATION = Schema::BREAK_GLASS_ANNOTATION;

	/**
	 * Verbs an emergency grant may never carry.
	 *
	 * @var array<int, string>
	 */
	public const REFUSED_VERBS = ['manage'];

	/**
	 * Constructor.
	 *
	 * @param array<int, string> $verbs           The verbs the grant carries.
	 * @param int                $durationSeconds How long one take lasts.
	 * @param array<int, string> $notifyUsers     Users told when it is taken.
	 * @param array<int, string> $notifyGroups    Groups whose members are told when it is taken.
	 * @param array<int, string> $takerGroups     Who may take it; empty means any signed-in user.
	 */
	public function __construct(
		public readonly array $verbs,
		public readonly int $durationSeconds,
		public readonly array $notifyUsers = [],
		public readonly array $notifyGroups = [],
		public readonly array $takerGroups = [],
	) {
	}//end __construct()

	/**
	 * The schema's declaration, or null when it declares none or a malformed one.
	 *
	 * @param Schema|null $schema The schema.
	 *
	 * @return self|null The declaration.
	 *
	 * @spec openspec/changes/grants-that-follow-a-slot-a-relation-or-a-reason/specs/rbac-scopes/spec.md#requirement-emergency-self-granted-access-is-declared-bounded-and-announced-req-gsr-004
	 */
	public static function fromSchema(?Schema $schema): ?self {
		if ($schema === null) {
			return null;
		}

		$block = (($schema->getConfiguration() ?? [])[self::ANNOTATION] ?? null);

		return self::fromBlock(block: $block);
	}//end fromSchema()

	/**
	 * A declaration from its block, or null when the block is absent or malformed.
	 *
	 * @param mixed $block The configured block.
	 *
	 * @return self|null The declaration.
	 *
	 * @spec openspec/changes/grants-that-follow-a-slot-a-relation-or-a-reason/specs/rbac-scopes/spec.md#requirement-emergency-self-granted-access-is-declared-bounded-and-announced-req-gsr-004
	 */
	public static function fromBlock(mixed $block): ?self {
		if ($block === null || self::errorsOf(block: $block) !== []) {
			return null;
		}

		$notify = ($block['notify'] ?? []);

		return new self(
			verbs: self::names(value: $block['verbs']),
			durationSeconds: (int)self::durationSecondsOf(value: $block['maxDuration']),
			notifyUsers: self::names(value: ($notify['users'] ?? [])),
			notifyGroups: self::names(value: ($notify['groups'] ?? [])),
			takerGroups: self::names(value: ($block['groups'] ?? []))
		);
	}//end fromBlock()

	/**
	 * Every reason the block cannot be honoured; empty when it can.
	 *
	 * @param mixed $block The configured block.
	 *
	 * @return array<int, string> The faults, in a stable order.
	 *
	 * @spec openspec/changes/grants-that-follow-a-slot-a-relation-or-a-reason/specs/rbac-scopes/spec.md#requirement-emergency-self-granted-access-is-declared-bounded-and-announced-req-gsr-004
	 */
	public static function errorsOf(mixed $block): array {
		if (is_array($block) === false || array_is_list($block) === true) {
			return ['the declaration must be an object'];
		}

		$errors = array_merge(
			self::verbErrors(value: ($block['verbs'] ?? null)),
			self::durationErrors(value: ($block['maxDuration'] ?? null)),
			self::recipientErrors(value: ($block['notify'] ?? null))
		);

		if (array_key_exists('groups', $block) === true && self::isNameList(value: $block['groups']) === false) {
			$errors[] = '`groups` must be a list of group ids';
		}

		return $errors;
	}//end errorsOf()

	/**
	 * Whether the declaration grants this verb.
	 *
	 * @param string $verb The verb asked for.
	 *
	 * @return bool True when it is one of the declared verbs.
	 *
	 * @spec openspec/changes/grants-that-follow-a-slot-a-relation-or-a-reason/specs/rbac-scopes/spec.md#requirement-emergency-self-granted-access-is-declared-bounded-and-announced-req-gsr-004
	 */
	public function grants(string $verb): bool {
		return in_array($verb, $this->verbs, true);
	}//end grants()

	/**
	 * Whether a caller in these groups may take it.
	 *
	 * @param array<int, string> $callerGroups The caller's groups.
	 *
	 * @return bool True when no taker groups are declared, or the caller is in one.
	 *
	 * @spec openspec/changes/grants-that-follow-a-slot-a-relation-or-a-reason/specs/rbac-scopes/spec.md#requirement-emergency-self-granted-access-is-declared-bounded-and-announced-req-gsr-004
	 */
	public function mayBeTakenBy(array $callerGroups): bool {
		if ($this->takerGroups === []) {
			return true;
		}

		return array_intersect($this->takerGroups, $callerGroups) !== [];
	}//end mayBeTakenBy()

	/**
	 * The seconds an ISO-8601 duration spans, or null when it is not one or not positive.
	 *
	 * @param mixed $value The duration, e.g. `PT4H`.
	 *
	 * @return int|null The seconds.
	 *
	 * @spec openspec/changes/grants-that-follow-a-slot-a-relation-or-a-reason/specs/rbac-scopes/spec.md#requirement-emergency-self-granted-access-is-declared-bounded-and-announced-req-gsr-004
	 */
	public static function durationSecondsOf(mixed $value): ?int {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			$interval = new DateInterval(trim($value));
		} catch (Throwable $e) {
			return null;
		}

		$epoch = new DateTimeImmutable('@0');
		$seconds = $epoch->add($interval)->getTimestamp();
		if ($interval->invert === 1 || $seconds <= 0) {
			return null;
		}

		return $seconds;
	}//end durationSecondsOf()

	/**
	 * The faults of the verb list.
	 *
	 * @param mixed $value The `verbs` value.
	 *
	 * @return array<int, string> The faults.
	 */
	private static function verbErrors(mixed $value): array {
		if (self::isNameList(value: $value) === false || $value === []) {
			return ['`verbs` must be a non-empty list of verbs'];
		}

		$refused = array_values(array_intersect(self::names(value: $value), self::REFUSED_VERBS));
		if ($refused !== []) {
			return ['`verbs` may not include ' . implode(', ', $refused) . ': emergency access is not a route to administration'];
		}

		return [];
	}//end verbErrors()

	/**
	 * The faults of the duration.
	 *
	 * @param mixed $value The `maxDuration` value.
	 *
	 * @return array<int, string> The faults.
	 */
	private static function durationErrors(mixed $value): array {
		if (self::durationSecondsOf(value: $value) === null) {
			return ['`maxDuration` must be a positive ISO-8601 duration such as PT4H'];
		}

		return [];
	}//end durationErrors()

	/**
	 * The faults of the recipients.
	 *
	 * @param mixed $value The `notify` value.
	 *
	 * @return array<int, string> The faults.
	 */
	private static function recipientErrors(mixed $value): array {
		$fault = ['`notify` must name at least one user or group in `users` or `groups`'];
		if (is_array($value) === false) {
			return $fault;
		}

		$users = ($value['users'] ?? []);
		$groups = ($value['groups'] ?? []);
		if (self::isNameList(value: $users) === false || self::isNameList(value: $groups) === false) {
			return $fault;
		}

		if (self::names(value: $users) === [] && self::names(value: $groups) === []) {
			return $fault;
		}

		return [];
	}//end recipientErrors()

	/**
	 * Whether the value is a list of non-blank strings.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True for a list whose every item is a non-blank string.
	 */
	private static function isNameList(mixed $value): bool {
		if (is_array($value) === false || array_is_list($value) === false) {
			return false;
		}

		foreach ($value as $item) {
			if (is_string($item) === false || trim($item) === '') {
				return false;
			}
		}

		return true;
	}//end isNameList()

	/**
	 * The trimmed, distinct names of a list.
	 *
	 * @param mixed $value The list.
	 *
	 * @return array<int, string> The names.
	 */
	private static function names(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$names = [];
		foreach ($value as $item) {
			if (is_string($item) === true && trim($item) !== '') {
				$names[] = trim($item);
			}
		}

		return array_values(array_unique($names));
	}//end names()
}//end class
