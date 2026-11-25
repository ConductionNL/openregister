<?php

/**
 * SearchRegistrar: container registrations of the Search component.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Search
 * @package  OCA\OpenRegister\Search
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search;

use OCA\OpenRegister\Db\SchemaMapper\SchemaDeclarationChecksEvent;
use OCA\OpenRegister\Listener\UniqueHintDeclarationCheckListener;
use OCA\OpenRegister\Search\Db\ViewMapper;
use OCA\OpenRegister\Kernel\Contracts\Core\ViewFinderInterface;
use OCA\OpenRegister\Search\Listener\AggregationDeclarationCheckListener;
use OCA\OpenRegister\Kernel\Contracts\EmbeddingGeneratorInterface;
use OCA\OpenRegister\Kernel\Contracts\MetricsRetentionInterface;
use OCA\OpenRegister\Search\Db\ViewMapperInterface;
use OCA\OpenRegister\Search\Service\Aggregation\AggregationCache;
use OCA\OpenRegister\Search\Service\Aggregation\AggregationCacheInterface;
use OCA\OpenRegister\Search\Service\Aggregation\AggregationAnnotationValidator;
use OCA\OpenRegister\Search\Service\Aggregation\AggregationAnnotationValidatorInterface;
use OCA\OpenRegister\Search\Service\Geo\GeoFeatureCollectionBuilder;
use OCA\OpenRegister\Search\Service\Geo\GeoFeatureCollectionBuilderInterface;
use OCA\OpenRegister\Search\Service\Geo\GeoFilterApplier;
use OCA\OpenRegister\Search\Service\Geo\GeoFilterApplierInterface;
use OCA\OpenRegister\Search\Service\Geo\GeoFilterParser;
use OCA\OpenRegister\Search\Service\Geo\GeoFilterParserInterface;
use OCA\OpenRegister\Search\Service\Geo\SpatialFilter;
use OCA\OpenRegister\Kernel\Contracts\Core\SpatialFilterInterface;
use OCA\OpenRegister\Search\Service\Aggregation\TimeseriesRequestValidator;
use OCA\OpenRegister\Search\Service\Aggregation\TimeseriesRequestValidatorInterface;
use OCA\OpenRegister\Search\Service\Quality\DuplicateDetectionService;
use OCA\OpenRegister\Search\Service\Quality\DuplicateDetectionServiceInterface;
use OCA\OpenRegister\Search\Service\Quality\DedupCreatePolicy;
use OCA\OpenRegister\Search\Service\Quality\DedupCreatePolicyInterface;
use OCA\OpenRegister\Kernel\Contracts\CreateGuardInterface;
use OCA\OpenRegister\Search\Service\Search\SearchIndexMaintenance;
use OCA\OpenRegister\Search\Service\Search\SearchIndexMaintenanceInterface;
use OCA\OpenRegister\Search\Service\Quality\UniqueHintAnnotationValidator;
use OCA\OpenRegister\Service\Quality\UniqueHintAnnotationValidatorInterface;
use OCA\OpenRegister\Search\Service\VectorizationService;
use OCA\OpenRegister\Search\Service\MetricsService;
use OCA\OpenRegister\Search\Service\VectorizationServiceInterface;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\ChunkVectorFactory;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\ChunkVectorFactoryInterface;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorStorageHandler;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorStorageHandlerInterface;
use OCA\OpenRegister\Search\Service\Vectorization\VectorEmbeddings;
use OCA\OpenRegister\Search\Service\Vectorization\VectorEmbeddingsInterface;
use OCA\OpenRegister\Service\Vectorization\Strategies\FileVectorizationStrategyInterface;
use OCA\OpenRegister\Service\Vectorization\Strategies\ObjectVectorizationStrategyInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\StoredViewQueriesInterface;
use OCA\OpenRegister\Kernel\Contracts\ViewNamingDocumentsInterface;
use OCA\OpenRegister\Search\Service\StoredViewQueries;
use OCA\OpenRegister\Search\Service\ViewNamingDocuments;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;


/**
 * The wiring behind SearchRegistrar.
 *
 * SearchRegistrar keeps the public entry points and delegates each of them here.
 *
 * Registers the services of the Search component that the container cannot
 * autowire, and binds the interfaces other components type against.
 */
