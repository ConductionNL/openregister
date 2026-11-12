<?php

/**
 * Authorization-declaration checks lane C06 hid are reached through their interfaces.
 *
 * The register and schema save paths in lib/Db used to construct the
 * permission catalogue, the deny validator and the hierarchy validator with
 * `new`, read the hierarchy and reveal-audit keys off concrete classes, and
 * the facet handler resolved the concrete PropertyRbacHandler from the
 * container. They now take the checks from AccessDeclarationValidators (typed
 * as interfaces), read constants off interfaces, and resolve
 * PropertyRbacHandlerInterface, which ContractBindings aliases to the same
 * shared PropertyRbacHandler. This test pins the implementations, the binding,
 * that the factory builds exactly what the callers used to build, the key
 * values, and that no lib file outside lib/Service names these classes.
 *
 * Kept apart from C06InterfaceBindingsTest so the two lane-C06 pull requests
 * do not edit one file.
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
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\PropertyRbacHandlerInterface;
use OCA\OpenRegister\Service\Rbac\AccessDeclarationValidators;
use OCA\OpenRegister\Service\Rbac\AuthorizationDenyValidator;
use OCA\OpenRegister\Service\Rbac\AuthorizationDenyValidatorInterface;
use OCA\OpenRegister\Service\Rbac\HierarchyAnnotationValidator;
use OCA\OpenRegister\Service\Rbac\HierarchyAnnotationValidatorInterface;
use OCA\OpenRegister\Service\Rbac\HierarchyGrantExpander;
use OCA\OpenRegister\Service\Rbac\PermissionCatalogue;
use OCA\OpenRegister\Service\Rbac\PermissionCatalogueInterface;
use OCA\OpenRegister\Service\Rbac\RevealCollector;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionProperty;

/**
 * @coversNothing
 */
final class C06AccessInterfaceBindingsTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * Classes no lib file outside lib/Service may name.
	 *
	 * @var list<class-string>
	 */
	private const HIDDEN = [
		AuthorizationDenyValidator::class,
		HierarchyAnnotationValidator::class,
		HierarchyGrantExpander::class,
		PermissionCatalogue::class,
		PropertyRbacHandler::class,
		RevealCollector::class,
	];

	/**
	 * One case per hidden class.
	 *
	 * @return array<string, array{class-string}>
	 */
	public static function hidden(): array {
		$cases = [];
		foreach (self::HIDDEN as $class) {
			$cases[$class] = [$class];
		}

		return $cases;
	}//end hidden()

	/**
	 * Each class implements its interface.
	 *
	 * @return void
	 */
	public function testTheClassesImplementTheirInterfaces(): void {
		$pairs = [
			AuthorizationDenyValidator::class   => AuthorizationDenyValidatorInterface::class,
			HierarchyAnnotationValidator::class => HierarchyAnnotationValidatorInterface::class,
			PermissionCatalogue::class          => PermissionCatalogueInterface::class,
			PropertyRbacHandler::class          => PropertyRbacHandlerInterface::class,
		];
		foreach ($pairs as $class => $contract) {
			$this->assertContains(needle: $contract, haystack: class_implements($class), message: $class . ' implements ' . $contract);
		}
	}//end testTheClassesImplementTheirInterfaces()

	/**
	 * The facet handler's interface resolves to the same shared PropertyRbacHandler.
	 *
	 * @return void
	 */
	public function testThePropertyRbacInterfaceIsBound(): void {
		$this->assertSame(
			expected: PropertyRbacHandler::class,
			actual: ContractBindings::BINDINGS[PropertyRbacHandlerInterface::class] ?? null
		);
	}//end testThePropertyRbacInterfaceIsBound()

	/**
	 * The factory builds a fresh instance of exactly what the callers used to build.
	 *
	 * @return void
	 */
	public function testTheFactoryBuildsWhatTheCallersBuilt(): void {
		$dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$catalogue  = AccessDeclarationValidators::permissionCatalogue(eventDispatcher: $dispatcher);
		$this->assertSame(expected: PermissionCatalogue::class, actual: get_class($catalogue));
		$this->assertSame(
			expected: $dispatcher,
			actual: (new ReflectionProperty(PermissionCatalogue::class, 'eventDispatcher'))->getValue($catalogue)
		);
		$this->assertNotSame(expected: $catalogue, actual: AccessDeclarationValidators::permissionCatalogue(eventDispatcher: $dispatcher));

		$deny = AccessDeclarationValidators::denyValidator();
		$this->assertSame(expected: AuthorizationDenyValidator::class, actual: get_class($deny));
		$this->assertNotSame(expected: $deny, actual: AccessDeclarationValidators::denyValidator());

		$hierarchy = AccessDeclarationValidators::hierarchy();
		$this->assertSame(expected: HierarchyAnnotationValidator::class, actual: get_class($hierarchy));
		$this->assertNotSame(expected: $hierarchy, actual: AccessDeclarationValidators::hierarchy());
	}//end testTheFactoryBuildsWhatTheCallersBuilt()

	/**
	 * The re-exported keys are the owning classes' own.
	 *
	 * @return void
	 */
	public function testTheKeysAreUnchanged(): void {
		$this->assertSame(expected: HierarchyGrantExpander::ANNOTATION, actual: AccessDeclarationValidators::HIERARCHY_ANNOTATION);
		$this->assertSame(expected: RevealCollector::AUDIT_KEY, actual: AccessDeclarationValidators::REVEAL_AUDIT_KEY);
		$this->assertSame(expected: PermissionCatalogueInterface::CANONICAL, actual: PermissionCatalogue::CANONICAL);
		$this->assertSame(expected: PermissionCatalogueInterface::CONTROL_KEYS, actual: PermissionCatalogue::CONTROL_KEYS);
	}//end testTheKeysAreUnchanged()

	/**
	 * No lib file outside lib/Service names the class.
	 *
	 * @param class-string $class The class.
	 *
	 * @return void
	 */
	#[DataProvider('hidden')]
	public function testNoOutsideFileNamesTheClass(string $class): void {
		$naming = [];
		foreach ($this->outsideFiles() as $relative => $path) {
			if ($this->namesClass(source: (string) file_get_contents($path), class: $class) === true) {
				$naming[] = $relative;
			}
		}

		$this->assertSame(expected: [], actual: $naming, message: 'Type against the interface or AccessDeclarationValidators instead.');
	}//end testNoOutsideFileNamesTheClass()

	/**
	 * Every lib PHP file outside lib/Service, keyed by its path under lib/.
	 *
	 * @return array<string, string>
	 */
	private function outsideFiles(): array {
		$lib   = (string) realpath(self::LIB);
		$files = [];
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($lib)) as $file) {
			$relative = substr($file->getPathname(), strlen($lib) + 1);
			if ($file->getExtension() === 'php' && str_starts_with($relative, 'Service/') === false) {
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

			$qualified = in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) === true && ltrim($token[1], '\\') === $class;
			if ($qualified === true || ($imported === true && $token[0] === T_STRING && $token[1] === $short)) {
				return true;
			}
		}

		return false;
	}//end namesClass()
}//end class
