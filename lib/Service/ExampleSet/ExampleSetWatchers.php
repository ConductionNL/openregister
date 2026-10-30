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
 * @spec openspec/changes/example-set-watchers/specs/example-sets/spec.md#requirement-a-fragment-may-let-the-sets-own-accounts-follow-its-objects
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use OCA\OpenRegister\Db\WatcherMapper;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Checks, writes, proves and deletes the follows of an example set.
 *
 * @spec openspec/changes/example-set-watchers/specs/example-sets/spec.md#requirement-a-fragment-may-let-the-sets-own-accounts-follow-its-objects
 */
class ExampleSetWatchers extends ExampleSetEngineRows {
	/**
	 * The follow fields a fragment may set.
	 */
	public const FIELDS = ['user', 'objectUuid', 'register', 'schema', 'notify'];

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
	 * @spec openspec/changes/example-set-watchers/specs/example-sets/spec.md#requirement-a-fragment-may-let-the-sets-own-accounts-follow-its-objects
	 */
	public function part(): string {
		return 'watchers';
	}//end part()

	/**
	 * The word for one follow, in messages.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/example-set-watchers/specs/example-sets/spec.md#requirement-a-fragment-may-let-the-sets-own-accounts-follow-its-objects
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
	 * @spec openspec/changes/example-set-watchers/specs/example-sets/spec.md#requirement-a-fragment-may-let-the-sets-own-accounts-follow-its-objects
	 */
	public function refusal(array $data, array $users): string {
		$unknown = array_diff(array_keys($data), self::FIELDS);
		if ($unknown !== []) {
			return 'field "'.implode('", "', $unknown).'" is not a watcher field a set may write';
		}

		$user = (string) ($data['user'] ?? '');
		if (in_array($user, $users, true) === false) {
			return 'user "'.$user.'" is not an account the set creates or adopts';
		}

		if (is_string($data['objectUuid'] ?? null) === false || trim($data['objectUuid']) === '') {
			return 'a watcher needs the object it follows (objectUuid)';
		}

		if (isset($data['notify']) === true && is_bool($data['notify']) === false) {
			return 'notify is true or false';
		}

		return '';
	}//end refusal()

	/**
	 * Let the account follow the object.
	 *
	 * @param string               $app  The app that ships the fragment (unused: a follow names its user).
	 * @param string               $key  The follow's key in the fragment (unused).
	 * @param array<string, mixed> $data The filled follow fields.
	 *
	 * @return array{error: string, entry: array{user: string, objectUuid: string}|null} The record entry, or why the write failed.
	 *
	 * @spec openspec/changes/example-set-watchers/specs/example-sets/spec.md#requirement-a-fragment-may-let-the-sets-own-accounts-follow-its-objects
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
			$this->mapper()->subscribe(
				userId: (string) $data['user'],
				objectUuid: (string) $data['objectUuid'],
				register: $register,
				schema: $schema,
				notify: ($data['notify'] ?? null)
			);
		} catch (Throwable $e) {
			return ['error' => $e->getMessage(), 'entry' => null];
		}

		return ['error' => '', 'entry' => ['user' => (string) $data['user'], 'objectUuid' => (string) $data['objectUuid']]];
	}//end write()

	/**
	 * Whether a written follow is no longer there.
	 *
	 * @param array<string, mixed> $entry A record entry from write().
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/example-set-watchers/specs/example-sets/spec.md#requirement-a-fragment-may-let-the-sets-own-accounts-follow-its-objects
	 */
	public function lost(array $entry): bool {
		try {
			return $this->mapper()->findOne(userId: (string) $entry['user'], objectUuid: (string) $entry['objectUuid']) === null;
		} catch (Throwable) {
			return true;
		}
	}//end lost()

	/**
	 * Take away a follow the set wrote.
	 *
	 * @param array<string, mixed> $entry A record entry from write().
	 *
	 * @return string `removed` (also when it was already gone) or `failed`.
	 *
	 * @spec openspec/changes/example-set-watchers/specs/example-sets/spec.md#requirement-remove-takes-away-the-follows-a-set-wrote
	 */
	public function revert(array $entry): string {
		try {
			$this->mapper()->unsubscribe(userId: (string) $entry['user'], objectUuid: (string) $entry['objectUuid']);
		} catch (Throwable) {
			return 'failed';
		}

		return 'removed';
	}//end revert()

	/**
	 * The watcher mapper.
	 *
	 * @return WatcherMapper
	 */
	private function mapper(): WatcherMapper {
		return $this->container->get(WatcherMapper::class);
	}//end mapper()
}//end class