final class SearchWiring {

	/**
	 * The interfaces callers outside the component type against, bound to their class.
	 *
	 * @var array<class-string, class-string>
	 */
	public const BINDINGS = [
		VectorEmbeddingsInterface::class => VectorEmbeddings::class,
		EmbeddingGeneratorInterface::class => VectorEmbeddings::class,
		GeoFeatureCollectionBuilderInterface::class => GeoFeatureCollectionBuilder::class,
		GeoFilterApplierInterface::class => GeoFilterApplier::class,
		GeoFilterParserInterface::class => GeoFilterParser::class,
		SpatialFilterInterface::class => SpatialFilter::class,
		MetricsRetentionInterface::class => MetricsService::class,
		SearchIndexMaintenanceInterface::class => SearchIndexMaintenance::class,
		DuplicateDetectionServiceInterface::class => DuplicateDetectionService::class,
		UniqueHintAnnotationValidatorInterface::class => UniqueHintAnnotationValidator::class,
		AggregationAnnotationValidatorInterface::class => AggregationAnnotationValidator::class,
		DedupCreatePolicyInterface::class => DedupCreatePolicy::class,
		CreateGuardInterface::class => DedupCreatePolicy::class,
		TimeseriesRequestValidatorInterface::class => TimeseriesRequestValidator::class,
		VectorizationServiceInterface::class => VectorizationService::class,
		VectorStorageHandlerInterface::class => VectorStorageHandler::class,
		AggregationCacheInterface::class => AggregationCache::class,
		ViewMapperInterface::class => ViewMapper::class,
		ViewFinderInterface::class => ViewMapper::class,
		ChunkVectorFactoryInterface::class => ChunkVectorFactory::class,
		StoredViewQueriesInterface::class => StoredViewQueries::class,
		ViewNamingDocumentsInterface::class => ViewNamingDocuments::class,
		\OCA\OpenRegister\Kernel\Contracts\AdminQueryRunnerInterface::class => \OCA\OpenRegister\Service\GraphQL\AdminQueryRunner::class,
	];

	/**
	 * Register the Search component's services and its schema-save listener.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Container wiring; no behaviour of its own.
	 */
	public static function register(IRegistrationContext $context): void {
		$context->registerService(VectorizationService::class, self::buildVectorizationService(...));

		foreach (self::BINDINGS as $contract => $service) {
			$context->registerServiceAlias($contract, $service);
		}

		// The aggregations declaration check runs inside the schema save, in the
		// AGGREGATIONS slot of SchemaDeclarationOrder the mapper keeps for it.
		$context->registerEventListener(SchemaDeclarationChecksEvent::class, AggregationDeclarationCheckListener::class);

		// The unique-hint declaration check runs inside the schema save, in its slot of SchemaDeclarationOrder.
		$context->registerEventListener(SchemaDeclarationChecksEvent::class, UniqueHintDeclarationCheckListener::class);
	}//end register()

	/**
	 * Build the VectorizationService with the file and object strategies.
	 *
	 * @param ContainerInterface $container The container.
	 *
	 * @return VectorizationServiceInterface
	 */
	private static function buildVectorizationService(ContainerInterface $container): VectorizationServiceInterface {
		$service = new VectorizationService(
			vectorService: $container->get(VectorEmbeddingsInterface::class),
			logger: $container->get(LoggerInterface::class)
		);

		$service->registerStrategy('file', $container->get(FileVectorizationStrategyInterface::class));
		$service->registerStrategy('object', $container->get(ObjectVectorizationStrategyInterface::class));

		return $service;
	}//end buildVectorizationService()
}//end class
