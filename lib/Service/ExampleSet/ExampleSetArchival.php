<?php

/**
 * OpenRegister Example Set Archival
 *
 * The keys of OpenRegister's `archival` setting an example set may name: the
 * register and schema its destruction lists live in. Only those keys are
 * merged into the setting; every other key stays. What each key held is
 * recorded and put back on remove, while the key still holds what the set
 * wrote.
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
 * @spec openspec/specs/example-sets/spec.md#requirement-openregisters-fragment-may-name-the-destruction-list-register-and-schema
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use OCP\IAppConfig;
use Throwable;

/**
 * Merges, proves and restores the destruction-list keys of the archival setting.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-openregisters-fragment-may-name-the-destruction-list-register-and-schema
 */
class ExampleSetArchival extends ExampleSetEngineRows {
	/**
	 * The keys a set may write.
	 */
	public const KEYS = ['destructionListRegister', 'destructionListSchema'];

	/**
	 * The app and key of the setting.
	 */
	private const APP = 'openregister';

	private const SETTING = 'archival';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Holds the archival setting.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The fragment part and record part.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-openregisters-fragment-may-name-the-destruction-list-register-and-schema
	 */
	public function part(): string {
		return 'archival';
	}//end part()

	/**
	 * The word for one row.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-openregisters-fragment-may-name-the-destruction-list-register-and-schema
	 */
	public function noun(): string {
		return 'archival setting';
	}//end noun()

	/**
	 * Why a row cannot be written: a key outside KEYS, no key at all, or a value that is no id.
	 *
	 * @param array<string, mixed> $data  The filled keys.
	 * @param array<int, string>   $users Unused; the setting names no account.
	 *
	 * @return string
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is the engine rows' contract.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-openregisters-fragment-may-name-the-destruction-list-register-and-schema
	 */
	public function refusal(array $data, array $users): string {
		$unknown = array_diff(array_keys($data), self::KEYS);
		if ($unknown !== []) {
			return 'key "'.implode('", "', $unknown).'" is not an archival key a set may write (only '.implode(', ', self::KEYS).')';
		}

		if ($data === []) {
			return 'it names no key';
		}

		foreach ($data as $key => $value) {
			if (is_int($value) === false && (is_string($value) === false || $value === '')) {
				return $key.' is a register or schema id';
			}
		}

		return '';
	}//end refusal()

	/**
	 * Merge the keys into the setting, recording what each held.
	 *
	 * @param string               $app  The app that ships the fragment.
	 * @param string               $key  The row's key in the fragment.
	 * @param array<string, mixed> $data The filled keys.
	 *
	 * @return array{error: string, entry: array{hadSetting: bool, set: array<string, mixed>, had: array<string, bool>, old: array<string, mixed>}|null}
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is the engine rows' contract.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-openregisters-fragment-may-name-the-destruction-list-register-and-schema
	 */
	public function write(string $app, string $key, array $data): array {
		$had     = $this->has();
		$setting = $this->setting();
		$entry   = ['hadSetting' => $had, 'set' => $data, 'had' => [], 'old' => []];
		foreach ($data as $name => $value) {
			$entry['had'][$name] = array_key_exists($name, $setting);
			$entry['old'][$name] = ($setting[$name] ?? null);
			$setting[$name]      = $value;
		}

		$error = $this->store(setting: $setting);
		if ($error !== '') {
			return ['error' => $error, 'entry' => null];
		}

		return ['error' => '', 'entry' => $entry];
	}//end write()

	/**
	 * Whether a key no longer holds what the set wrote.
	 *
	 * @param array<string, mixed> $entry A record entry from write().
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-openregisters-fragment-may-name-the-destruction-list-register-and-schema
	 */
	public function lost(array $entry): bool {
		$setting = $this->setting();
		foreach ((array) ($entry['set'] ?? []) as $name => $value) {
			if ($this->same(first: ($setting[$name] ?? null), second: $value) === false) {
				return true;
			}
		}

		return false;
	}//end lost()

	/**
	 * Put back what each key held while it still holds what the set wrote; a key somebody changed since stays.
	 *
	 * @param array<string, mixed> $entry A record entry from write().
	 *
	 * @return string `removed` or `failed`.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-openregisters-fragment-may-name-the-destruction-list-register-and-schema
	 */
	public function revert(array $entry): string {
		$setting = $this->setting();
		foreach ((array) ($entry['set'] ?? []) as $name => $value) {
			if ($this->same(first: ($setting[$name] ?? null), second: $value) === false) {
				continue;
			}

			unset($setting[$name]);
			if (($entry['had'][$name] ?? false) === true) {
				$setting[$name] = ($entry['old'][$name] ?? null);
			}
		}

		if ($setting === [] && ($entry['hadSetting'] ?? true) === false) {
			try {
				$this->appConfig->deleteKey(self::APP, self::SETTING);
			} catch (Throwable) {
				return 'failed';
			}

			return 'removed';
		}

		if ($this->store(setting: $setting) !== '') {
			return 'failed';
		}

		return 'removed';
	}//end revert()

	/**
	 * Whether two ids are the same, an id as text or as a number.
	 *
	 * @param mixed $first  One value.
	 * @param mixed $second The other.
	 *
	 * @return bool
	 */
	private function same(mixed $first, mixed $second): bool {
		if ($first === null || $second === null) {
			return $first === $second;
		}

		return (string) $first === (string) $second;
	}//end same()

	/**
	 * Whether the setting is there.
	 *
	 * @return bool
	 */
	private function has(): bool {
		try {
			return $this->appConfig->hasKey(self::APP, self::SETTING) === true;
		} catch (Throwable) {
			return false;
		}
	}//end has()

	/**
	 * The setting as an array; empty when it is not there or not JSON.
	 *
	 * @return array<string, mixed>
	 */
	private function setting(): array {
		try {
			$decoded = json_decode($this->appConfig->getValueString(self::APP, self::SETTING, ''), true);
		} catch (Throwable) {
			return [];
		}

		if (is_array($decoded) === false) {
			return [];
		}

		return $decoded;
	}//end setting()

	/**
	 * Store the setting; '' when stored, otherwise why not.
	 *
	 * @param array<string, mixed> $setting The setting.
	 *
	 * @return string
	 */
	private function store(array $setting): string {
		try {
			$this->appConfig->setValueString(self::APP, self::SETTING, (string) json_encode($setting));
		} catch (Throwable $e) {
			return $e->getMessage();
		}

		return '';
	}//end store()
}//end class
