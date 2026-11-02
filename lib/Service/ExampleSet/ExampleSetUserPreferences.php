<?php

/**
 * OpenRegister Example Set User Preferences
 *
 * Per-user preferences an example set writes for the app that ships the
 * fragment, such as the tour a demo employee has already seen. Only for an
 * account the set's cast declares (created or adopted), also when that
 * account is an administrator; never for an administrator outside the cast,
 * and never for a key that holds a secret. The old value is
 * recorded and put back on remove, but only while the key still holds what
 * the set wrote, so a value the person changed since stays.
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
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-set-its-own-apps-preferences-for-the-sets-own-accounts-req-exs-024
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use OCP\Config\IUserConfig;
use OCP\IConfig;
use Throwable;

/**
 * Writes, proves and restores per-user preferences for an example set.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-set-its-own-apps-preferences-for-the-sets-own-accounts-req-exs-024
 */
class ExampleSetUserPreferences {
	/**
	 * Nextcloud's administrators group.
	 */
	private const ADMIN_GROUP = 'admin';

	/**
	 * A key name that holds a secret; a set never writes one.
	 */
	private const SECRET = '/(pass(word|wd)?|secret|token|credential|private|api[_-]?key|session)/i';

	/**
	 * Constructor.
	 *
	 * @param IConfig            $config     Writes and reads the user values.
	 * @param IUserConfig        $userConfig Says whether a user value is kept as sensitive.
	 * @param ExampleSetAccounts $accounts   Says who is an administrator.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IConfig $config,
		private readonly IUserConfig $userConfig,
		private readonly ExampleSetAccounts $accounts,
	) {
	}//end __construct()

	/**
	 * Why a preference cannot be written, or ''.
	 *
	 * @param string             $app   The app that ships the fragment.
	 * @param string             $uid   The filled account id.
	 * @param string             $key   The key.
	 * @param array<int, string> $users The account ids the set creates or adopts.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-set-its-own-apps-preferences-for-the-sets-own-accounts-req-exs-024
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-set-its-own-apps-preferences-for-the-sets-own-accounts-req-exs-024
	 */
	public function refusal(string $app, string $uid, string $key, array $users): string {
		if (in_array($uid, $users, true) === false) {
			if ($this->accounts->inGroup(gid: self::ADMIN_GROUP, uid: $uid) === true) {
				return 'account "'.$uid.'" is one of Nextcloud\'s administrators and not in the set\'s cast; an example set never changes their preferences';
			}

			return 'account "'.$uid.'" is not one the set creates or adopts';
		}

		if (preg_match(self::SECRET, $key) === 1 || $this->sensitive(uid: $uid, app: $app, key: $key) === true) {
			return 'user preference '.$app.' '.$key.' holds a secret; a set never writes one';
		}

		return '';
	}//end refusal()

	/**
	 * Write a preference when the key does not hold that value yet.
	 *
	 * @param string $uid   The account id.
	 * @param string $app   The app.
	 * @param string $key   The key.
	 * @param string $value The filled value.
	 *
	 * @return array{error: string, entry: array<string, mixed>|null} The record entry (null when nothing had to be
	 *                                                                 written), or why the write failed.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-set-its-own-apps-preferences-for-the-sets-own-accounts-req-exs-024
	 */
	public function apply(string $uid, string $app, string $key, string $value): array {
		$had = $this->has(uid: $uid, app: $app, key: $key);
		$old = $this->read(uid: $uid, app: $app, key: $key);
		if ($had === true && $old === $value) {
			return ['error' => '', 'entry' => null];
		}

		try {
			$this->config->setUserValue($uid, $app, $key, $value);
		} catch (Throwable $e) {
			return ['error' => $e->getMessage(), 'entry' => null];
		}

		return ['error' => '', 'entry' => ['user' => $uid, 'app' => $app, 'key' => $key, 'had' => $had, 'old' => $old, 'new' => $value]];
	}//end apply()

