<?php

/**
 * The interfaces lane M13 added are bound in their component's registrar and
 * implemented by the class behind them.
 *
 * A missing alias is a runtime 500 that no other unit test sees, so this test
 * pins each binding, the implementation, and that the callers outside the
 * owning component name only the interface.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Architecture
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use OCA\OpenRegister\Files\FilesRegistrar;
use OCA\OpenRegister\Files\Service\File\FileAuditHandler;
use OCA\OpenRegister\Files\Service\File\FileAuditHandlerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class M13InterfaceBindingsTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * Interface => [registrar bindings, the class behind it, outside callers that name only the interface].
	 *
	 * @return array<string, array{0: array<string, string>, 1: class-string, 2: class-string, 3: list<string>}>
	 */
	public static function bindings(): array {
		return [
			'FileAuditHandlerInterface' => [
				FilesRegistrar::BINDINGS,
				FileAuditHandlerInterface::class,
				FileAuditHandler::class,
				['Service/FileService.php', 'Service/FileServiceInterface.php', 'Service/File/FileHistoryHandlers.php'],
			],
		];
	}//end bindings()

	/**
	 * @param array<string, string> $map
	 * @param list<string>          $callers
	 */
	#[DataProvider('bindings')]
	public function testBindingIsPresent(array $map, string $contract, string $class, array $callers): void {
		$this->assertArrayHasKey($contract, $map);
		$this->assertSame($class, $map[$contract]);
	}//end testBindingIsPresent()

	/**
	 * @param array<string, string> $map
	 * @param list<string>          $callers
	 */
	#[DataProvider('bindings')]
	public function testClassImplementsTheInterface(array $map, string $contract, string $class, array $callers): void {
		$this->assertTrue(interface_exists($contract));
		$this->assertContains($contract, class_implements($class));
	}//end testClassImplementsTheInterface()

	/**
	 * @param array<string, string> $map
	 * @param list<string>          $callers
	 */
	#[DataProvider('bindings')]
	public function testOutsideCallersNameOnlyTheInterface(array $map, string $contract, string $class, array $callers): void {
		$short = substr($class, (int)strrpos($class, '\\') + 1);
		foreach ($callers as $caller) {
			$source = (string)file_get_contents(self::LIB . '/' . $caller);
			$this->assertStringContainsString($short . 'Interface', $source, $caller);
			$this->assertDoesNotMatchRegularExpression('/\b' . preg_quote($short, '/') . '\b(?!Interface)/', $source, $caller);
		}
	}//end testOutsideCallersNameOnlyTheInterface()
}//end class
