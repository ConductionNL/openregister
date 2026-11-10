<?php

/**
 * NotificationRequestTrait: the request reading the notification controllers share.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Notifications\Controller\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Controller\Support;

use OCP\AppFramework\Http\JSONResponse;

/**
 * Who is calling, the 401 an anonymous caller gets, and the payload readings
 * every preference-style endpoint repeats.
 *
 * Meant for controllers that hold an `IUserSession` in `$this->userSession`.
 */
trait NotificationRequestTrait {

	/**
	 * Resolve the current user's UID, or null when anonymous.
	 *
	 * @return string|null The UID, or null.
	 */
	private function resolveUserId(): ?string {
		return $this->userSession->getUser()?->getUID();

	}//end resolveUserId()

	/**
	 * The response an anonymous caller gets.
	 *
	 * @return JSONResponse A 401.
	 */
	private function authenticationRequired(): JSONResponse {
		return new JSONResponse(data: ['error' => 'Authentication required'], statusCode: 401);

	}//end authenticationRequired()

	/**
	 * Coerce a request value to a non-empty string, or null.
	 *
	 * @param mixed $value Input.
	 *
	 * @return string|null The string, or null when it is not a non-empty string.
	 */
	private function nonEmptyString(mixed $value): ?string {
		if (is_string($value) === false || $value === '') {
			return null;
		}

		return $value;

	}//end nonEmptyString()

	/**
	 * One payload value as a non-empty string, or null when absent or empty.
	 *
	 * @param array<string, mixed> $params The request payload.
	 * @param string               $key    The key.
	 *
	 * @return string|null The value, or null.
	 */
	private function stringParam(array $params, string $key): ?string {
		return $this->nonEmptyString(value: ($params[$key] ?? null));
	}//end stringParam()

	/**
	 * Whether the payload asks to clear the stored value (`reset` true or "true").
	 *
	 * @param array<string, mixed> $params The request payload.
	 *
	 * @return bool True when the caller asks for a reset.
	 */
	private function wantsReset(array $params): bool {
		$reset = ($params['reset'] ?? null);

		return ($reset === true || $reset === 'true');

	}//end wantsReset()

	/**
	 * The enabled/channels setting a payload asks to store, or null for a reset.
	 *
	 * `enabled` defaults to true; `channels` is kept only when it is a list.
	 *
	 * @param array<string, mixed> $params The request payload.
	 *
	 * @return array<string, mixed>|null The setting, or null when the caller asks for a reset.
	 */
	private function requestedSetting(array $params): ?array {
		if ($this->wantsReset(params: $params) === true) {
			return null;
		}

		$setting = ['enabled' => (bool)($params['enabled'] ?? true)];
		if (isset($params['channels']) === true && is_array($params['channels']) === true) {
			$setting['channels'] = $params['channels'];
		}

		return $setting;

	}//end requestedSetting()

	/**
	 * Answer a write with its target and the value stored now.
	 *
	 * The target's keys come back in their order, `scope` reading "global"
	 * when none was given, followed by the stored value under `$key`.
	 *
	 * @param array<string, string|null> $target What was written, `scope` included.
	 * @param string                     $key    The key the stored value is answered under.
	 * @param array<string, mixed>|null  $stored The stored value, or null.
	 *
	 * @return JSONResponse The answer.
	 */
	private function settingResponse(array $target, string $key, ?array $stored): JSONResponse {
		$data = $target;
		$data['scope'] = ($target['scope'] ?? 'global');
		$data[$key] = $stored;

		return new JSONResponse(data: $data);
	}//end settingResponse()
}//end trait
