<?php

/**
 * The C01 flow classes are reached through their interfaces.
 *
 * Each class listed here was named directly by a lib file in another component.
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
use OCA\OpenRegister\Db\FlowStateMapper;
use OCA\OpenRegister\Db\FlowStateMapperInterface;
use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Service\Flow\Bpmn\FlowBpmnInterchange;
use OCA\OpenRegister\Service\Flow\Bpmn\FlowBpmnInterchangeInterface;
use OCA\OpenRegister\Service\Flow\EventCatalogService;
use OCA\OpenRegister\Service\Flow\EventCatalogServiceInterface;
use OCA\OpenRegister\Service\Flow\FlowRunAuthorization;
use OCA\OpenRegister\Service\Flow\FlowRunAuthorizationInterface;
use OCA\OpenRegister\Service\Flow\FlowRunnableGuard;
use OCA\OpenRegister\Service\Flow\FlowRunnableGuardInterface;
use OCA\OpenRegister\Service\Flow\FlowRunVersionPin;
use OCA\OpenRegister\Service\Flow\FlowRunVersionPinInterface;
use OCA\OpenRegister\Service\Flow\Principal\PrincipalResolverRegistry;
use OCA\OpenRegister\Service\Flow\Principal\PrincipalResolverRegistryInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @coversNothing
 */
final class C01FlowInterfaceBindingsTest extends TestCase {

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
		EventCatalogService::class => [EventCatalogServiceInterface::class, ContractBindings::class],
		FlowBpmnInterchange::class => [FlowBpmnInterchangeInterface::class, ContractBindings::class],
		FlowRunAuthorization::class => [FlowRunAuthorizationInterface::class, ContractBindings::class],
		FlowRunVersionPin::class => [FlowRunVersionPinInterface::class, ContractBindings::class],
		FlowRunnableGuard::class => [FlowRunnableGuardInterface::class, ContractBindings::class],
		FlowStateMapper::class => [FlowStateMapperInterface::class, DbRegistrar::class],
		PrincipalResolverRegistry::class => [PrincipalResolverRegistryInterface::class, ContractBindings::class],
	];

	/**
	 * Outside files still allowed to name the class, and why.
	 *
	 * This pass moved the A–F controllers and their Support bundles. The callers
	 * below are in other components (Service, Repair) and are left for the passes
	 * that own them; each can switch to the interface without touching the class.
	 *
	 * @var array<class-string, list<string>>
	 */
	private const KEPT_CALLERS = [
		FlowStateMapper::class => ['Service/Flow/FlowDeletionSweep.php', 'Service/Flow/FlowRunService.php', 'Service/Flow/FlowStateBinding.php'],
		PrincipalResolverRegistry::class => ['Repair/TypePerformerReferences.php'],
	];

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
	 * The BPMN exporter, importer, vocabulary and validator are reached only through the interchange.
	 *
	 * @return void
	 */
	public function testTheBpmnInternalsAreNotNamedOutsideService(): void {
		$naming = [];
		foreach (['FlowBpmnExporter', 'FlowBpmnImporter', 'BpmnVocabulary', 'BpmnSchemaValidator'] as $short) {
			$class = 'OCA\\OpenRegister\\Service\\Flow\\Bpmn\\' . $short;
			foreach ($this->outsideFiles(class: $class) as $relative => $path) {
				if ($this->namesClass(source: (string) file_get_contents($path), class: $class) === true) {
					$naming[] = $relative . ' → ' . $short;
				}
			}
		}

		$this->assertSame(expected: [], actual: $naming, message: 'Use FlowBpmnInterchangeInterface instead.');
	}//end testTheBpmnInternalsAreNotNamedOutsideService()

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
