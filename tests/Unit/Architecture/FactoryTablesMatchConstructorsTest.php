<?php

/**
 * Every table in lib/ that maps a class to named constructor arguments agrees
 * with that class's constructor.
 *
 * The tables are spread into `new $class(...$arguments)`. A key the constructor
 * no longer has throws `Unknown named parameter` and a required parameter the
 * table leaves out throws `ArgumentCountError`, both only when the service is
 * first built, so the registrar tests and static analysis do not see them.
 * The tables are found from the source, so a new table is covered without being
 * listed here. Nothing autowires a parameter of a table entry: every required
 * parameter has to be in the table.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Architecture
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use ArrayObject;
use DateInterval;
use FilesystemIterator;
use OCA\OpenRegister\Service\Integration\BuiltinIntegrationServices;
use OCA\OpenRegister\Service\ObjectSource\ObjectSourceWiring;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * Structural: named-argument factory tables against their constructors.
 *
 * @coversNothing
 */
class FactoryTablesMatchConstructorsTest extends TestCase {

	/**
	 * A class key whose array value opens with a named argument.
	 */
	private const TABLE_ENTRY = '/(?:::class|\\\\[A-Za-z0-9_]+\')\s*=>\s*\[\s*(?:\/\/[^\n]*\s*)*\'[a-z][A-Za-z0-9_]*\'\s*=>/';

	/**
	 * The tables the discovery has to find, so it cannot pass by finding nothing.
	 */
	private const KNOWN_TABLES = [
		BuiltinIntegrationServices::class . '::FACTORIES',
		ObjectSourceWiring::class . '::SERVICES',
	];

	/**
	 * Every named key is a constructor parameter and every required parameter is supplied.
	 *
	 * @return void
	 */
	public function testEveryTableMatchesItsConstructors(): void {
		$violations = [];
		foreach ($this->discoverTables() as $name => $entries) {
			array_push($violations, ...$this->tableViolations(table: $name, entries: $entries));
		}

		$this->assertSame([], $violations, implode("\n", $violations));
	}//end testEveryTableMatchesItsConstructors()

	/**
	 * The discovery finds the tables known to exist.
	 *
	 * @return void
	 */
	public function testDiscoveryFindsTheKnownTables(): void {
		$found = array_keys($this->discoverTables());

		foreach (self::KNOWN_TABLES as $table) {
			$this->assertContains($table, $found);
		}
	}//end testDiscoveryFindsTheKnownTables()

	/**
	 * A matching entry has no violations.
	 *
	 * @return void
	 */
	public function testAMatchingEntryPasses(): void {
		$entries = [
			DateInterval::class => ['duration' => 'id'],
			ArrayObject::class  => [],
		];

		$this->assertSame([], $this->tableViolations(table: 'T', entries: $entries));
	}//end testAMatchingEntryPasses()

	/**
	 * A key the constructor does not have is reported.
	 *
	 * @return void
	 */
	public function testAnUnknownKeyIsReported(): void {
		$violations = $this->tableViolations(
			table: 'T',
			entries: [DateInterval::class => ['duration' => 'id', 'appName' => 'id']]
		);

		$this->assertSame(['T[DateInterval]: appName is not a constructor parameter'], $violations);
	}//end testAnUnknownKeyIsReported()

	/**
	 * A required parameter the table leaves out is reported.
	 *
	 * @return void
	 */
	public function testAMissingRequiredParameterIsReported(): void {
		$violations = $this->tableViolations(table: 'T', entries: [DateInterval::class => []]);

		$this->assertSame(['T[DateInterval]: required parameter duration is not supplied'], $violations);
	}//end testAMissingRequiredParameterIsReported()

	/**
	 * A bundle's own named arguments are held to the same rule.
	 *
	 * @return void
	 */
	public function testABundleIsChecked(): void {
		$violations = $this->tableViolations(
			table: 'T',
			entries: [ArrayObject::class => ['array' => [DateInterval::class, ['appName' => 'id']]]]
		);

		$this->assertSame(
			[
				'T[ArrayObject].array[DateInterval]: appName is not a constructor parameter',
				'T[ArrayObject].array[DateInterval]: required parameter duration is not supplied',
			],
			$violations
		);
	}//end testABundleIsChecked()

	/**
	 * A key naming a class that does not exist is reported.
	 *
	 * @return void
	 */
	public function testAMissingClassIsReported(): void {
		$violations = $this->tableViolations(table: 'T', entries: ['OCA\\Nope\\Gone' => ['a' => 'id']]);

		$this->assertSame(['T[OCA\\Nope\\Gone]: class does not exist'], $violations);
	}//end testAMissingClassIsReported()