	/**
	 * Write a held preference again when its declaration now resolves to something else than the set last wrote (a
	 * view it quotes was written again under a new uuid); what it first replaced stays recorded.
	 *
	 * @param array<string, mixed> $entry   The record entry from apply().
	 * @param string               $value   The value the declaration resolves to now.
	 * @param bool                 $refresh Whether this run refreshes and the value resolved.
	 *
	 * @return array{error: string, entry: array<string, mixed>, wrote: bool}
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-held-change-or-preference-follows-its-declaration
	 */
	public function follow(array $entry, string $value, bool $refresh): array {
		if ($refresh === false || $value === (string) $entry['new']) {
			return ['error' => '', 'entry' => $entry, 'wrote' => false];
		}

		$made = $this->apply(uid: (string) $entry['user'], app: (string) $entry['app'], key: (string) $entry['key'], value: $value);
		if ($made['error'] !== '') {
			return ['error' => $made['error'], 'entry' => $entry, 'wrote' => false];
		}

		return ['error' => '', 'entry' => array_merge($entry, ['new' => $value]), 'wrote' => true];
	}//end follow()

	/**
	 * Whether a written preference is no longer on the instance as written.
	 *
	 * @param array<string, mixed> $entry A record entry from apply().
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-an-install-proves-what-arrived-req-exs-006
	 */
	public function lost(array $entry): bool {
		return $this->read(uid: (string) $entry['user'], app: (string) $entry['app'], key: (string) $entry['key']) !== (string) $entry['new'];
	}//end lost()

	/**
	 * Put back what a preference replaced, while the key still holds what the set wrote.
	 *
	 * @param array<string, mixed> $entry A record entry from apply().
	 *
	 * @return string `restored`, `left` (somebody changed it since) or `failed`.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-set-its-own-apps-preferences-for-the-sets-own-accounts-req-exs-024
	 */
	public function revert(array $entry): string {
		$uid = (string) $entry['user'];
		$app = (string) $entry['app'];
		$key = (string) $entry['key'];
		if ($this->has(uid: $uid, app: $app, key: $key) === true && $this->read(uid: $uid, app: $app, key: $key) !== (string) $entry['new']) {
			return 'left';
		}

		try {
			if (($entry['had'] ?? false) === true) {
				$this->config->setUserValue($uid, $app, $key, (string) $entry['old']);
				return 'restored';
			}

			$this->config->deleteUserValue($uid, $app, $key);
		} catch (Throwable) {
			return 'failed';
		}

		return 'restored';
	}//end revert()

	/**
	 * Whether a user has a key.
	 *
	 * @param string $uid The account id.
	 * @param string $app The app.
	 * @param string $key The key.
	 *
	 * @return bool
	 */
	private function has(string $uid, string $app, string $key): bool {
		try {
			return in_array($key, $this->config->getUserKeys($uid, $app), true);
		} catch (Throwable) {
			return false;
		}
	}//end has()

	/**
	 * Whether an existing user value is kept as sensitive.
	 *
	 * @param string $uid The account id.
	 * @param string $app The app.
	 * @param string $key The key.
	 *
	 * @return bool
	 */
	private function sensitive(string $uid, string $app, string $key): bool {
		if ($this->has(uid: $uid, app: $app, key: $key) === false) {
			return false;
		}

		try {
			return $this->userConfig->isSensitive($uid, $app, $key, null) === true;
		} catch (Throwable) {
			return true;
		}
	}//end sensitive()

	/**
	 * A user value as text, '' when it is not there.
	 *
	 * @param string $uid The account id.
	 * @param string $app The app.
	 * @param string $key The key.
	 *
	 * @return string
	 */
	private function read(string $uid, string $app, string $key): string {
		try {
			return (string) $this->config->getUserValue($uid, $app, $key, '');
		} catch (Throwable) {
			return '';
		}
	}//end read()
}//end class
