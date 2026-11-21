<?php

/**
 * Unit tests for OpenConnectorHttpProvider — an external register's records
 * served as objects through a configured OpenConnector source.
 *
 * The router stands in for OpenConnector. The tests assert the source and the
 * path each read asks for, the declared mapping applied to the answer, the
 * rows read from a list body, and that every failure degrades to no record.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectSource
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/external-register-view-leaf/specs/object-source-providers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\Mapping;
use OCA\OpenRegister\Db\MappingMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\ProviderUnavailableException;
use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouterInterface;
use OCA\OpenRegister\Service\Integration\IntegrationProvider;
use OCA\OpenRegister\Service\Integration\UpstreamRequest;
use OCA\OpenRegister\Service\MappingService;
use OCA\OpenRegister\Service\ObjectSource\OpenConnectorHttpProvider;
use OCA\OpenRegister\Service\ObjectSource\SourcedTable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\ObjectSource\OpenConnectorHttpProvider
 * @covers \OCA\OpenRegister\Service\ObjectSource\ConfiguredSourceUpstream
 * @uses \OCA\OpenRegister\Service\ObjectSource\SourcedTable
 * @uses \OCA\OpenRegister\Service\ObjectSource\SourcedObjectBinding
 * @uses \OCA\OpenRegister\Service\Integration\UpstreamRequest
 * @uses \OCA\OpenRegister\Db\RegisterSchema
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 */
final class OpenConnectorHttpProviderTest extends TestCase {

	private const CONFIG = [
		'source' => 'bag',
		'find' => '/adressen/{key}',
		'findAll' => '/adressen',
		'results' => '_embedded.adressen',
		'query' => ['postcode', 'huisnummer'],
		'id' => 'identificatie',
	];

	private ExternalIntegrationRouterInterface&MockObject $router;

	private MappingMapperInterface&MockObject $mappings;

	private MappingService&MockObject $mappingService;

	private LoggerInterface&MockObject $logger;

	/**
	 * The requests the router was asked, as [source, method, path, query].
	 *
	 * @var list<array{0: string|null, 1: string, 2: string, 3: mixed}>
	 */
	private array $asked = [];

	protected function setUp(): void {
		$this->router         = $this->createMock(ExternalIntegrationRouterInterface::class);
		$this->mappings       = $this->createMock(MappingMapperInterface::class);
		$this->mappingService = $this->createMock(MappingService::class);
		$this->logger         = $this->createMock(LoggerInterface::class);
	}

	private function answer(array|\Throwable $body): void {
		$this->router->method('call')->willReturnCallback(
			function (IntegrationProvider $provider, UpstreamRequest $request) use ($body): array {
				self::assertSame('external', $provider->getStorageStrategy());
				$this->asked[] = [$provider->getOpenConnectorSource(), $request->method, $request->path, ($request->options['query'] ?? null)];
				if ($body instanceof \Throwable) {
					throw $body;
				}

				return $body;
			}
		);
	}

	private function source(array $config): SourcedTable {
		$register = new Register();
		$register->setId(4);
		$schema = new Schema();
		$schema->setId(9);
		return new SourcedTable(new RegisterSchema($register, $schema), $config);
	}

	private function provider(): OpenConnectorHttpProvider {
		return new OpenConnectorHttpProvider($this->router, $this->mappings, $this->mappingService, $this->logger);
	}

	public function testItIsEnabledUnderItsId(): void {
		$this->assertSame('openconnector-http', $this->provider()->getId());
		$this->assertTrue($this->provider()->isEnabled());
	}

	public function testFindAsksTheSourceByKeyAndServesTheRecord(): void {
		$this->answer(['identificatie' => '0363200000006110', 'straat' => 'Dam']);

		$entity = $this->provider()->find($this->source(self::CONFIG), '0363200000006110');

		$this->assertSame([['bag', 'GET', '/adressen/0363200000006110', []]], $this->asked);
		$this->assertSame('0363200000006110', $entity->getUuid());
		$this->assertSame('Dam', $entity->getObject()['straat']);
	}