	/**
	 * Find every table in lib/, by name.
	 *
	 * @return array<string, array<string, array<string, mixed>>> Table name to its entries.
	 */
	private function discoverTables(): array {
		$root   = dirname(__DIR__, 3) . '/lib';
		$tables = [];
		$files  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

		foreach ($files as $file) {
			$path = (string) $file;
			if (str_ends_with($path, '.php') === false || preg_match(self::TABLE_ENTRY, (string) file_get_contents($path)) !== 1) {
				continue;
			}

			$class = 'OCA\\OpenRegister\\' . str_replace('/', '\\', substr($path, strlen($root) + 1, -4));
			$found = $this->tablesOfClass(class: $class);
			$this->assertNotSame([], $found, $class . ' writes a table that is not a constant or static property, so it cannot be checked.');
			$tables += $found;
		}

		return $tables;
	}//end discoverTables()

	/**
	 * The constants and static properties of a class that are tables.
	 *
	 * @param class-string $class The class to read.
	 *
	 * @return array<string, array<string, array<string, mixed>>> Table name to its entries.
	 */
	private function tablesOfClass(string $class): array {
		$reflection = new ReflectionClass($class);
		$tables     = [];

		foreach ([...$reflection->getConstants(), ...$reflection->getStaticProperties()] as $name => $value) {
			if ($this->isTable(value: $value) === true) {
				$tables[$class . '::' . $name] = $value;
			}
		}

		return $tables;
	}//end tablesOfClass()

	/**
	 * Whether a value maps class names to named constructor arguments.
	 *
	 * @param mixed $value The value of a constant or static property.
	 *
	 * @return bool
	 */
	private function isTable(mixed $value): bool {
		if (is_array($value) === false || $value === []) {
			return false;
		}

		$hasNamedArguments = false;
		foreach ($value as $key => $arguments) {
			if (is_string($key) === false || preg_match('/^[A-Za-z_]\w*(\\\\[A-Za-z_]\w*)+$/', $key) !== 1 || is_array($arguments) === false) {
				return false;
			}

			if ($arguments !== [] && array_is_list($arguments) === true) {
				return false;
			}

			$hasNamedArguments = $hasNamedArguments || $arguments !== [];
		}

		return $hasNamedArguments;
	}//end isTable()

	/**
	 * Every mismatch between a table and the constructors it names.
	 *
	 * @param string                              $table   Label for the messages.
	 * @param array<string, array<string, mixed>> $entries Class to named arguments.
	 *
	 * @return list<string> One message per mismatch.
	 */
	private function tableViolations(string $table, array $entries): array {
		$violations = [];
		foreach ($entries as $class => $arguments) {
			array_push($violations, ...$this->entryViolations(label: $table . '[' . $class . ']', class: $class, arguments: $arguments));
		}

		return $violations;
	}//end tableViolations()

	/**
	 * Every mismatch of one entry, including the bundles it builds.
	 *
	 * @param string               $label     Label for the messages.
	 * @param string               $class     The class the entry builds.
	 * @param array<string, mixed> $arguments Named arguments of the entry.
	 *
	 * @return list<string> One message per mismatch.
	 */
	private function entryViolations(string $label, string $class, array $arguments): array {
		if (class_exists($class) === false) {
			return [$label . ': class does not exist'];
		}

		$parameters = ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? []);
		$accepted   = [];
		$variadic   = false;
		foreach ($parameters as $parameter) {
			$accepted[] = $parameter->getName();
			$variadic   = $variadic || $parameter->isVariadic();
		}

		$violations = [];
		foreach ($arguments as $name => $source) {
			if ($variadic === false && in_array($name, $accepted, true) === false) {
				$violations[] = $label . ': ' . $name . ' is not a constructor parameter';
			}

			if (is_array($source) === true) {
				$bundleLabel = $label . '.' . $name . '[' . $source[0] . ']';
				array_push($violations, ...$this->entryViolations(label: $bundleLabel, class: $source[0], arguments: $source[1]));
			}
		}

		foreach ($parameters as $parameter) {
			if ($parameter->isOptional() === false && array_key_exists($parameter->getName(), $arguments) === false) {
				$violations[] = $label . ': required parameter ' . $parameter->getName() . ' is not supplied';
			}
		}

		return $violations;
	}//end entryViolations()
}//end class
