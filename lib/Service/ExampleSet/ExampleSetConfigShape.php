<?php

/**
 * OpenRegister Example Set Config Shape
 *
 * Checks the app config entries and per-user preferences of one fragment of an
 * example set: each for the fragment's own app, with a value it can write.
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
 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-is-gathered-from-every-enabled-app-in-a-declared-order-req-exs-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * The app config and preferences of one fragment, for one app and file.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-an-app-config-entry-may-name-its-type
 */
class ExampleSetConfigShape {
	/**
	 * Constructor.
	 *
	 * @param string $app  The app that ships the fragment.
	 * @param string $file The fragment file, for messages.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly string $app,
		private readonly string $file,
	) {
	}//end __construct()

	/**
	 * One app config entry, for the fragment's own app.
	 *
	 * @param int|string $index The entry's position, for messages.
	 * @param mixed      $entry The declared entry.
	 *
	 * @return array{key: string, value: mixed, generate: bool, type: string, atLeast: bool}
	 *
	 * @throws ExampleSetException When the entry names another app, lacks a key, has no value to write, or a type it
	 *                             cannot write or whose value does not fit.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-an-app-config-entry-may-name-its-type
	 */
	public function appConfig(int|string $index, mixed $entry): array {
		$entry = (array) $entry;
		$this->refuseAppConfig(index: $index, entry: $entry);

		return [
			'key'      => $entry['key'],
			'value'    => ($entry['value'] ?? ''),
			'generate' => $this->generates(entry: $entry),
			'type'     => $this->appConfigType(index: (int) $index, entry: $entry),
			'atLeast'  => $this->appConfigFloor(index: (int) $index, entry: $entry),
		];
	}//end appConfig()

	/**
	 * Refuse an app config entry for another app, or one without a key and a value to write.
	 *
	 * @param int|string           $index The entry's position, for messages.
	 * @param array<string, mixed> $entry The declared entry.
	 *
	 * @return void
	 *
	 * @throws ExampleSetException When the entry names another app, or lacks a key or a value.
	 */
	private function refuseAppConfig(int|string $index, array $entry): void {
		if ($this->ownApp(entry: $entry) === false) {
			throw new ExampleSetException($this->file.': app config '.$index.' is for "'.$entry['app'].'"; a fragment sets only its own app\'s config.');
		}

		if ($this->writable(entry: $entry) === false) {
			throw new ExampleSetException($this->file.': app config '.$index.' needs a key and a value, or generate: true.');
		}
	}//end refuseAppConfig()

	/**
	 * Whether an app config entry has a key and a value to write, or is generated.
	 *
	 * @param array<string, mixed> $entry The declared entry.
	 *
	 * @return bool
	 */
	private function writable(array $entry): bool {
		return is_string($entry['key'] ?? null) === true && $entry['key'] !== ''
			&& ($this->generates(entry: $entry) === true || is_scalar($entry['value'] ?? null) === true);
	}//end writable()

	/**
	 * Whether the set generates an app config entry's value.
	 *
	 * @param array<string, mixed> $entry The declared entry.
	 *
	 * @return bool
	 */
	private function generates(array $entry): bool {
		return ($entry['generate'] ?? false) === true;
	}//end generates()

	/**
	 * Whether an app config entry is a floor (`atLeast`): a running counter the set raises to its value but never
	 * lowers, which the app may move on after the install.
	 *
	 * @param int                  $index The entry's position, for messages.
	 * @param array<string, mixed> $entry The declared entry.
	 *
	 * @return bool
	 *
	 * @throws ExampleSetException When a floor is not a written integer.
	 *
	 * @spec openspec/changes/example-set-app-config-at-least/specs/example-sets/spec.md#requirement-an-integer-app-config-entry-may-be-a-floor
	 */
	private function appConfigFloor(int $index, array $entry): bool {
		if (($entry['atLeast'] ?? false) !== true) {
			return false;
		}

		if ($this->generates(entry: $entry) === true || (string) ($entry['type'] ?? 'string') !== 'int') {
			throw new ExampleSetException($this->file.': app config '.$index.' ("'.(string) $entry['key'].'") is atLeast; only a written int can be a floor.');
		}

		return true;
	}//end appConfigFloor()

	/**
	 * The type an app config entry is written with: `string`, or `int` for a key its app reads as an integer.
	 *
	 * @param int                  $index The entry's position, for messages.
	 * @param array<string, mixed> $entry The declared entry.
	 *
	 * @return string
	 *
	 * @throws ExampleSetException When the type is not one a set writes, or an integer's value is no integer.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-an-app-config-entry-may-name-its-type
	 */
	private function appConfigType(int $index, array $entry): string {
		$type = (string) ($entry['type'] ?? 'string');
		if (in_array($type, ['string', 'int'], true) === false) {
			throw new ExampleSetException($this->file.': app config '.$index.' has type "'.$type.'"; a set writes string or int.');
		}

		if ($type === 'int' && $this->generates(entry: $entry) === false && $this->integerValue(entry: $entry) === false) {
			throw new ExampleSetException($this->file.': app config '.$index.' ("'.(string) $entry['key'].'") is an integer; its value is not.');
		}

		return $type;
	}//end appConfigType()

	/**
	 * Whether an app config entry's value is an integer, or a placeholder that becomes one.
	 *
	 * @param array<string, mixed> $entry The declared entry.
	 *
	 * @return bool
	 */
	private function integerValue(array $entry): bool {
		return preg_match('/^(-?\d+|\{\{[^{}]+\}\})$/', (string) ($entry['value'] ?? '')) === 1;
	}//end integerValue()

	/**
	 * One per-user preference, for the fragment's own app.
	 *
	 * @param int|string $index The entry's position, for messages.
	 * @param mixed      $entry The declared entry.
	 *
	 * @return array{user: string, key: string, value: string}
	 *
	 * @throws ExampleSetException When the entry names another app, or lacks a user, a key or a value.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-set-its-own-apps-preferences-for-the-sets-own-accounts-req-exs-024
	 */
	public function userPreference(int|string $index, mixed $entry): array {
		$entry = (array) $entry;
		if ($this->ownApp(entry: $entry) === false) {
			throw new ExampleSetException(
				$this->file.': user preference '.$index.' is for "'.$entry['app'].'"; a fragment sets only its own app\'s preferences.'
			);
		}

		if ($this->hasPreference(entry: $entry) === false) {
			throw new ExampleSetException($this->file.': user preference '.$index.' needs a user, a key and a value.');
		}

		return ['user' => $entry['user'], 'key' => $entry['key'], 'value' => (string) $entry['value']];
	}//end userPreference()

	/**
	 * Whether a preference names a user and a key, and has a value.
	 *
	 * @param array<string, mixed> $entry The declared entry.
	 *
	 * @return bool
	 */
	private function hasPreference(array $entry): bool {
		return is_string($entry['user'] ?? null) === true && $entry['user'] !== ''
			&& is_string($entry['key'] ?? null) === true && $entry['key'] !== ''
			&& is_scalar($entry['value'] ?? null) === true;
	}//end hasPreference()

	/**
	 * Whether an entry is for the fragment's own app: it names none, or names that app.
	 *
	 * @param array<string, mixed> $entry The declared entry.
	 *
	 * @return bool
	 */
	private function ownApp(array $entry): bool {
		return (string) ($entry['app'] ?? $this->app) === $this->app;
	}//end ownApp()
}//end class
