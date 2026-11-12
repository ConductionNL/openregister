<?php

/**
 * The classes lane D5 hid behind an interface stay hidden and stay bound.
 *
 * Each class below implements the interface next to it, its component's
 * registrar binds that interface to it, and no lib/ file outside the class's
 * component names the class. A missing binding is a runtime 500 that the
 * other unit tests do not catch, which is why the binding is checked here.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Architecture
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use OCA\OpenRegister\Service\ContractBindings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @coversNothing
 */
final class D5InterfaceBindingsTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * Class => [its interface, the registrar that binds it].
	 *
	 * @var array<class-string, array{0: class-string, 1: class-string}>
	 */
	private const CASES = [
		'OCA\OpenRegister\Service\PersonLinkService' => ['OCA\OpenRegister\Service\PersonLinkServiceInterface', ContractBindings::class],
		'OCA\OpenRegister\Service\PhotoLinkService' => ['OCA\OpenRegister\Service\PhotoLinkServiceInterface', ContractBindings::class],
		'OCA\OpenRegister\Service\PollLinkService' => ['OCA\OpenRegister\Service\PollLinkServiceInterface', ContractBindings::class],
		'OCA\OpenRegister\Service\RegisterSchemaLinkageRepairService' => [
			'OCA\OpenRegister\Service\RegisterSchemaLinkageRepairServiceInterface',
			ContractBindings::class,
		],
		'OCA\OpenRegister\Service\RegisterService' => ['OCA\OpenRegister\Service\RegisterServiceInterface', ContractBindings::class],
		'OCA\OpenRegister\Service\SchemaApplicationMigrator' => [
			'OCA\OpenRegister\Service\SchemaApplicationMigratorInterface',
			ContractBindings::class,
		],
		'OCA\OpenRegister\Service\SearchTrailService' => ['OCA\OpenRegister\Service\SearchTrailServiceInterface', ContractBindings::class],
		'OCA\OpenRegister\Service\SemanticTypeResolver' => ['OCA\OpenRegister\Service\SemanticTypeResolverInterface', ContractBindings::class],
		'OCA\OpenRegister\Service\SequenceService' => ['OCA\OpenRegister\Service\SequenceServiceInterface', ContractBindings::class],
		'OCA\OpenRegister\Service\SharedSchemaDedupeService' => [
			'OCA\OpenRegister\Service\SharedSchemaDedupeServiceInterface',
			ContractBindings::class,
		],
		'OCA\OpenRegister\Service\TalkLinkService' => ['OCA\OpenRegister\Service\TalkLinkServiceInterface', ContractBindings::class],
		'OCA\OpenRegister\Service\TimeTrackerLinkService' => ['OCA\OpenRegister\Service\TimeTrackerLinkServiceInterface', ContractBindings::class],
		'OCA\OpenRegister\Service\TranslationProjectionService' => [
			'OCA\OpenRegister\Service\TranslationProjectionServiceInterface',
			ContractBindings::class,
		],
		'OCA\OpenRegister\Service\TranslationStatusService' => [
			'OCA\OpenRegister\Service\TranslationStatusServiceInterface',
			ContractBindings::class,
		],
		'OCA\OpenRegister\Service\XwikiLinkService' => ['OCA\OpenRegister\Service\XwikiLinkServiceInterface', ContractBindings::class],
	];

	/**
	 * One row per hidden class.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function classes(): array {
		$rows = [];
		foreach (array_keys(self::CASES) as $class) {
			$rows[$class] = [$class];
		}

		return $rows;
	}//end classes()

	/**
	 * The class implements the interface its callers type against.
	 *
	 * @param string $class The hidden class.
	 *
	 * @return void
	 */
	#[DataProvider('classes')]
	public function testClassImplementsItsInterface(string $class): void {
		$this->assertContains(self::CASES[$class][0], class_implements($class));
	}//end testClassImplementsItsInterface()

	/**
	 * The component's registrar binds the interface to the class.
	 *
	 * @param string $class The hidden class.
	 *
	 * @return void
	 */
	#[DataProvider('classes')]
	public function testInterfaceIsBound(string $class): void {
		[$interface, $registrar] = self::CASES[$class];
		$this->assertSame($class, constant($registrar . '::BINDINGS')[$interface] ?? null);
	}//end testInterfaceIsBound()

	/**
	 * No lib/ file outside the class's component names the class.
	 *
	 * @param string $class The hidden class.
	 *
	 * @return void
	 */
	#[DataProvider('classes')]
	public function testNoFileOutsideTheComponentNamesTheClass(string $class): void {
		$component = explode('\\', $class)[2];
		$naming    = [];
		foreach ($this->libFiles() as $relative => $path) {
			if (str_starts_with($relative, $component . '/') === false && $this->names(path: $path, class: $class) === true) {
				$naming[] = $relative;
			}
		}

		$this->assertSame([], $naming);
	}//end testNoFileOutsideTheComponentNamesTheClass()

	/**
	 * Every PHP file under lib/, keyed by its path relative to lib/.
	 *
	 * @return array<string, string>
	 */
	private function libFiles(): array {
		$lib   = realpath(self::LIB);
		$files = [];
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($lib)) as $file) {
			if ($file->isFile() === true && $file->getExtension() === 'php') {
				$files[substr($file->getPathname(), strlen($lib) + 1)] = $file->getPathname();
			}
		}

		ksort($files);
		return $files;
	}//end libFiles()

	/**
	 * Whether the file's code (comments excluded) names the class.
	 *
	 * A name counts when it is the fully qualified name, an import of it, a
	 * string literal holding it, or the bare short name in a file of the
	 * class's own namespace.
	 *
	 * @param string $path  The file.
	 * @param string $class The class.
	 *
	 * @return bool
	 */
	private function names(string $path, string $class): bool {
		$short = null;
		if ($this->namespaceOf(path: $path) === substr($class, 0, strrpos($class, '\\'))) {
			$short = substr($class, strrpos($class, '\\') + 1);
		}

		foreach (token_get_all((string) file_get_contents($path)) as $token) {
			if ($this->tokenNames(token: $token, target: [$class, $short]) === true) {
				return true;
			}
		}

		return false;
	}//end names()

	/**
	 * Whether one token names the class.
	 *
	 * @param array{0: int, 1: string}|string $token  The token.
	 * @param array{0: string, 1: ?string}    $target The class, and its short name when the file shares its namespace.
	 *
	 * @return bool
	 */
	private function tokenNames(array|string $token, array $target): bool {
		if (is_string($token) === true) {
			return false;
		}

		if ($token[0] === T_NAME_QUALIFIED || $token[0] === T_NAME_FULLY_QUALIFIED) {
			return ltrim($token[1], '\\') === $target[0];
		}

		if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
			return ltrim(str_replace('\\\\', '\\', trim($token[1], '\'"')), '\\') === $target[0];
		}

		return $token[0] === T_STRING && $token[1] === $target[1];
	}//end tokenNames()

	/**
	 * The namespace a file declares, or an empty string.
	 *
	 * @param string $path The file.
	 *
	 * @return string
	 */
	private function namespaceOf(string $path): string {
		$found = preg_match('/^namespace\s+([^;\s]+)\s*;/m', (string) file_get_contents($path), $match);
		if ($found !== 1) {
			return '';
		}

		return $match[1];
	}//end namespaceOf()
}//end class
