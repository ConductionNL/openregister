<?php

/**
 * The C09 classes are reached through their interfaces from outside Service.
 *
 * Each class listed here was named directly by a repair step, an occ command
 * or the Search registrar/report writer. It now has an interface next to it;
 * those callers type against, resolve or read constants from that interface,
 * and ContractBindings aliases it to the class, so the container hands out the
 * same shared instance as before. A missing alias is a runtime 500 that no
 * other unit test sees, so this test pins the binding as well as the
 * implementation and the callers.
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
use OCA\OpenRegister\Service\Credential\DoriathCredentialKeys;
use OCA\OpenRegister\Service\Credential\DoriathCredentialStore;
use OCA\OpenRegister\Service\Export\Odt\OdtWriter;
use OCA\OpenRegister\Service\Export\Odt\OdtWriterInterface;
use OCA\OpenRegister\Service\Flow\FlowRunSubjects;
use OCA\OpenRegister\Service\Flow\FlowRunSubjectsInterface;
use OCA\OpenRegister\Service\Operations\MovedJobState;
use OCA\OpenRegister\Service\Operations\MovedJobStateInterface;
use OCA\OpenRegister\Service\Vectorization\Strategies\FileVectorizationStrategy;
use OCA\OpenRegister\Service\Vectorization\Strategies\FileVectorizationStrategyInterface;
use OCA\OpenRegister\Service\Vectorization\Strategies\ObjectVectorizationStrategy;
use OCA\OpenRegister\Service\Vectorization\Strategies\ObjectVectorizationStrategyInterface;
use OCA\OpenRegister\Search\Service\Vectorization\Strategies\VectorizationStrategyInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @coversNothing
 */
final class C09InterfaceBindingsTest extends TestCase {

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
		DoriathCredentialStore::class => [DoriathCredentialKeys::class, ContractBindings::class],
		FileVectorizationStrategy::class => [FileVectorizationStrategyInterface::class, ContractBindings::class],
		FlowRunSubjects::class => [FlowRunSubjectsInterface::class, ContractBindings::class],
		MovedJobState::class => [MovedJobStateInterface::class, ContractBindings::class],
		ObjectVectorizationStrategy::class => [ObjectVectorizationStrategyInterface::class, ContractBindings::class],
		OdtWriter::class => [OdtWriterInterface::class, ContractBindings::class],
	];

	/**
	 * The constants that moved to an interface keep their values on the class.
	 *
	 * @return void
	 */
	public function testMovedConstantsKeepTheirValues(): void {
		$this->assertSame(expected: 'doriath_application_id', actual: DoriathCredentialStore::APP_CONFIG_APPLICATION_ID);
		$this->assertSame(expected: 'doriath_public_key_pem', actual: DoriathCredentialStore::APP_CONFIG_PUBLIC_KEY_PEM);
		$this->assertSame(expected: 'openregister/doriath/private-key', actual: DoriathCredentialStore::PRIVATE_KEY_ID);
		$this->assertSame(expected: 'trigger', actual: FlowRunSubjects::ROLE_TRIGGER);
	}//end testMovedConstantsKeepTheirValues()

	/**
	 * The strategy interfaces are still vectorization strategies.
	 *
	 * @return void
	 */
	public function testStrategyInterfacesAreStrategies(): void {
		$this->assertContains(needle: VectorizationStrategyInterface::class, haystack: class_implements(FileVectorizationStrategyInterface::class));
		$this->assertContains(needle: VectorizationStrategyInterface::class, haystack: class_implements(ObjectVectorizationStrategyInterface::class));
	}//end testStrategyInterfacesAreStrategies()

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

		$this->assertSame(expected: [], actual: $naming, message: 'Type against ' . self::CLASSES[$class][0] . ' instead.');
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
