<?php

/**
 * Top-level Service classes are reached through their interfaces.
 *
 * Controllers, commands, the Files, Db, Notifications, Contacts and ContextChat
 * components, a capability, two events and a middleware named eight concrete
 * classes in lib/Service directly. Each now has an interface next to it that
 * declares only what those callers use; they type against and resolve that
 * interface, and ContractBindings aliases it to the class. A missing alias is a
 * runtime 500 that no other unit test sees, so this test pins the binding as
 * well as the implementation and the callers.
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

use OCA\OpenRegister\Service\ApplicationService;
use OCA\OpenRegister\Service\ApplicationServiceInterface;
use OCA\OpenRegister\Service\BulkActionRegistry;
use OCA\OpenRegister\Service\BulkActionRegistryInterface;
use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCA\OpenRegister\Service\DeepLinkRegistryServiceInterface;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\FileServiceInterface;
use OCA\OpenRegister\Service\LanguageService;
use OCA\OpenRegister\Service\LanguageServiceInterface;
use OCA\OpenRegister\Service\RegisterDescriptorService;
use OCA\OpenRegister\Service\RegisterDescriptorServiceInterface;
use OCA\OpenRegister\Service\RegisterResolverService;
use OCA\OpenRegister\Service\RegisterResolverServiceInterface;
use OCA\OpenRegister\Service\UrnService;
use OCA\OpenRegister\Service\UrnServiceInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @coversNothing
 */
final class D11InterfaceBindingsTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * Class => the interface outside callers type against.
	 *
	 * @var array<class-string, class-string>
	 */
	private const CLASSES = [
		ApplicationService::class => ApplicationServiceInterface::class,
		BulkActionRegistry::class => BulkActionRegistryInterface::class,
		DeepLinkRegistryService::class => DeepLinkRegistryServiceInterface::class,
		FileService::class => FileServiceInterface::class,
		LanguageService::class => LanguageServiceInterface::class,
		RegisterDescriptorService::class => RegisterDescriptorServiceInterface::class,
		RegisterResolverService::class => RegisterResolverServiceInterface::class,
		UrnService::class => UrnServiceInterface::class,
	];

	/**
	 * Outside files still allowed to name the class, and why.
	 *
	 * Application::boot() resolves the deep-link registry inside a 43-line
	 * method this refactor does not reshape. ObjectsController resolves
	 * FileService in downloadFiles(), a 50-line method, and in the bulk-download
	 * audit helper; audit code is out of scope. FileReadScope decides which
	 * file-search hits the caller may read: it is access code, also out of scope.
	 *
	 * @var array<class-string, list<string>>
	 */
	private const KEPT_CALLERS = [
		DeepLinkRegistryService::class => ['AppInfo/Application.php'],
		FileService::class => ['Controller/ObjectsController.php', 'Files/Service/File/FileReadScope.php'],
	];

	/**
	 * The class and its interface, one case per class.
	 *
	 * @return array<string, array{class-string, class-string}>
	 */
	public static function classes(): array {
		$cases = [];
		foreach (self::CLASSES as $class => $contract) {
			$cases[$class] = [$class, $contract];
		}

		return $cases;
	}//end classes()

	/**
	 * The class implements its interface.
	 *
	 * @param class-string $class    The class.
	 * @param class-string $contract Its interface.
	 *
	 * @return void
	 */
	#[DataProvider('classes')]
	public function testTheClassImplementsItsInterface(string $class, string $contract): void {
		$this->assertTrue(interface_exists($contract), $contract . ' exists');
		$this->assertContains($contract, class_implements($class), $class . ' implements ' . $contract);
	}//end testTheClassImplementsItsInterface()

	/**
	 * The interface is bound to the class in ContractBindings.
	 *
	 * @param class-string $class    The class.
	 * @param class-string $contract Its interface.
	 *
	 * @return void
	 */
	#[DataProvider('classes')]
	public function testTheInterfaceIsBound(string $class, string $contract): void {
		$this->assertArrayHasKey($contract, ContractBindings::BINDINGS);
		$this->assertSame($class, ContractBindings::BINDINGS[$contract]);
	}//end testTheInterfaceIsBound()

	/**
	 * No lib file outside the class's component names the class.
	 *
	 * @param class-string $class    The class.
	 * @param class-string $contract Its interface.
	 *
	 * @return void
	 */
	#[DataProvider('classes')]
	public function testNoOutsideFileNamesTheClass(string $class, string $contract): void {
		$naming = [];
		foreach ($this->outsideFiles(class: $class) as $relative => $path) {
			if ($this->namesClass(source: (string) file_get_contents($path), class: $class) === true) {
				$naming[] = $relative;
			}
		}

		$this->assertSame(self::KEPT_CALLERS[$class] ?? [], $naming, 'Type against ' . $contract . ' instead.');
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
