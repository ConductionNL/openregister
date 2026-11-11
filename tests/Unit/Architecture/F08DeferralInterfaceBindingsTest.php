<?php

/**
 * The deferral classes lane F08 hid stay hidden behind their interfaces.
 *
 * For each class below: it implements the interface next to it, ContractBindings
 * binds that interface to it (a missing binding is a 500 at the first request,
 * which no unit test of the callers sees), and no lib/ file outside the class's
 * own component names the class itself. Comments do not count.
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
use OCA\OpenRegister\Service\Deferral\DeferredEntryObjectResolver;
use OCA\OpenRegister\Service\Deferral\DeferredEntryObjectResolverInterface;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralInterface;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * @coversNothing
 */
final class F08DeferralInterfaceBindingsTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * Class => [its interface, its component].
	 *
	 * @var array<class-string, array{0: class-string, 1: string}>
	 */
	private const CLASSES = [
		DeferredEntryObjectResolver::class => [DeferredEntryObjectResolverInterface::class, 'Service'],
		ListenerDeferralService::class => [ListenerDeferralInterface::class, 'Service'],
	];

	/**
	 * Each class implements its interface.
	 *
	 * @return void
	 */
	public function testEveryClassImplementsItsInterface(): void {
		foreach (self::CLASSES as $class => $entry) {
			$this->assertTrue(condition: (new ReflectionClass($class))->implementsInterface($entry[0]), message: $class . ' implements ' . $entry[0]);
		}
	}//end testEveryClassImplementsItsInterface()

	/**
	 * ContractBindings binds each interface to its class.
	 *
	 * @return void
	 */
	public function testEveryInterfaceIsBound(): void {
		foreach (self::CLASSES as $class => $entry) {
			$this->assertSame(expected: $class, actual: ContractBindings::BINDINGS[$entry[0]] ?? null, message: $entry[0] . ' is bound');
		}
	}//end testEveryInterfaceIsBound()

	/**
	 * No lib/ file outside the component names the class.
	 *
	 * @return void
	 */
	public function testNoFileOutsideTheComponentNamesTheClass(): void {
		$sources = $this->sources();
		foreach (self::CLASSES as $class => $entry) {
			$named = array_filter(
				$sources,
				fn (string $code, string $path) => str_starts_with($path, $entry[1] . '/') === false
					&& $this->names(code: $code, class: $class) === true,
				ARRAY_FILTER_USE_BOTH
			);
			$this->assertSame(expected: [], actual: array_keys($named), message: $class . ' is named outside ' . $entry[1]);
		}
	}//end testNoFileOutsideTheComponentNamesTheClass()

	/**
	 * Whether comment-free code names the class by its fully qualified name.
	 *
	 * @param string $code  Code with the comments removed.
	 * @param string $class The class.
	 *
	 * @return bool
	 */
	private function names(string $code, string $class): bool {
		return preg_match('/(?<![\w\\\\])\\\\?' . preg_quote($class, '/') . '(?![\w\\\\])/', $code) === 1;
	}//end names()

	/**
	 * Every PHP file under lib/, by lib-relative path, with its comments removed.
	 *
	 * @return array<string, string>
	 */
	private function sources(): array {
		$sources = [];
		$files   = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::LIB));
		foreach ($files as $file) {
			if ($file->isFile() === true && $file->getExtension() === 'php') {
				$path = substr($file->getPathname(), strlen(self::LIB) + 1);
				$text = '';
				foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
					if (is_array($token) === false) {
						$text .= $token;
					} else if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) === false) {
						$text .= $token[1];
					}
				}

				$sources[$path] = $text;
			}
		}

		ksort($sources);
		return $sources;
	}//end sources()
}//end class
