<?php

/**
 * The typed app config reads and writes an example set makes.
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
use Throwable;

/**
 * Reads a key as text whatever type it is kept as, and writes it back with its type.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-set-its-own-apps-config-and-undo-exactly-that-req-exs-015
 */
final class ExampleSetConfigStore {

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Nextcloud's app config.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Whether a key exists.
	 *
	 * @param string $app The app.
	 * @param string $key The key.
	 *
	 * @return bool
	 */
	public function has(string $app, string $key): bool {
		try {
			return $this->appConfig->hasKey($app, $key) === true;
		} catch (Throwable) {
			return false;
		}
	}//end has()

	/**
	 * A key's value as text, '' when it is not there or not text.
	 *
	 * @param ExampleSetConfigValue $target The key, and its type: `string`, or `int` for a key kept as an integer.
	 *
	 * @return string
	 */
	public function read(ExampleSetConfigValue $target): string {
		try {
			if ($target->type === 'int') {
				return $this->readInt(target: $target);
			}

			return $this->appConfig->getValueString($target->app, $target->key, '');
		} catch (Throwable) {
			return '';
		}
	}//end read()

	/**
	 * An integer key's value as text, '' when it is not there.
	 *
	 * @param ExampleSetConfigValue $target The key.
	 *
	 * @return string
	 */
	private function readInt(ExampleSetConfigValue $target): string {
		if ($this->has(app: $target->app, key: $target->key) === false) {
			return '';
		}

		return (string) $this->appConfig->getValueInt($target->app, $target->key);
	}//end readInt()

	/**
	 * Write a value with its type.
	 *
	 * @param ExampleSetConfigValue $target The key, the value as text and its type.
	 *
	 * @return void
	 */
	public function write(ExampleSetConfigValue $target): void {
		if ($target->type === 'int') {
			$this->appConfig->setValueInt($target->app, $target->key, (int) $target->value);
			return;
		}

		$this->appConfig->setValueString($target->app, $target->key, $target->value);
	}//end write()

	/**
	 * Write a value, reporting rather than throwing when the write fails.
	 *
	 * @param ExampleSetConfigValue $target The key and its value.
	 *
	 * @return bool Whether the write went through.
	 */
	public function tryWrite(ExampleSetConfigValue $target): bool {
		try {
			$this->write(target: $target);
		} catch (Throwable) {
			return false;
		}

		return true;
	}//end tryWrite()
}//end class
