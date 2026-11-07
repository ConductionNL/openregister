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

use OCA\OpenRegister\Db\ObjectReadStateMapper;
use Psr\Container\ContainerInterface;

/**
 * Checks, writes, proves and takes away the read states of an example set.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-say-which-objects-the-sets-own-accounts-opened-and-when
 */
class ExampleSetReadStates extends ExampleSetEngineRows {
	use ExampleSetUserObjectRows;

	/**
	 * The read-state fields a fragment may set.
	 */
	public const FIELDS = ['user', 'objectUuid', 'register', 'schema', 'seenAt'];

	/**
	 * The row in the unknown-field message.
	 */
	private const FIELD_WORD = 'read-state';

	/**
	 * The refusal for a row without its object.
	 */
	private const NEEDS_OBJECT = 'a read state needs the object that was opened (objectUuid)';

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
		$refusal = $this->userObjectRefusal(data: $data, users: $users);
		if ($refusal !== '') {
			return $refusal;
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
		return $this->userObjectWrite(
			write: fn () => $this->mapper()->markSeen(
				userId: (string) $data['user'],
				objectUuid: (string) $data['objectUuid'],
				register: $this->textOrNull(value: ($data['register'] ?? null)),
				schema: $this->textOrNull(value: ($data['schema'] ?? null)),
				seenAt: $this->moment(value: ($data['seenAt'] ?? null))
			),
			data: $data
		);
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
		return $this->userObjectLost(
			find: fn () => $this->mapper()->findOne(userId: (string) $entry['user'], objectUuid: (string) $entry['objectUuid'])
		);
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
		return $this->userObjectRevert(
			remove: fn () => $this->mapper()->markUnread(userId: (string) $entry['user'], objectUuid: (string) $entry['objectUuid'])
		);
	}//end revert()

	/**
	 * The read-state mapper.
	 *
	 * @return ObjectReadStateMapper
	 */
	private function mapper(): ObjectReadStateMapper {
		return $this->container->get(ObjectReadStateMapper::class);
	}//end mapper()
}//end class
