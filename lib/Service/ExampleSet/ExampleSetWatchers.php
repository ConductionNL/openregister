<?php

/**
 * OpenRegister Example Set Watchers
 *
 * The followers an example set declares, such as a colleague who follows a
 * demo case. A follow lives in OpenRegister's watcher table, keyed by user
 * and object, not in a register, so the set writes it through the watcher
 * mapper and records the pair. A follower is one of the set's own accounts.
 * Remove takes away the follows the record holds.
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
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-let-the-sets-own-accounts-follow-its-objects
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use OCA\OpenRegister\Notifications\Db\WatcherMapperInterface;
use Psr\Container\ContainerInterface;

/**
 * Checks, writes, proves and deletes the follows of an example set.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-let-the-sets-own-accounts-follow-its-objects
 */
class ExampleSetWatchers extends ExampleSetEngineRows {
	use ExampleSetUserObjectRows;

	/**
	 * The follow fields a fragment may set.
	 */
	public const FIELDS = ['user', 'objectUuid', 'register', 'schema', 'notify'];

	/**
	 * The row in the unknown-field message.
	 */
	private const FIELD_WORD = 'watcher';

	/**
	 * The refusal for a row without its object.
	 */
	private const NEEDS_OBJECT = 'a watcher needs the object it follows (objectUuid)';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Hands out the watcher mapper on first use.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * The fragment and record part follows live in.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-let-the-sets-own-accounts-follow-its-objects
	 */
	public function part(): string {
		return 'watchers';
	}//end part()

	/**
	 * The word for one follow, in messages.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-let-the-sets-own-accounts-follow-its-objects
	 */
	public function noun(): string {
		return 'watcher';
	}//end noun()

	/**
	 * Why a filled follow cannot be written, or ''.
	 *
	 * @param array<string, mixed> $data  The filled follow fields.
	 * @param array<int, string>   $users The account ids the set creates or adopts.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-let-the-sets-own-accounts-follow-its-objects
	 */
	public function refusal(array $data, array $users): string {
		$refusal = $this->userObjectRefusal(data: $data, users: $users);
		if ($refusal !== '') {
			return $refusal;
		}

		if (isset($data['notify']) === true && is_bool($data['notify']) === false) {
			return 'notify is true or false';
		}

		return '';
	}//end refusal()

	/**
	 * Let the account follow the object.
	 *
	 * @param ExampleSetRow $row The row: its app, its key in the fragment and its filled fields.
	 *
	 * @return array{error: string, entry: array{user: string, objectUuid: string}|null} The record entry, or why the write failed.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-let-the-sets-own-accounts-follow-its-objects
	 */
	public function write(ExampleSetRow $row): array {
		$data = $row->data;
		return $this->userObjectWrite(
			write: fn () => $this->mapper()->subscribe(
				userId: (string) $data['user'],
				objectUuid: (string) $data['objectUuid'],
				register: $this->textOrNull(value: ($data['register'] ?? null)),
				schema: $this->textOrNull(value: ($data['schema'] ?? null)),
				notify: ($data['notify'] ?? null)
			),
			data: $data
		);
	}//end write()

	/**
	 * Whether a written follow is no longer there.
	 *
	 * @param array<string, mixed> $entry A record entry from write().
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-let-the-sets-own-accounts-follow-its-objects
	 */
	public function lost(array $entry): bool {
		return $this->userObjectLost(
			find: fn () => $this->mapper()->findOne(userId: (string) $entry['user'], objectUuid: (string) $entry['objectUuid'])
		);
	}//end lost()

	/**
	 * Take away a follow the set wrote.
	 *
	 * @param array<string, mixed> $entry A record entry from write().
	 *
	 * @return string `removed` (also when it was already gone) or `failed`.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-remove-takes-away-the-follows-a-set-wrote
	 */
	public function revert(array $entry): string {
		return $this->userObjectRevert(
			remove: fn () => $this->mapper()->unsubscribe(userId: (string) $entry['user'], objectUuid: (string) $entry['objectUuid'])
		);
	}//end revert()

	/**
	 * The watcher mapper.
	 *
	 * @return WatcherMapperInterface
	 */
	private function mapper(): WatcherMapperInterface {
		return $this->container->get(WatcherMapperInterface::class);
	}//end mapper()
}//end class
