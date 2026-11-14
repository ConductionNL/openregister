<?php

/**
 * Protected fields and provenance through the real MappingService.
 *
 * The payload a harvest writes, and the mappedData the conflict queue shows,
 * are the source's mapping run by the real Twig-backed MappingService, with
 * the protected fields removed and the provenance set after mapping, so a
 * mapping cannot write a protected field or override the provenance.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Sync
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Sync;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\Mapping;
use OCA\OpenRegister\Db\MappingMapperInterface;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SyncRecordMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\MappingService;
use OCA\OpenRegister\Service\Sync\HarvestImportServices;
use OCA\OpenRegister\Service\Sync\HarvestPipelineService;
use OCA\OpenRegister\Service\Sync\SyncConflictResolver;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Sync\HarvestPipelineService
 * @uses   \OCA\OpenRegister\Service\MappingService
 * @uses   \OCA\OpenRegister\Service\Sync\HarvestImportServices
 * @uses   \OCA\OpenRegister\Service\Sync\SyncConflictResolver
 * @uses   \OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator
 * @uses   \OCA\OpenRegister\Db\Mapping
 * @uses   \OCA\OpenRegister\Db\Source
 * @uses   \OCA\OpenRegister\Db\Mapping\MappingSlug
 * @uses   \OCA\OpenRegister\Db\TypedFieldsJson
 * @uses   \OCA\OpenRegister\Service\MappingKeyRewrite
 * @uses   \OCA\OpenRegister\Service\MappingTwigSandbox
 * @uses   \OCA\OpenRegister\Twig\MappingExtension
 * @uses   \OCA\OpenRegister\Twig\MappingRuntimeLoader
 * @uses   \OCA\OpenRegister\Twig\MappingTwigInstaller
 * @uses   \OCA\OpenRegister\Service\RegisterMappingFunctionsEvent
 */
class HarvestProtectedFieldsMappingTest extends TestCase {

	private HarvestPipelineService $pipeline;

	protected function setUp(): void {
		$cache = $this->createMock(ICacheFactory::class);
		$cache->method('createDistributed')->willReturn($this->createMock(ICache::class));
		$mappings = $this->createMock(MappingMapperInterface::class);

		$this->pipeline = new HarvestPipelineService(
			syncRecordMapper: $this->createMock(SyncRecordMapperInterface::class),
			importServices: new HarvestImportServices(
				mappingMapper: $mappings,
				mappingService: new MappingService($mappings, $cache, $this->createMock(LoggerInterface::class)),
				objectService: $this->createMock(ObjectOperationsInterface::class),
				conflictResolver: new SyncConflictResolver()
			),
			logger: $this->createMock(LoggerInterface::class)
		);
	}

	private function mapping(): Mapping {
		$mapping = new Mapping();
		$mapping->setMapping([
			'title' => '{{ name }}',
			'status' => '{{ state }}',
			'publicationDate' => '{{ issued }}',
			'dct:source' => 'spoofed-by-the-mapping',
		]);
		$mapping->setUnset([]);
		$mapping->setCast([]);
		$mapping->setPassThrough(false);
		return $mapping;
	}

	private function source(): Source {
		$source = new Source();
		$source->setUuid('src-uuid');
		$source->setProtectedFields(['status', 'publicationDate']);
		$source->setProvenance(['externalIdProperty' => 'dct:source', 'sourceProperty' => 'prov:wasDerivedFrom']);
		return $source;
	}

	public function testTheMappedPayloadHasNoProtectedFieldAndTheSourcesProvenance(): void {
		$payload = $this->pipeline->writablePayload(
			$this->source(),
			$this->mapping(),
			['name' => 'Dataset A', 'state' => 'published', 'issued' => '2026-01-01'],
			'ds-1'
		);

		$this->assertSame('Dataset A', $payload['title']);
		$this->assertArrayNotHasKey('status', $payload);
		$this->assertArrayNotHasKey('publicationDate', $payload);
		$this->assertSame('ds-1', $payload['dct:source']);
		$this->assertSame('src-uuid', $payload['prov:wasDerivedFrom']);
	}

	public function testWithoutProtectedFieldsTheMappingIsWrittenAsMapped(): void {
		$source = new Source();
		$source->setUuid('src-uuid');

		$payload = $this->pipeline->writablePayload($source, $this->mapping(), ['name' => 'B', 'state' => 'draft', 'issued' => 'x'], 'ds-2');

		$this->assertSame('draft', $payload['status']);
		$this->assertSame('spoofed-by-the-mapping', $payload['dct:source']);
	}
}
