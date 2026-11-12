<?php

/**
 * The interfaces lane H11 added for the Search component are bound in SearchWiring.
 *
 * A missing alias is a runtime 500 that no other unit test sees, so this test
 * pins each binding, that the bound class implements the interface, and that
 * the callers outside lib/Search name only the interface or handle.
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

use OCA\OpenRegister\Search\SearchWiring;
use OCA\OpenRegister\Search\Service\ExampleSet\ExampleSetViewRowsInterface;
use OCA\OpenRegister\Search\Service\ExampleSet\ExampleSetViews;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\ChunkVectorFactory;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\ChunkVectorFactoryInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class H11InterfaceBindingsTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * Each new interface and the class it is bound to.
	 *
	 * @return array<string, array{0: class-string, 1: class-string}>
	 */
	public static function bindings(): array {
		return [
			'example-set views' => [ExampleSetViewRowsInterface::class, ExampleSetViews::class],
			'chunk vector factory' => [ChunkVectorFactoryInterface::class, ChunkVectorFactory::class],
		];
	}//end bindings()

	/**
	 * The interface is bound in SearchWiring to the class, and the class implements it.
	 *
	 * @param class-string $contract The interface.
	 * @param class-string $service  The class.
	 *
	 * @return void
	 */
	#[DataProvider('bindings')]
	public function testBindingIsPresentAndImplemented(string $contract, string $service): void {
		$this->assertArrayHasKey($contract, SearchWiring::BINDINGS);
		$this->assertSame($service, SearchWiring::BINDINGS[$contract]);
		$this->assertTrue(is_subclass_of($service, $contract), $service . ' must implement ' . $contract);
	}//end testBindingIsPresentAndImplemented()

	/**
	 * Outside lib/Search, the callers name the interface or the handle, not the Search classes.
	 *
	 * @return array<string, array{0: string, 1: list<string>}>
	 */
	public static function callers(): array {
		return [
			'example-set own row kinds' => ['Service/ExampleSet/ExampleSetOwnRowKinds.php', ['ExampleSetViews']],
			'operations jobs' => ['Service/Operations/OperationsJobsService.php', ['SearchIndexRebuildJob']],
			'chunk vectorization batch' => [
				'Files/BackgroundJob/ChunkVectorizationBatch.php',
				['StoredVector', 'VectorSubject', 'VectorPayload', 'VectorChunkPosition'],
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
