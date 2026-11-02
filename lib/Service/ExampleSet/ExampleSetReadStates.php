<?php

/**
 * OpenRegister Example Set Read States
 *
 * The objects an example set says one of its accounts has opened, and when,
 * such as the cases a handler looked at this morning. A read state lives in
 * OpenRegister's read-state table, keyed by user and object, not in a
 * register, so the set writes it through the read-state mapper and records
 * the pair. The reader is one of the set's own accounts. Remove puts the
 * objects back to unread for that account.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-say-which-objects-the-sets-own-accounts-opened-and-when
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use DateTime;
use OCA\OpenRegister\Db\ObjectReadStateMapper;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Checks, writes, proves and takes away the read states of an example set.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-say-which-objects-the-sets-own-accounts-opened-and-when
 */
class ExampleSetReadStates extends ExampleSetEngineRows {
	/**
	 * The read-state fields a fragment may set.
	 */
	public const FIELDS = ['user', 'objectUuid', 'register', 'schema', 'seenAt'];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Hands out the read-state mapper on first use.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * The fragment and record part read states live in.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-say-which-objects-the-sets-own-accounts-opened-and-when
	 */
	public function part(): string {
		return 'readStates';
	}//end part()

	/**
	 * The word for one read state, in messages.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-say-which-objects-the-sets-own-accounts-opened-and-when
	 */
	public function noun(): string {
		return 'read state';
	}//end noun()

	/**
	 * Why a filled read state cannot be written, or ''.
	 *
	 * @param array<string, mixed> $data  The filled fields.
	 * @param array<int, string>   $users The account ids the set creates or adopts.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-say-which-objects-the-sets-own-accounts-opened-and-when
	 */
	public function refusal(array $data, array $users): string {
		$unknown = array_diff(array_keys($data), self::FIELDS);
		if ($unknown !== []) {
			return 'field "'.implode('", "', $unknown).'" is not a read-state field a set may write';
		}

		$user = (string) ($data['user'] ?? '');
		if (in_array($user, $users, true) === false) {
			return 'user "'.$user.'" is not an account the set creates or adopts';
		}

		if (is_string($data['objectUuid'] ?? null) === false || trim($data['objectUuid']) === '') {
			return 'a read state needs the object that was opened (objectUuid)';
		}

		if (isset($data['seenAt']) === true && $this->moment(value: $data['seenAt']) === null) {
			return 'seenAt is a date and time';
		}

		return '';
	}//end refusal()

	/**
	 * Record that the account opened the object, at `seenAt` or now.
	 *
	 * @param string               $app  The app that ships the fragment (unused: a read state names its user).
	 * @param string               $key  The read state's key in the fragment (unused).
	 * @param array<string, mixed> $data The filled fields.
	 *
	 * @return array{error: string, entry: array{user: string, objectUuid: string}|null} The record entry, or why the write failed.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-say-which-objects-the-sets-own-accounts-opened-and-when
	 */
	public function write(string $app, string $key, array $data): array {
		$register = null;
		if (isset($data['register']) === true) {
			$register = (string) $data['register'];
		}

		$schema = null;
		if (isset($data['schema']) === true) {
			$schema = (string) $data['schema'];
		}

		try {
			$this->mapper()->markSeen(
				userId: (string) $data['user'],
				objectUuid: (string) $data['objectUuid'],
				register: $register,
				schema: $schema,
				seenAt: $this->moment(value: ($data['seenAt'] ?? null))
			);
		} catch (Throwable $e) {
			return ['error' => $e->getMessage(), 'entry' => null];
		}

		return ['error' => '', 'entry' => ['user' => (string) $data['user'], 'objectUuid' => (string) $data['objectUuid']]];
	}//end write()

	/**
	 * Whether a written read state is no longer there.
	 *
	 * @param array<string, mixed> $entry A record entry from write().
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-say-which-objects-the-sets-own-accounts-opened-and-when
	 */
	public function lost(array $entry): bool {
		try {
			return $this->mapper()->findOne(userId: (string) $entry['user'], objectUuid: (string) $entry['objectUuid']) === null;
		} catch (Throwable) {
			return true;
		}
	}//end lost()

	/**
	 * Put the object back to unread for the account.
	 *
	 * @param array<string, mixed> $entry A record entry from write().
	 *
	 * @return string `removed` (also when it was already gone) or `failed`.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-remove-takes-away-the-notes-and-read-states-a-set-wrote
	 */
	public function revert(array $entry): string {
		try {
			$this->mapper()->markUnread(userId: (string) $entry['user'], objectUuid: (string) $entry['objectUuid']);
		} catch (Throwable) {
			return 'failed';
		}

		return 'removed';
	}//end revert()

	/**
	 * A date and time as written by `{{datetime:...}}`, or null.
	 *
	 * @param mixed $value The filled value.
	 *
	 * @return DateTime|null
	 */
	private function moment(mixed $value): ?DateTime {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return new DateTime($value);
		} catch (Throwable) {
			return null;
		}
	}//end moment()

	/**
	 * The read-state mapper.
	 *
	 * @return ObjectReadStateMapper
	 */
	private function mapper(): ObjectReadStateMapper {
		return $this->container->get(ObjectReadStateMapper::class);
	}//end mapper()
}//end class
