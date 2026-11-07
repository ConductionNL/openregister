<?php

/**
 * Lane D3: Service classes are reached from other components through an interface.
 *
 * For each class below: the class implements the interface next to it, the
 * interface is bound to the class in its registrar's BINDINGS (a missing
 * binding is a runtime 500 that no other unit test catches), and no lib/ file
 * outside the class's own component names the concrete class in code.
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

use OCA\OpenRegister\Service\ActivityFilterService;
use OCA\OpenRegister\Service\ActivityFilterServiceInterface;
use OCA\OpenRegister\Service\AnalyticsLinkService;
use OCA\OpenRegister\Service\AnalyticsLinkServiceInterface;
use OCA\OpenRegister\Service\AppRegisterImportRunner;
use OCA\OpenRegister\Service\AppRegisterImportRunnerInterface;
use OCA\OpenRegister\Service\BookmarkLinkService;
use OCA\OpenRegister\Service\BookmarkLinkServiceInterface;
use OCA\OpenRegister\Service\CalendarLinkService;
use OCA\OpenRegister\Service\CalendarLinkServiceInterface;
use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Service\CospendLinkService;
use OCA\OpenRegister\Service\CospendLinkServiceInterface;
use OCA\OpenRegister\Service\DeckCardService;
use OCA\OpenRegister\Service\DeckCardServiceInterface;
use OCA\OpenRegister\Service\EmailLinkService;
use OCA\OpenRegister\Service\EmailLinkServiceInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class D3InterfaceBindingsTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * Class => [interface, registrar holding the binding, component directory under lib/].
	 *
	 * @var array<class-string, array{0: class-string, 1: class-string, 2: string}>
	 */
	private const CASES = [
		ActivityFilterService::class => [ActivityFilterServiceInterface::class, ContractBindings::class, 'Service'],
		AnalyticsLinkService::class => [AnalyticsLinkServiceInterface::class, ContractBindings::class, 'Service'],
		AppRegisterImportRunner::class => [AppRegisterImportRunnerInterface::class, ContractBindings::class, 'Service'],
		BookmarkLinkService::class => [BookmarkLinkServiceInterface::class, ContractBindings::class, 'Service'],
		CalendarLinkService::class => [CalendarLinkServiceInterface::class, ContractBindings::class, 'Service'],
		CospendLinkService::class => [CospendLinkServiceInterface::class, ContractBindings::class, 'Service'],
		DeckCardService::class => [DeckCardServiceInterface::class, ContractBindings::class, 'Service'],
		EmailLinkService::class => [EmailLinkServiceInterface::class, ContractBindings::class, 'Service'],
	];

	/**
	 * One data set per class.
	 *
	 * @return array<string, array{0: class-string, 1: array{0: class-string, 1: class-string, 2: string}}>
	 */
	public static function cases(): array {
		$sets = [];
		foreach (self::CASES as $class => $case) {
			$sets[$class] = [$class, $case];
		}

		return $sets;
	}//end cases()

	/**
	 * The class implements its interface.
	 *
	 * @param class-string                                          $class The concrete class
	 * @param array{0: class-string, 1: class-string, 2: string} $case  Interface, registrar, component
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testClassImplementsItsInterface(string $class, array $case): void {
		$this->assertTrue(interface_exists($case[0]), $case[0] . ' is not an interface');
		$this->assertContains($case[0], class_implements($class), $class . ' does not implement ' . $case[0]);
	}//end testClassImplementsItsInterface()

	/**
	 * The registrar binds the interface to the class.
	 *
	 * @param class-string                                          $class The concrete class
	 * @param array{0: class-string, 1: class-string, 2: string} $case  Interface, registrar, component
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testRegistrarBindsTheInterface(string $class, array $case): void {
		$bindings = constant($case[1] . '::BINDINGS');
		$this->assertArrayHasKey($case[0], $bindings, $case[1] . ' does not bind ' . $case[0]);
		$this->assertSame($class, $bindings[$case[0]]);
	}//end testRegistrarBindsTheInterface()

	/**
	 * No lib/ file outside the class's component names the concrete class.
	 *
	 * @param class-string                                          $class The concrete class
	 * @param array{0: class-string, 1: class-string, 2: string} $case  Interface, registrar, component
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testNoOtherComponentNamesTheClass(string $class, array $case): void {
		$offenders = [];
		foreach ($this->filesOutside(component: $case[2]) as $file) {
			if ($this->namesClass(file: $file, class: $class) === true) {
				$offenders[] = substr($file, strlen(self::LIB) + 1);
			}
		}

		$this->assertSame([], $offenders, $class . ' is named outside lib/' . $case[2]);
	}//end testNoOtherComponentNamesTheClass()

	/**
	 * Every PHP file under lib/ that is not in the given component.
	 *
	 * @param string $component First-level directory under lib/
	 *
	 * @return list<string>
	 */
	private function filesOutside(string $component): array {
		$files    = [];
		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::LIB, \FilesystemIterator::SKIP_DOTS));
		foreach ($iterator as $info) {
			$path = $info->getPathname();
			if (str_ends_with($path, '.php') === true && str_starts_with($path, self::LIB . '/' . $component . '/') === false) {
				$files[] = $path;
			}
		}

		return $files;
	}//end filesOutside()

	/**
	 * Whether the file's code (comments stripped) names the class: fully
	 * qualified, imported, or by bare name inside the class's namespace.
	 *
	 * @param string       $file  Absolute path
	 * @param class-string $class The concrete class
	 *
	 * @return bool
	 */
	private function namesClass(string $file, string $class): bool {
		$code      = $this->codeOf(file: $file);
		$namespace = substr($class, 0, (int) strrpos($class, '\\'));
		$short     = substr($class, strlen($namespace) + 1);
		if (preg_match('/(?<![\w\\\\])\\\\?' . preg_quote($class, '/') . '(?![\w\\\\])/', $code) === 1) {
			return true;
		}

		$inNamespace = preg_match('/\bnamespace\s+' . preg_quote($namespace, '/') . '\s*;/', $code) === 1;
		return $inNamespace === true && preg_match('/(?<![\w\\\\$])' . $short . '(?![\w\\\\])/', $code) === 1;
	}//end namesClass()

	/**
	 * The file's source with comments and docblocks removed.
	 *
	 * @param string $file Absolute path
	 *
	 * @return string
	 */
	private function codeOf(string $file): string {
		$code = '';
		foreach (token_get_all((string) file_get_contents($file)) as $token) {
			if (is_array($token) === false) {
				$code .= $token;
				continue;
			}

			if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) === false) {
				$code .= $token[1];
			}
		}

		return $code;
	}//end codeOf()
}//end class
