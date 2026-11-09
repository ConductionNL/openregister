<?php

/**
 * OpenRegister Example Set App Config
 *
 * App config an example set writes for the app that ships the fragment, such
 * as the account a background job runs as. The old value is recorded and put
 * back on remove, but only while the key still holds what the set wrote, so a
 * value an administrator changed since stays. A key the app keeps as sensitive
 * is refused, unless the set generates the value itself: that value is stored
 * as sensitive, never printed, and never kept in the install record.
 *
 * An integer entry declared `atLeast` is a floor for a running counter, such
 * as the last ticket number handed out: the set raises the key to its value
 * but never lowers it, the app may move it on afterwards, and remove leaves a
 * counter that moved on.
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
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-set-its-own-apps-config-and-undo-exactly-that-req-exs-015
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use OCP\IAppConfig;
use OCP\Security\ISecureRandom;
use Throwable;

/**
 * Writes, proves and restores app config for an example set.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-set-its-own-apps-config-and-undo-exactly-that-req-exs-015
 */
class ExampleSetAppConfig {
	/**
	 * Length of a generated value.
	 */
	private const GENERATED_LENGTH = 40;

	/**
	 * Reads and writes typed keys.
	 *
	 * @var ExampleSetConfigStore
	 */
	private readonly ExampleSetConfigStore $store;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig    $appConfig Nextcloud's app config.
	 * @param ISecureRandom $random    Makes generated values.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ISecureRandom $random,
	) {
		$this->store = new ExampleSetConfigStore(appConfig: $appConfig);
	}//end __construct()

	/**
	 * Why an entry cannot be written, or ''.
	 *
	 * @param string $app      The app.
	 * @param string $key      The key.
	 * @param bool   $generate Whether the set generates the value.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-set-its-own-apps-config-and-undo-exactly-that-req-exs-015
	 */
	public function refusal(string $app, string $key, bool $generate): string {
		if ($generate === true || $this->sensitive(app: $app, key: $key) === false) {
			return '';
		}

		return 'app config '.$app.' '.$key.' is kept as sensitive; a set may only generate such a value, never write one from a file';
	}//end refusal()

	/**
	 * Write an entry when the key does not hold that value yet.
	 *
	 * @param ExampleSetConfigValue $target The key, the filled value (ignored when generating), its type, and its kind:
	 *                                      exact, generated, or a floor that an integer key already at or above it
	 *                                      leaves as it is.
	 *
	 * @return array{error: string, entry: array<string, mixed>|null} The record entry (null when nothing had to be
	 *                                                                 written), or why the write failed.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-set-its-own-apps-config-and-undo-exactly-that-req-exs-015
	 * @spec openspec/changes/example-set-app-config-at-least/specs/example-sets/spec.md#requirement-an-integer-app-config-entry-may-be-a-floor
	 */
	public function apply(ExampleSetConfigValue $target): array {
		$had    = $this->store->has(app: $target->app, key: $target->key);
		$old    = $this->store->read(target: $target);
		if ($had === true && $this->alreadyThere(old: $old, target: $target) === true) {
			return ['error' => '', 'entry' => null];
		}

		try {
			return ['error' => '', 'entry' => $this->writeEntry(target: $target, prior: ['had' => $had, 'old' => $old])];
		} catch (Throwable $e) {
			return ['error' => $e->getMessage(), 'entry' => null];
		}
	}//end apply()

	/**
	 * Write an entry's value and describe what was written, for the install record.
	 *
	 * @param ExampleSetConfigValue         $target The key and what it should hold.
	 * @param array{had: bool, old: string} $prior  Whether the key existed, and what it held.
	 *
	 * @return array<string, mixed> The record entry.
	 */
	private function writeEntry(ExampleSetConfigValue $target, array $prior): array {
		if ($target->kind === ExampleSetConfigValue::KIND_GENERATED) {
			return $this->generatedEntry(target: $target, prior: $prior);
		}

		$this->store->write(target: $target);
		$entry = ['app' => $target->app, 'key' => $target->key, 'had' => $prior['had'], 'old' => $prior['old'], 'new' => $target->value];
		$entry += ['generated' => false, 'type' => $target->type];
		if ($target->kind === ExampleSetConfigValue::KIND_FLOOR) {
			$entry['atLeast'] = true;
		}

		return $entry;
	}//end writeEntry()

	/**
	 * Generate a value, store it as sensitive, and describe it without the value.
	 *
	 * @param ExampleSetConfigValue         $target The key.
	 * @param array{had: bool, old: string} $prior  Whether the key existed, and what it held.
	 *
	 * @return array<string, mixed> The record entry.
	 */
	private function generatedEntry(ExampleSetConfigValue $target, array $prior): array {
		$generated = $this->random->generate(self::GENERATED_LENGTH, ISecureRandom::CHAR_ALPHANUMERIC);
		$this->appConfig->setValueString($target->app, $target->key, $generated, false, true);
		// A one-way fingerprint, never the value: remove can tell the generated value from one an administrator put there.
		return [
			'app' => $target->app,
			'key' => $target->key,
			'had' => $prior['had'],
			'old' => '',
			'new' => '',
			'generated' => true,
			'fingerprint' => hash('sha256', $generated),
		];
	}//end generatedEntry()

	/**
	 * Bring a recorded entry up to a floor declared after it was written: an entry without `atLeast`, or with a lower
	 * floor. The key is written only when it holds less than the floor. The returned entry keeps `had` and `old`, so
	 * remove still puts back what was there before the set.
	 *
	 * @param array<string, mixed> $entry A record entry from apply().
	 * @param string               $value The declared floor.
	 *
	 * @return array{entry: array<string, mixed>|null, written: bool} The updated entry (null when it already holds this
	 *                                                                floor, or the write failed) and whether the key was written.
	 *
	 * @spec openspec/changes/example-set-recorded-floor/specs/example-sets/spec.md#requirement-a-recorded-app-config-entry-follows-a-floor-declared-later
	 */
	public function raise(array $entry, string $value): array {
		if ($this->holdsFloor(entry: $entry, value: $value) === true) {
			return ['entry' => null, 'written' => false];
		}

		$target  = new ExampleSetConfigValue(at: new ExampleSetConfigKey(app: (string) $entry['app'], key: (string) $entry['key']), value: $value, type: 'int');
		$current = $this->store->read(target: $target);
		$written = ($current === '' || (int) $current < (int) $value);
		if ($written === true && $this->store->tryWrite(target: $target) === false) {
			return ['entry' => null, 'written' => false];
		}

		return ['entry' => array_merge($entry, ['new' => $value, 'type' => 'int', 'atLeast' => true]), 'written' => $written];
	}//end raise()

	/**
	 * Whether a recorded entry is already a floor of at least this value.
	 *
	 * @param array<string, mixed> $entry A record entry from apply().
	 * @param string               $value The declared floor.
	 *
	 * @return bool
	 */
	private function holdsFloor(array $entry, string $value): bool {
		return ($entry['atLeast'] ?? false) === true && (int) $entry['new'] >= (int) $value;
	}//end holdsFloor()

	/**
	 * Whether an existing key already holds what the entry asks for: any value for a generated entry, the same value
	 * for a written one, and the value or more for a floor.
	 *
	 * @param string                $old    The key's current value.
	 * @param ExampleSetConfigValue $target The entry's key, value and kind.
	 *
	 * @return bool
	 */
	private function alreadyThere(string $old, ExampleSetConfigValue $target): bool {
		if ($target->kind === ExampleSetConfigValue::KIND_GENERATED) {
			return $old !== '';
		}

		if ($target->kind === ExampleSetConfigValue::KIND_FLOOR && $old !== '') {
			return (int) $old >= (int) $target->value;
		}

		return $old === $target->value;
	}//end alreadyThere()

	/**
	 * Whether a written entry is no longer on the instance as written; a floor only when the key fell below it.
	 *
	 * @param array<string, mixed> $entry A record entry from apply().
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-an-install-proves-what-arrived-req-exs-006
	 * @spec openspec/changes/example-set-app-config-at-least/specs/example-sets/spec.md#requirement-an-integer-app-config-entry-may-be-a-floor
	 */
	public function lost(array $entry): bool {
		if (($entry['generated'] ?? false) === true) {
			return $this->store->read(target: $this->targetOf(entry: $entry, type: 'string')) === '';
		}

		if (($entry['atLeast'] ?? false) === true) {
			return $this->belowFloor(entry: $entry);
		}

		return $this->store->read(target: $this->targetOf(entry: $entry, type: $this->typeOf(entry: $entry))) !== (string) $entry['new'];
	}//end lost()

	/**
	 * Whether a floor entry's key is gone or fell below the floor.
	 *
	 * @param array<string, mixed> $entry A floor record entry from apply().
	 *
	 * @return bool
	 */
	private function belowFloor(array $entry): bool {
		$current = $this->store->read(target: $this->targetOf(entry: $entry, type: 'int'));

		return $current === '' || (int) $current < (int) $entry['new'];
	}//end belowFloor()

	/**
	 * The key of a record entry, to read as the given type.
	 *
	 * @param array<string, mixed> $entry A record entry from apply().
	 * @param string               $type  `string`, or `int` for a key kept as an integer.
	 *
	 * @return ExampleSetConfigValue
	 */
	private function targetOf(array $entry, string $type): ExampleSetConfigValue {
		return new ExampleSetConfigValue(at: new ExampleSetConfigKey(app: (string) $entry['app'], key: (string) $entry['key']), type: $type);
	}//end targetOf()

	/**
	 * Put back what an entry replaced, while the key still holds what the set wrote.
	 *
	 * A generated value is recognised by its fingerprint (the record never
	 * holds the value): one that no longer has it was replaced by somebody,
	 * and stays. A key that held an empty value before the set generated one
	 * gets that value back; a key that was not there is deleted. An entry
	 * from before fingerprints was recorded has none to compare and is
	 * restored as it always was.
	 *
	 * @param array<string, mixed> $entry A record entry from apply().
	 *
	 * @return string `restored`, `left` (somebody changed it since) or `failed`.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
	 */
	public function revert(array $entry): string {
		if ($this->changedSince(entry: $entry) === true) {
			return 'left';
		}

		try {
			$this->putBack(entry: $entry);
		} catch (Throwable) {
			return 'failed';
		}

		return 'restored';
	}//end revert()

	/**
	 * Write back the value an entry replaced, or delete a key the set added.
	 *
	 * A generated entry from before fingerprints was recorded is restored as it always was: deleted.
	 *
	 * @param array<string, mixed> $entry A record entry from apply().
	 *
	 * @return void
	 */
	private function putBack(array $entry): void {
		$app = (string) $entry['app'];
		$key = (string) $entry['key'];
		$generated = (($entry['generated'] ?? false) === true);
		if ($entry['had'] === true && ($generated === false || isset($entry['fingerprint']) === true)) {
			$this->store->write(target: new ExampleSetConfigValue(at: new ExampleSetConfigKey(app: $app, key: $key), value: (string) $entry['old'], type: $this->typeOf(entry: $entry)));
			return;
		}

		$this->appConfig->deleteKey($app, $key);
	}//end putBack()

	/**
	 * Whether the key no longer holds what the set wrote: a written value that differs, or a generated one that lost its fingerprint.
	 *
	 * @param array<string, mixed> $entry A record entry from apply().
	 *
	 * @return bool
	 */
	private function changedSince(array $entry): bool {
		$app = (string) $entry['app'];
		$key = (string) $entry['key'];
		if ($this->store->has(app: $app, key: $key) === false) {
			return false;
		}

		if (($entry['generated'] ?? false) === true) {
			return $this->lostFingerprint(entry: $entry);
		}

		return $this->store->read(target: $this->targetOf(entry: $entry, type: $this->typeOf(entry: $entry))) !== (string) $entry['new'];
	}//end changedSince()

	/**
	 * Whether a generated value no longer matches its fingerprint; an entry without one cannot tell, so it has not.
	 *
	 * @param array<string, mixed> $entry A generated record entry from apply().
	 *
	 * @return bool
	 */
	private function lostFingerprint(array $entry): bool {
		if (isset($entry['fingerprint']) === false) {
			return false;
		}

		$current = $this->store->read(target: $this->targetOf(entry: $entry, type: 'string'));

		return hash_equals((string) $entry['fingerprint'], hash('sha256', $current)) === false;
	}//end lostFingerprint()

	/**
	 * Whether an existing key is kept as sensitive.
	 *
	 * @param string $app The app.
	 * @param string $key The key.
	 *
	 * @return bool
	 */
	private function sensitive(string $app, string $key): bool {
		if ($this->store->has(app: $app, key: $key) === false) {
			return false;
		}

		try {
			return $this->appConfig->isSensitive($app, $key) === true;
		} catch (Throwable) {
			return true;
		}
	}//end sensitive()

	/**
	 * The type a record entry was written with; an entry from before types were recorded is text.
	 *
	 * @param array<string, mixed> $entry A record entry from apply().
	 *
	 * @return string
	 */
	private function typeOf(array $entry): string {
		return (string) ($entry['type'] ?? 'string');
	}//end typeOf()
}//end class
