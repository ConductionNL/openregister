<?php

/**
 * InstanceAppConfigReader: an app's configuration for a serialised instance, without its secrets.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Import;

use OCP\IAppConfig;
use Throwable;

/**
 * Reads every app config key of one app for the instance set and leaves out
 * the secret ones: a key Nextcloud keeps as sensitive, a key whose name
 * names a secret, and, inside a value holding a JSON object, every entry
 * whose name names one. Each left-out entry is reported as
 * `configuration.<key>` or `configuration.<key>.<path>`.
 *
 * A key whose sensitivity cannot be read is treated as sensitive, so a
 * failure leaves a value out rather than writing it into the set.
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md#REQ-IPC-005
 */
class InstanceAppConfigReader {

	/**
	 * Constructor.
	 *
	 * @param IAppConfig           $appConfig Nextcloud's app config.
	 * @param InstanceSecretFilter $filter    Names secret keys and strips nested ones.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly InstanceSecretFilter $filter,
	) {
	}//end __construct()

	/**
	 * The app's configuration without secrets, and the paths left out.
	 *
	 * @param string $app The app id.
	 *
	 * @return array{0: array<string, mixed>, 1: list<string>} The values by key and the excluded paths.
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md#REQ-IPC-005
	 */
	public function read(string $app): array {
		$values   = [];
		$excluded = [];
		foreach ($this->appConfig->getAllValues($app) as $key => $value) {
			$key = (string) $key;
			if ($this->sensitive(app: $app, key: $key) === true || $this->filter->isSecretKey(key: $key) === true) {
				$excluded[] = 'configuration.'.$key;
				continue;
			}

			[$values[$key], $inner] = $this->withoutNestedSecrets(key: $key, value: $value);
			$excluded = array_merge($excluded, $inner);
		}

		return [$values, $excluded];
	}//end read()

	/**
	 * A value with the secrets inside a JSON object string left out.
	 *
	 * A value that is not a JSON object string, or holds no secret, comes
	 * back unchanged, so the set keeps the stored text as it was.
	 *
	 * @param string $key   The app config key.
	 * @param mixed  $value The stored value.
	 *
	 * @return array{0: mixed, 1: list<string>} The value and the excluded paths.
	 */
	private function withoutNestedSecrets(string $key, mixed $value): array {
		if (is_string($value) === false || str_starts_with(ltrim($value), '{') === false) {
			return [$value, []];
		}

		$decoded = json_decode($value, true);
		if (is_array($decoded) === false) {
			return [$value, []];
		}

		[$clean, $excluded] = $this->filter->strip(node: $decoded, path: 'configuration.'.$key);
		if ($excluded === []) {
			return [$value, []];
		}

		return [json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $excluded];
	}//end withoutNestedSecrets()

	/**
	 * Whether Nextcloud keeps a key as sensitive; unreadable counts as sensitive.
	 *
	 * @param string $app The app id.
	 * @param string $key The key.
	 *
	 * @return bool
	 */
	private function sensitive(string $app, string $key): bool {
		try {
			return $this->appConfig->isSensitive($app, $key, null) === true;
		} catch (Throwable) {
			return true;
		}
	}//end sensitive()
}//end class
