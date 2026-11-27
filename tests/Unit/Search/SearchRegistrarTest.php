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

use OCA\OpenRegister\Kernel\Contracts\Core\ViewFinderInterface;
use OCA\OpenRegister\Search\Db\ViewMapper;
use OCA\OpenRegister\Kernel\Contracts\ViewNamingDocumentsInterface;
use OCA\OpenRegister\Search\Service\ViewNamingDocuments;
use OCA\OpenRegister\Db\SchemaMapper\SchemaDeclarationChecksEvent;
use OCA\OpenRegister\Listener\UniqueHintDeclarationCheckListener;
use OCA\OpenRegister\Search\Listener\AggregationDeclarationCheckListener;
use OCA\OpenRegister\Kernel\Contracts\Core\FilterPlaceholdersInterface;
use OCA\OpenRegister\Search\SearchRegistrar;
use OCA\OpenRegister\Kernel\Contracts\PropertySearchProfileInterface;
use OCA\OpenRegister\Search\Service\Search\PlaceholderResolverInterface;
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
 * @uses \OCA\OpenRegister\Search\SearchWiring
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

		$this->assertCount(23, $expected);
		$this->assertSame(\OCA\OpenRegister\Service\GraphQL\AdminQueryRunner::class, SearchRegistrar::BINDINGS[\OCA\OpenRegister\Kernel\Contracts\AdminQueryRunnerInterface::class]);
		$this->assertSame(ViewMapper::class, SearchRegistrar::BINDINGS[ViewFinderInterface::class]);
		$this->assertSame(ViewNamingDocuments::class, SearchRegistrar::BINDINGS[ViewNamingDocumentsInterface::class]);
		$this->assertSame(expected: $expected, actual: $context->aliases);
	}//end testEveryInterfaceIsBoundToItsImplementation()

	/**
	 * Search registers its own schema-save listeners, the aggregations and unique-hint checks, for the declaration checks event only.
	 *
	 * @return void
	 */
	public function testTheDeclarationCheckListenersAreRegistered(): void {
		$context = new RecordingRegistrationContext();
		SearchRegistrar::register(context: $context);

		$this->assertSame(
			expected: [
				['event' => SchemaDeclarationChecksEvent::class, 'listener' => AggregationDeclarationCheckListener::class],
				['event' => SchemaDeclarationChecksEvent::class, 'listener' => UniqueHintDeclarationCheckListener::class],
			],
			actual: array_map(static fn (array $r): array => ['event' => $r['event'], 'listener' => $r['listener']], $context->listeners)
		);
	}//end testTheDeclarationCheckListenersAreRegistered()

	/**
	 * The placeholder resolver and the property search profile count to Core, so
	 * Search no longer binds them; Core's own map does.
	 *
	 * @return void
	 */
	public function testSearchNoLongerBindsThePlaceholdersOrTheSearchProfile(): void {
		$this->assertArrayNotHasKey(key: FilterPlaceholdersInterface::class, array: SearchRegistrar::BINDINGS);
		$this->assertArrayNotHasKey(key: PlaceholderResolverInterface::class, array: SearchRegistrar::BINDINGS);
		$this->assertArrayNotHasKey(key: PropertySearchProfileInterface::class, array: SearchRegistrar::BINDINGS);
	}//end testSearchNoLongerBindsThePlaceholdersOrTheSearchProfile()
}//end class
