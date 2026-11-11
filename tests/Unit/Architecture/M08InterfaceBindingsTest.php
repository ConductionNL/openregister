<?php

/**
 * The interfaces lane M08 added at the Search component's boundary.
 *
 * Pins that each class implements the interface its callers rely on, and that
 * the callers outside lib/Search name only the interface, not the Search classes.
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

use OCA\OpenRegister\Files\BackgroundJob\ChunkAsVectorSource;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\ChunkVectorSourceInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class M08InterfaceBindingsTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * Each new interface and the class that implements it.
	 *
	 * @return array<string, array{0: class-string, 1: class-string}>
	 */
	public static function implementations(): array {
		return [
			'chunk vector source' => [ChunkVectorSourceInterface::class, ChunkAsVectorSource::class],
		];
	}//end implementations()

	/**
	 * The class implements the interface.
	 *
	 * @param class-string $contract The interface.
	 * @param class-string $service  The implementing class.
	 *
	 * @return void
	 */
	#[DataProvider('implementations')]
	public function testClassImplementsInterface(string $contract, string $service): void {
		$this->assertTrue(interface_exists($contract), $contract . ' must be an interface');
		$this->assertTrue(is_subclass_of($service, $contract), $service . ' must implement ' . $contract);
	}//end testClassImplementsInterface()

	/**
	 * Outside lib/Search, the callers name the interface, not the Search classes.
	 *
	 * @return array<string, array{0: string, 1: list<string>}>
	 */
	public static function callers(): array {
		return [
			'chunk vectorization batch' => [
				'Files/BackgroundJob/ChunkVectorizationBatch.php',
				['ChunkVectorSource', 'ChunkVectorFactory'],
			],
		];
	}//end callers()

	/**
	 * The caller does not name a hidden class.
	 *
	 * @param string       $file    The caller, relative to lib/.
	 * @param list<string> $classes The Search classes it must not name.
	 *
	 * @return void
	 */
	#[DataProvider('callers')]
	public function testCallerNamesNoHiddenClass(string $file, array $classes): void {
		$source = (string) file_get_contents(self::LIB . '/' . $file);
		foreach ($classes as $class) {
			$this->assertDoesNotMatchRegularExpression('/\b' . $class . '\b/', $source, $file . ' names ' . $class);
		}
	}//end testCallerNamesNoHiddenClass()
}//end class
