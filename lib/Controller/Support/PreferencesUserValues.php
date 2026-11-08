<?php

/**
 * OpenRegister per-user preference values
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IUser;

/**
 * The per-user preference values of one app, stored as IConfig user values.
 *
 * Keys are sanitised to `[a-z0-9-]{0,64}` and stored under the `pref_`
 * namespace, so a caller can never reach arbitrary IConfig user values. The
 * user is always the session user handed in by the controller.
 *
 * @spec openspec/specs/apphost-boilerplate/spec.md#requirement-generic-preferences-controller
 */
final class PreferencesUserValues {
	/**
	 * Constructor.
	 *
	 * @param IConfig $config  The Nextcloud config (user values).
	 * @param string  $appName The app whose user values are read and written.
	 */
	public function __construct(
		private readonly IConfig $config,
		private readonly string $appName,
	) {
	}//end __construct()

	/**
	 * Run $action with the user id and stored key: 401 when anonymous, 400 when no safe key remains.
	 *
	 * @param IUser|null                            $user   The session user.
	 * @param string                                $key    The raw preference key.
	 * @param callable(string, string): JSONResponse $action Gets the user id and the stored (`pref_`) key.
	 *
	 * @return JSONResponse The response.
	 */
	public function forUser(?IUser $user, string $key, callable $action): JSONResponse {
		$safeKey = $this->sanitizeKey(key: $key);
		$refusal = $this->refusal(user: $user, safeKey: $safeKey);
		if ($refusal !== null) {
			return $refusal;
		}

		return $action($user->getUID(), 'pref_' . $safeKey);
	}//end forUser()

	/**
	 * The refusal of an anonymous call (401) or of a key with nothing safe left (400).
	 *
	 * @param IUser|null $user    The session user.
	 * @param string     $safeKey The sanitised key.
	 *
	 * @return JSONResponse|null The refusal, or null when the call may proceed.
	 */
	private function refusal(?IUser $user, string $safeKey): ?JSONResponse {
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Not logged in'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		if ($safeKey === '') {
			return new JSONResponse(data: ['message' => 'Invalid key'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		return null;
	}//end refusal()

	/**
	 * The stored value, null when none is stored.
	 *
	 * @param string $userId    The user id.
	 * @param string $storedKey The stored (`pref_`) key.
	 *
	 * @return string|null The value.
	 */
	public function read(string $userId, string $storedKey): ?string {
		$value = $this->config->getUserValue(userId: $userId, appName: $this->appName, key: $storedKey, default: '');
		if ($value === '') {
			return null;
		}

		return $value;
	}//end read()

	/**
	 * Store the value, or delete the stored one when it is empty.
	 *
	 * @param string $userId    The user id.
	 * @param string $storedKey The stored (`pref_`) key.
	 * @param string $value     The value; empty clears it.
	 *
	 * @return string|null The value now stored.
	 */
	public function write(string $userId, string $storedKey, string $value): ?string {
		if ($value === '') {
			$this->config->deleteUserValue(userId: $userId, appName: $this->appName, key: $storedKey);
			return null;
		}

		$this->config->setUserValue(userId: $userId, appName: $this->appName, key: $storedKey, value: $value);
		return $value;
	}//end write()

	/**
	 * Restrict keys to a safe charset so callers cannot reach arbitrary
	 * IConfig user values outside the `pref_` namespace.
	 *
	 * @param string $key The raw key.
	 *
	 * @return string The sanitised key, or '' when nothing safe remains.
	 */
	private function sanitizeKey(string $key): string {
		$safe = preg_replace(pattern: '/[^a-z0-9-]/', replacement: '', subject: strtolower($key));
		return substr((string) $safe, offset: 0, length: 64);
	}//end sanitizeKey()
}//end class