	public function testTheKeyIsEncodedIntoThePath(): void {
		$this->answer(['identificatie' => 'x']);

		$this->provider()->find($this->source(self::CONFIG), 'a/b c');

		$this->assertSame('/adressen/a%2Fb%20c', $this->asked[0][2]);
	}

	public function testADeclaredMappingShapesTheRecord(): void {
		$mapping = new Mapping();
		$this->answer(['nummeraanduiding' => ['id' => 'n-1'], 'openbareRuimteNaam' => 'Dam']);
		$this->mappings->expects($this->once())->method('find')->with('bag-adres-to-object')->willReturn($mapping);
		$this->mappingService->expects($this->once())
			->method('executeMapping')
			->with($mapping, ['nummeraanduiding' => ['id' => 'n-1'], 'openbareRuimteNaam' => 'Dam'])
			->willReturn(['identificatie' => 'n-1', 'straat' => 'Dam']);

		$entity = $this->provider()->find($this->source(self::CONFIG + ['mapping' => 'bag-adres-to-object']), 'n-1');

		$this->assertSame(['identificatie' => 'n-1', 'straat' => 'Dam'], array_intersect_key($entity->getObject(), ['identificatie' => 1, 'straat' => 1]));
	}

	public function testAMappingThatCannotBeLoadedShowsNothingRatherThanTheRawRecord(): void {
		$this->answer(['geheim' => 'ruw']);
		$this->mappings->method('find')->willThrowException(new RuntimeException('no such mapping'));
		$this->logger->expects($this->once())->method('warning')->with($this->stringContains('mapping failed'));

		$this->assertNull($this->provider()->find($this->source(self::CONFIG + ['mapping' => 'missing']), 'k'));
	}

	public function testAnUnavailableSourceDegradesToNoRecord(): void {
		$this->answer(new ProviderUnavailableException('down', ProviderUnavailableException::CAUSE_UPSTREAM_SERVICE_DOWN));
		$this->logger->expects($this->exactly(2))->method('warning')->with($this->stringContains('read failed'));

		$this->assertNull($this->provider()->find($this->source(self::CONFIG), 'k'));
		$this->assertSame([], $this->provider()->findAll($this->source(self::CONFIG)));
	}

	public function testNoSourceOrNoKeyAsksNobody(): void {
		$this->router->expects($this->never())->method('call');

		$config = self::CONFIG;
		unset($config['source']);
		$this->assertNull($this->provider()->find($this->source($config), 'k'));
		$this->assertNull($this->provider()->find($this->source(self::CONFIG), '  '));
		$this->assertNull($this->provider()->find($this->source(['source' => 'bag']), 'k'));
	}

	public function testFindAllPassesOnlyDeclaredQueryAndReadsTheRows(): void {
		$this->answer(['_embedded' => ['adressen' => [['identificatie' => 'a-1'], 'not a row', ['identificatie' => 'a-2']]]]);

		$entities = $this->provider()->findAll(
			$this->source(self::CONFIG),
			['filters' => ['postcode' => '1012JS', 'geheim' => 'x'], 'huisnummer' => 1]
		);

		$this->assertSame([['bag', 'GET', '/adressen', ['postcode' => '1012JS', 'huisnummer' => '1']]], $this->asked);
		$this->assertSame(['a-1', 'a-2'], array_map(static fn ($e) => $e->getUuid(), $entities));
	}

	public function testRowsOfAListBodyAndOfAMissingPath(): void {
		$this->assertSame([['id' => 1]], OpenConnectorHttpProvider::rows([['id' => 1]], 'results'));
		$this->assertSame([], OpenConnectorHttpProvider::rows(['results' => 'x'], 'results'));
		$this->assertSame([], OpenConnectorHttpProvider::rows(['other' => []], '_embedded.adressen'));
	}
}
