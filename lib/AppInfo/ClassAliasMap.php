<?php

/**
 * ClassAliasMap: keeps the old name of a moved class working.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category AppInfo
 * @package  OCA\OpenRegister\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppInfo;

use OCP\AppFramework\Bootstrap\IRegistrationContext;
use UnexpectedValueException;

/**
 * Old fully qualified class name => new one, read from appinfo/class-aliases.json.
 *
 * Other apps import OpenRegister classes by name. When a class moves to a
 * component directory, its old name keeps resolving in three ways:
 *
 * - as a class: an eager entry is aliased at boot, a lazy one on first
 *   autoload. PHP does not autoload for `instanceof`, `catch` or a parameter
 *   type check, so a name any other app uses in one of those positions must be
 *   eager, or the check is silently false or a TypeError.
 * - as a container service: the old name is an alias of the new one, so both
 *   names return the same shared instance. Entries with `service: false` (classes
 *   only this app uses, kept for serialised data) skip this.
 * - as a background job: RepointMovedBackgroundJobs rewrites the job list.
 *
 * The map is a data file, not code, so no shim file sits at an old path.
 */
final class ClassAliasMap {

	/**
	 * The map that ships with the app.
	 */
	public const MAP_FILE = __DIR__ . '/../../appinfo/class-aliases.json';

	/**
	 * Old names the lazy autoloader may alias, across every map applied in this process.
	 *
	 * @var array<string, string>
	 */
	private static array $lazy = [];

	/**
	 * Whether the lazy autoloader is registered.
	 *
	 * @var boolean
	 */
	private static bool $autoloaderRegistered = false;

	/**
	 * Constructor.
	 *
	 * @param array<string, array{class: string, eager: bool, service?: bool}> $entries Old name => new name, loading mode and
	 *                                                                       whether the old name is a container alias.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly array $entries,
	) {
	}//end __construct()

	/**
	 * Read a map file. A missing file is an empty map; a malformed one throws.
	 *
	 * @param string $path The JSON file.
	 *
	 * @return self
	 *
	 * @throws UnexpectedValueException When the file is not a valid map.
	 */
	public static function fromFile(string $path=self::MAP_FILE): self {
		if (is_file($path) === false) {
			return new self(entries: []);
		}

		$data = json_decode((string) file_get_contents($path), true);
		if (is_array($data) === false || is_array($data['aliases'] ?? null) === false) {
			throw new UnexpectedValueException('The class alias map has no "aliases" object: '.$path);
		}

		$entries = [];
		foreach ($data['aliases'] as $old => $entry) {
			if (is_array($entry) === false || is_string($entry['class'] ?? null) === false) {
				throw new UnexpectedValueException('The class alias map entry for '.$old.' names no class.');
			}

			$entries[(string) $old] = [
				'class'   => $entry['class'],
				'eager'   => ($entry['eager'] ?? false) === true,
				'service' => ($entry['service'] ?? true) === true,
			];
		}

		return new self(entries: $entries);
	}//end fromFile()

	/**
	 * The entries of this map.
	 *
	 * @return array<string, array{class: string, eager: bool, service?: bool}>
	 */
	public function entries(): array {
		return $this->entries;
	}//end entries()

	/**
	 * Alias the classes and register the old names as container aliases.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	public function apply(IRegistrationContext $context): void {
		$this->aliasClasses();
		$this->registerServices(context: $context);
	}//end apply()

	/**
	 * Alias every eager entry now and hand the lazy ones to the autoloader.
	 *
	 * Safe to call more than once: a name that already exists is left alone.
	 *
	 * @return void
	 */
	public function aliasClasses(): void {
		foreach ($this->entries as $old => $entry) {
			if ($entry['eager'] === true) {
				self::alias(old: $old, new: $entry['class']);
				continue;
			}

			self::$lazy[$old] = $entry['class'];
		}

		if (self::$lazy !== [] && self::$autoloaderRegistered === false) {
			spl_autoload_register([self::class, 'autoload']);
			self::$autoloaderRegistered = true;
		}
	}//end aliasClasses()

	/**
	 * Register each service entry's old name as a container alias of the new one, and the map itself.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	public function registerServices(IRegistrationContext $context): void {
		foreach ($this->entries as $old => $entry) {
			if (($entry['service'] ?? true) === true) {
				$context->registerServiceAlias($old, $entry['class']);
			}
		}

		$context->registerService(
			self::class,
			fn (): self => $this
		);
	}//end registerServices()

	/**
	 * The autoloader for lazy entries.
	 *
	 * @param string $class The class PHP is looking for.
	 *
	 * @return void
	 */
	public static function autoload(string $class): void {
		if (isset(self::$lazy[$class]) === true) {
			self::alias(old: $class, new: self::$lazy[$class]);
		}
	}//end autoload()

	/**
	 * Alias one class, unless the old name is already taken.
	 *
	 * @param string $old The old name.
	 * @param string $new The class it now lives under.
	 *
	 * @return void
	 */
	private static function alias(string $old, string $new): void {
		if (class_exists($old, false) === true || interface_exists($old, false) === true || trait_exists($old, false) === true) {
			return;
		}

		class_alias($new, $old);
	}//end alias()
}//end class
