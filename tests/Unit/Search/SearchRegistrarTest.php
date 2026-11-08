<?php

/**
 * Unit tests for SearchRegistrar.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Search
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search;

use OCA\OpenRegister\Search\SearchRegistrar;
use OCA\OpenRegister\Search\Service\Vectorization\VectorEmbeddingsInterface;
use OCA\OpenRegister\Search\Service\VectorizationService;
use OCA\OpenRegister\Service\Vectorization\Strategies\FileVectorizationStrategy;
use OCA\OpenRegister\Service\Vectorization\Strategies\FileVectorizationStrategyInterface;
use OCA\OpenRegister\Service\Vectorization\Strategies\ObjectVectorizationStrategy;
use OCA\OpenRegister\Service\Vectorization\Strategies\ObjectVectorizationStrategyInterface;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use OCP\AppFramework\IAppContainer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Search\SearchRegistrar
 * @uses   \OCA\OpenRegister\Search\Service\VectorizationService
 */
final class SearchRegistrarTest extends TestCase {

	/**
	 * The vectorization service is built with both strategies registered.
	 *
	 * @return void
	 */
	public function testTheVectorizationServiceGetsTheFileAndObjectStrategies(): void {
		$context = new RecordingRegistrationContext();
		SearchRegistrar::register(context: $context);

		$this->assertSame(expected: [VectorizationService::class], actual: $context->services);

		$file   = $this->createMock(FileVectorizationStrategy::class);
		$object = $this->createMock(ObjectVectorizationStrategy::class);
		$doubles = [
			VectorEmbeddingsInterface::class => $this->createMock(VectorEmbeddingsInterface::class),
			LoggerInterface::class => $this->createMock(LoggerInterface::class),
			FileVectorizationStrategyInterface::class => $file,
			ObjectVectorizationStrategyInterface::class => $object,
		];
		$container = $this->createMock(IAppContainer::class);
		$container->method('get')->willReturnCallback(static fn (string $id) => $doubles[$id]);

		$service = $context->factories[VectorizationService::class]($container);

		$this->assertInstanceOf(expected: VectorizationService::class, actual: $service);
		$strategies = (new \ReflectionProperty($service, 'strategies'))->getValue($service);
		$this->assertSame(expected: ['file' => $file, 'object' => $object], actual: $strategies);
	}//end testTheVectorizationServiceGetsTheFileAndObjectStrategies()

	/**
	 * Every interface is bound to a class that implements it.
	 *
	 * @return void
	 */
	public function testEveryInterfaceIsBoundToItsImplementation(): void {
		$context = new RecordingRegistrationContext();
		SearchRegistrar::register(context: $context);

		$expected = [];
		foreach (SearchRegistrar::BINDINGS as $contract => $service) {
			$this->assertTrue(is_subclass_of($service, $contract), $service . ' implements ' . $contract);
			$expected[] = ['alias' => $contract, 'target' => $service];
		}

		$this->assertCount(17, $expected);
		$this->assertSame(expected: $expected, actual: $context->aliases);
	}//end testEveryInterfaceIsBoundToItsImplementation()
}//end class
