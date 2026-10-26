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
 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-fragment-may-set-its-own-apps-config-and-undo-exactly-that-req-exs-015
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use OCP\IAppConfig;
use OCP\Security\ISecureRandom;
use Throwable;

/**
 * Writes, proves and restores app config for an example set.
 *
 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-fragment-may-set-its-own-apps-config-and-undo-exactly-that-req-exs-015
 */
class ExampleSetAppConfig {
	/**
	 * Length of a generated value.
	 */
	private const GENERATED_LENGTH = 40;

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
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-fragment-may-set-its-own-apps-config-and-undo-exactly-that-req-exs-015
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
	 * @param string $app      The app.
	 * @param string $key      The key.
	 * @param string $value    The filled value; ignored when generating.
	 * @param bool   $generate Whether to generate a value instead.
	 * @param string $type     `string`, or `int` for a key the app reads as an integer.
	 *
	 * @return array{error: string, entry: array<string, mixed>|null} The record entry (null when nothing had to be
	 *                                                                 written), or why the write failed.
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-fragment-may-set-its-own-apps-config-and-undo-exactly-that-req-exs-015
	 */
	public function apply(string $app, string $key, string $value, bool $generate, string $type='string'): array {
		$had = $this->has(app: $app, key: $key);
		$old = $this->read(app: $app, key: $key, type: $type);
		if (($generate === true && $had === true && $old !== '') || ($generate === false && $had === true && $old === $value)) {
			return ['error' => '', 'entry' => null];
		}

		try {
			if ($generate === true) {
				$generated = $this->random->generate(self::GENERATED_LENGTH, ISecureRandom::CHAR_ALPHANUMERIC);
				$this->appConfig->setValueString($app, $key, $generated, false, true);
				// A one-way fingerprint, never the value: remove can tell the generated value from one an administrator put there.
				return [
					'error' => '',
					'entry' => [
						'app' => $app,
						'key' => $key,
						'had' => $had,
						'old' => '',
						'new' => '',
						'generated' => true,
						'fingerprint' => hash('sha256', $generated),
					],
				];
			}

			$this->write(app: $app, key: $key, value: $value, type: $type);
		} catch (Throwable $e) {
			return ['error' => $e->getMessage(), 'entry' => null];
		}

		return ['error' => '', 'entry' => ['app' => $app, 'key' => $key, 'had' => $had, 'old' => $old, 'new' => $value, 'generated' => false, 'type' => $type]];
	}//end apply()

	/**
	 * Whether a written entry is no longer on the instance as written.
	 *
	 * @param array<string, mixed> $entry A record entry from apply().
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-an-install-proves-what-arrived-req-exs-006
	 */
	public function lost(array $entry): bool {
		if (($entry['generated'] ?? false) === true) {
			return $this->read(app: (string) $entry['app'], key: (string) $entry['key']) === '';
		}

		return $this->read(app: (string) $entry['app'], key: (string) $entry['key'], type: $this->typeOf(entry: $entry)) !== (string) $entry['new'];
	}//end lost()

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
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
	 */
	public function revert(array $entry): string {
		$app = (string) $entry['app'];
		$key = (string) $entry['key'];
		$generated = (($entry['generated'] ?? false) === true);
		if ($this->changedSince(entry: $entry) === true) {
			return 'left';
		}

		try {
			if ($entry['had'] === true && ($generated === false || isset($entry['fingerprint']) === true)) {
				$this->write(app: $app, key: $key, value: (string) $entry['old'], type: $this->typeOf(entry: $entry));
				return 'restored';
			}

			$this->appConfig->deleteKey($app, $key);
		} catch (Throwable) {
			return 'failed';
		}

		return 'restored';
	}//end revert()

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
		if ($this->has(app: $app, key: $key) === false) {
			return false;
		}

		if (($entry['generated'] ?? false) === true) {
			if (isset($entry['fingerprint']) === false) {
				return false;
			}

			return hash_equals((string) $entry['fingerprint'], hash('sha256', $this->read(app: $app, key: $key))) === false;
		}

		return $this->read(app: $app, key: $key, type: $this->typeOf(entry: $entry)) !== (string) $entry['new'];
	}//end changedSince()

	/**
	 * Whether a key exists.
	 *
	 * @param string $app The app.
	 * @param string $key The key.
	 *
	 * @return bool
	 */
	private function has(string $app, string $key): bool {
		try {
			return $this->appConfig->hasKey($app, $key) === true;
		} catch (Throwable) {
			return false;
		}
	}//end has()

	/**
	 * Whether an existing key is kept as sensitive.
	 *
	 * @param string $app The app.
	 * @param string $key The key.
	 *
	 * @return bool
	 */
	private function sensitive(string $app, string $key): bool {
		if ($this->has(app: $app, key: $key) === false) {
			return false;
		}

		try {
			return $this->appConfig->isSensitive($app, $key) === true;
		} catch (Throwable) {
			return true;
		}
	}//end sensitive()

	/**
	 * A key's value as text, '' when it is not there or not text.
	 *
	 * @param string $app  The app.
	 * @param string $key  The key.
	 * @param string $type `string`, or `int` for a key kept as an integer.
	 *
	 * @return string
	 */
	private function read(string $app, string $key, string $type='string'): string {
		try {
			if ($type === 'int') {
				if ($this->has(app: $app, key: $key) === false) {
					return '';
				}

				return (string) $this->appConfig->getValueInt($app, $key);
			}

			return $this->appConfig->getValueString($app, $key, '');
		} catch (Throwable) {
			return '';
		}
	}//end read()

	/**
	 * Write a value with its type.
	 *
	 * @param string $app   The app.
	 * @param string $key   The key.
	 * @param string $value The value as text.
	 * @param string $type  `string`, or `int` for a key the app reads as an integer.
	 *
	 * @return void
	 */
	private function write(string $app, string $key, string $value, string $type): void {
		if ($type === 'int') {
			$this->appConfig->setValueInt($app, $key, (int) $value);
			return;
		}

		$this->appConfig->setValueString($app, $key, $value);
	}//end write()

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
