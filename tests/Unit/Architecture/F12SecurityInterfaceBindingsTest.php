<?php

/**
 * SecurityService is reached through narrow interfaces outside lib/Service.
 *
 * The user API types against LoginSecurityInterface (and its responses against
 * SecurityHeadersInterface), the security settings controller against
 * RateLimitResetInterface and the inbound rate-limit middleware against
 * InboundAuthRateLimitInterface. Outbound URL fetchers call SafeFetchUrl::assert(),
 * which SecurityService::assertSafeFetchUrl() delegates to. Each alias resolves to
 * the service's own shared instance; a missing alias is a runtime 500 no other
 * unit test sees, so the binding is pinned.
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
use OCA\OpenRegister\Service\InboundAuthRateLimitInterface;
use OCA\OpenRegister\Service\LoginSecurityInterface;
use OCA\OpenRegister\Service\RateLimitResetInterface;
use OCA\OpenRegister\Service\SecurityHeadersInterface;
use OCA\OpenRegister\Service\SecurityService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @coversNothing
 */
final class F12SecurityInterfaceBindingsTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * Interface => [the class it is bound to, the registrar that binds it].
	 *
	 * @var array<class-string, array{class-string, class-string}>
	 */
	private const BINDINGS = [
		InboundAuthRateLimitInterface::class => [SecurityService::class, ContractBindings::class],
		LoginSecurityInterface::class => [SecurityService::class, ContractBindings::class],
		RateLimitResetInterface::class => [SecurityService::class, ContractBindings::class],
		SecurityHeadersInterface::class => [SecurityService::class, ContractBindings::class],
	];

	/**
	 * Classes no lib file outside their own component names any more.
	 *
	 * @var list<class-string>
	 */
	private const HIDDEN = [
		SecurityService::class,
	];

	/**
	 * One case per interface.
	 *
	 * @return array<string, array{class-string}>
	 */
	public static function interfaces(): array {
		$cases = [];
		foreach (array_keys(self::BINDINGS) as $contract) {
			$cases[$contract] = [$contract];
		}

		return $cases;
	}//end interfaces()

	/**
	 * One case per hidden class.
	 *
	 * @return array<string, array{class-string}>
	 */
	public static function hiddenClasses(): array {
		$cases = [];
		foreach (self::HIDDEN as $class) {
			$cases[$class] = [$class];
		}

		return $cases;
	}//end hiddenClasses()

	/**
	 * The class implements the interface, and the registrar is in the class's own component.
	 *
	 * @param class-string $contract The interface.
	 *
	 * @return void
	 */
	#[DataProvider('interfaces')]
	public function testTheClassImplementsTheInterface(string $contract): void {
		[$class, $registrar] = self::BINDINGS[$contract];
		$this->assertTrue(interface_exists($contract), $contract . ' exists');
		$this->assertContains($contract, class_implements($class), $class . ' implements ' . $contract);
		$this->assertSame(explode('\\', $class)[2], explode('\\', $registrar)[2], 'Bound in its own component');
	}//end testTheClassImplementsTheInterface()

	/**
	 * The registrar aliases the interface to the class.
	 *
	 * @param class-string $contract The interface.
	 *
	 * @return void
	 */
	#[DataProvider('interfaces')]
	public function testTheInterfaceIsBound(string $contract): void {
		[$class, $registrar] = self::BINDINGS[$contract];
		$bindings = constant($registrar . '::BINDINGS');
		$this->assertArrayHasKey($contract, $bindings);
		$this->assertSame($class, $bindings[$contract]);
	}//end testTheInterfaceIsBound()

	/**
	 * No lib file outside the class's component names the class.
	 *
	 * @param class-string $class The class.
	 *
	 * @return void
	 */
	#[DataProvider('hiddenClasses')]
	public function testNoOutsideFileNamesTheClass(string $class): void {
		$naming = [];
		foreach ($this->outsideFiles(class: $class) as $relative => $path) {
			if ($this->namesClass(source: (string) file_get_contents($path), class: $class) === true) {
				$naming[] = $relative;
			}
		}

		$this->assertSame([], $naming, $class . ' is named outside its component; type against its interface instead.');
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

			$qualified = in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) === true
				&& ltrim($token[1], '\\') === $class;
			if ($qualified === true || ($imported === true && $token[0] === T_STRING && $token[1] === $short)) {
				return true;
			}
		}

		return false;
	}//end namesClass()
}//end class
