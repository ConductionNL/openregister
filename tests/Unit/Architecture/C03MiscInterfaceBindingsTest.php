<?php

/**
 * C03 geo, vocabulary, register, schema, rule-run and well-known classes:
 * the controllers reach them through their interfaces.
 *
 * Each class listed here was named directly by a controller (lib/Controller, files P to Z).
 * It now has an interface next to it; outside callers type against and resolve
 * that interface, and the registrar of the class's own component aliases it to
 * the class. A missing alias is a runtime 500 that no other unit test sees, so
 * this test pins the binding as well as the implementation and the callers.
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

use OCA\OpenRegister\Db\DbRegistrar;
use OCA\OpenRegister\Db\RuleRunMapper;
use OCA\OpenRegister\Db\RuleRunMapperInterface;
use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Service\Dbal\DbalConnectionFactory;
use OCA\OpenRegister\Service\Dbal\DbalConnectionFactoryInterface;
use OCA\OpenRegister\Service\Geo\PdokGeocoder;
use OCA\OpenRegister\Service\Geo\PdokGeocoderInterface;
use OCA\OpenRegister\Service\Registers\RegisterCacheHandler;
use OCA\OpenRegister\Service\Registers\RegisterCacheHandlerInterface;
use OCA\OpenRegister\Service\Schema\SchemaMigrationService;
use OCA\OpenRegister\Service\Schema\SchemaMigrationServiceInterface;
use OCA\OpenRegister\Service\Schema\SchemaObjectReader;
use OCA\OpenRegister\Service\Schema\SchemaObjectReaderInterface;
use OCA\OpenRegister\Service\Serializer\RegisterSerializer;
use OCA\OpenRegister\Service\Serializer\RegisterSerializerInterface;
use OCA\OpenRegister\Service\Vocabulary\CodedOptionsBuilder;
use OCA\OpenRegister\Service\Vocabulary\CodedOptionsBuilderInterface;
use OCA\OpenRegister\Service\Vocabulary\VocabularyDeclarationResolver;
use OCA\OpenRegister\Service\Vocabulary\VocabularyDeclarationResolverInterface;
use OCA\OpenRegister\Service\WellKnown\SecurityTxtBuilder;
use OCA\OpenRegister\Service\WellKnown\SecurityTxtBuilderInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @coversNothing
 */
final class C03MiscInterfaceBindingsTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * Class => the interface outside callers type against, and the registrar that binds it.
	 *
	 * @var array<class-string, array{class-string, class-string}>
	 */
	private const CLASSES = [
		CodedOptionsBuilder::class => [CodedOptionsBuilderInterface::class, ContractBindings::class],
		DbalConnectionFactory::class => [DbalConnectionFactoryInterface::class, ContractBindings::class],
		PdokGeocoder::class => [PdokGeocoderInterface::class, ContractBindings::class],
		RegisterCacheHandler::class => [RegisterCacheHandlerInterface::class, ContractBindings::class],
		RegisterSerializer::class => [RegisterSerializerInterface::class, ContractBindings::class],
		RuleRunMapper::class => [RuleRunMapperInterface::class, DbRegistrar::class],
		SchemaMigrationService::class => [SchemaMigrationServiceInterface::class, ContractBindings::class],
		SchemaObjectReader::class => [SchemaObjectReaderInterface::class, ContractBindings::class],
		SecurityTxtBuilder::class => [SecurityTxtBuilderInterface::class, ContractBindings::class],
		VocabularyDeclarationResolver::class => [VocabularyDeclarationResolverInterface::class, ContractBindings::class],
	];

	/**
	 * Outside files still allowed to name the class, and why.
	 *
	 * None: main already retyped the background jobs and Service callers of
	 * RuleRunMapper and SchemaMigrationService against their interfaces.
	 *
	 * @var array<class-string, list<string>>
	 */
	private const KEPT_CALLERS = [];

	/**
	 * One case per class.
	 *
	 * @return array<string, array{class-string}>
	 */
	public static function classes(): array {
		$cases = [];
		foreach (array_keys(self::CLASSES) as $class) {
			$cases[$class] = [$class];
		}

		return $cases;
	}//end classes()

	/**
	 * The class implements its interface, and its registrar is in its own component.
	 *
	 * @param class-string $class The class.
	 *
	 * @return void
	 */
	#[DataProvider('classes')]
	public function testTheClassImplementsItsInterface(string $class): void {
		[$contract, $registrar] = self::CLASSES[$class];
		$this->assertTrue(condition: interface_exists($contract), message: $contract . ' exists');
		$this->assertContains(needle: $contract, haystack: class_implements($class), message: $class . ' implements ' . $contract);
		$this->assertSame(expected: explode('\\', $class)[2], actual: explode('\\', $registrar)[2], message: 'Bound in its own component');
	}//end testTheClassImplementsItsInterface()

	/**
	 * The interface is bound to the class in the registrar of the class's component.
	 *
	 * @param class-string $class The class.
	 *
	 * @return void
	 */
	#[DataProvider('classes')]
	public function testTheInterfaceIsBound(string $class): void {
		[$contract, $registrar] = self::CLASSES[$class];
		$bindings = constant($registrar . '::BINDINGS');
		$this->assertArrayHasKey(key: $contract, array: $bindings);
		$this->assertSame(expected: $class, actual: $bindings[$contract]);
	}//end testTheInterfaceIsBound()

	/**
	 * No lib file outside the class's component names the class.
	 *
	 * @param class-string $class The class.
	 *
	 * @return void
	 */
	#[DataProvider('classes')]
	public function testNoOutsideFileNamesTheClass(string $class): void {
		$naming = [];
		foreach ($this->outsideFiles(class: $class) as $relative => $path) {
			if ($this->namesClass(source: (string) file_get_contents($path), class: $class) === true) {
				$naming[] = $relative;
			}
		}

		$this->assertSame(expected: self::KEPT_CALLERS[$class] ?? [], actual: $naming, message: 'Type against ' . self::CLASSES[$class][0] . ' instead.');
	}//end testNoOutsideFileNamesTheClass()

	/**
	 * Every lib PHP file outside the component of the class, keyed by its path under lib/.
	 *
	 * @param class-string $class The class.
	 *
	 * @return array<string, string>
	 */
	private function outsideFiles(string $class): array {
		$component = explode('\\', $class)[2];
		$lib       = (string) realpath(self::LIB);
		$files     = [];
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($lib)) as $file) {
			$relative = substr($file->getPathname(), strlen($lib) + 1);
			if ($file->getExtension() === 'php' && str_starts_with($relative, $component . '/') === false) {
				$files[$relative] = $file->getPathname();
			}
		}

		ksort($files);
		return $files;
	}//end outsideFiles()

	/**
	 * Whether code (not comments) in this source names the class.
	 *
	 * @param string       $source The PHP source.
	 * @param class-string $class  The class.
	 *
	 * @return bool
	 */
	private function namesClass(string $source, string $class): bool {
		$short    = substr($class, (int) strrpos($class, '\\') + 1);
		$imported = str_contains($source, 'use ' . $class . ';');
		foreach (token_get_all($source) as $token) {
			if (is_array($token) === false) {
				continue;
			}

			if ($this->tokenNames(token: $token, class: $class) === true
				|| ($imported === true && $token[0] === T_STRING && $token[1] === $short)
			) {
				return true;
			}
		}

		return false;
	}//end namesClass()

	/**
	 * Whether a qualified-name token is the class.
	 *
	 * @param array{int, string, int} $token The token.
	 * @param class-string            $class The class.
	 *
	 * @return bool
	 */
	private function tokenNames(array $token, string $class): bool {
		if (in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) === false) {
			return false;
		}

		return ltrim($token[1], '\\') === $class;
	}//end tokenNames()
}//end class
