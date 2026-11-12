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

		return $this->idsRefusal(data: $data);
	}//end refusal()

	/**
	 * Why a key holds something other than a register or schema id (a number, or text that is not empty), or ''.
	 *
	 * @param array<string, mixed> $data The filled keys.
	 *
	 * @return string
	 */
	private function idsRefusal(array $data): string {
		foreach ($data as $key => $value) {
			if (is_int($value) === false && (is_string($value) === false || $value === '')) {
				return $key.' is a register or schema id';
			}
		}

		return '';
	}//end idsRefusal()

	/**
	 * Merge the keys into the setting, recording what each held.
	 *
	 * @param ExampleSetRow $row The row: its app, its key in the fragment and its filled fields.
	 *
	 * @return array{error: string, entry: array{hadSetting: bool, set: array<string, mixed>, had: array<string, bool>, old: array<string, mixed>}|null}
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-openregisters-fragment-may-name-the-destruction-list-register-and-schema
	 */
	public function write(ExampleSetRow $row): array {
		$data = $row->data;
		$had     = $this->has();
		$setting = $this->setting();
		$entry   = ['hadSetting' => $had] + self::recordOf(data: $data, setting: $setting);

		$error = $this->store(setting: array_replace($setting, $data));
		if ($error !== '') {
			return ['error' => $error, 'entry' => null];
		}

		return ['error' => '', 'entry' => $entry];
	}//end write()

	/**
	 * What the merge sets, and whether and what each key held before.
	 *
	 * @param array<string, mixed> $data    The keys the set writes.
	 * @param array<string, mixed> $setting The setting before the merge.
	 *
	 * @return array{set: array<string, mixed>, had: array<string, bool>, old: array<string, mixed>}
	 */
	private static function recordOf(array $data, array $setting): array {
		$record = ['set' => $data, 'had' => [], 'old' => []];
		foreach (array_keys($data) as $name) {
			$record['had'][$name] = array_key_exists($name, $setting);
			$record['old'][$name] = ($setting[$name] ?? null);
		}

		return $record;
	}//end recordOf()

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
		$setting = $this->putBack(setting: $this->setting(), entry: $entry);
		if ($setting === [] && ($entry['hadSetting'] ?? true) === false) {
			return $this->deleteSetting();
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
	 * The setting with each key the set wrote, and that still holds what it wrote, put back to what it held before,
	 * or taken away when it held nothing; a key changed since stays.
	 *
	 * @param array<string, mixed> $setting The setting as stored.
	 * @param array<string, mixed> $entry   A record entry from write().
	 *
	 * @return array<string, mixed>
	 */
	private function putBack(array $setting, array $entry): array {
		foreach ((array) ($entry['set'] ?? []) as $name => $value) {
			if ($this->same(first: ($setting[$name] ?? null), second: $value) === false) {
				continue;
			}

			unset($setting[$name]);
			if (($entry['had'][$name] ?? false) === true) {
				$setting[$name] = ($entry['old'][$name] ?? null);
			}
		}

		return $setting;
	}//end putBack()

	/**
	 * Delete the setting the set brought into being.
	 *
	 * @return string `removed` or `failed`.
	 */
	private function deleteSetting(): string {
		try {
			$this->appConfig->deleteKey(self::APP, self::SETTING);
		} catch (Throwable) {
			return 'failed';
		}

		return 'removed';
	}//end deleteSetting()

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
