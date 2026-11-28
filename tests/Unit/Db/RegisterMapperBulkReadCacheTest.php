<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/built-in-dashboards/spec.md#requirement-dashboardservice-must-assemble-or-canned-statistics-and-chart-payloads-with-fail-soft-semantics
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\RegisterMapperServices;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Tenancy\TenancyServices;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * A register loaded by the bulk read is the unfiltered row find($id, _multitenancy: false)
 * loads, so that lookup is answered without a query; a tenancy-filtered lookup still queries.
 */
class RegisterMapperBulkReadCacheTest extends TestCase {
	use RecordingQueryBuilderTrait;

	public function testAnUnfilteredIdLookupAfterTheBulkReadNeedsNoQuery(): void {
		$mapper = $this->mapper();
		$this->queuedRows = [[['id' => 7, 'uuid' => 'u-7', 'slug' => 's-7', 'title' => 'Seven'], ['id' => 8, 'title' => 'Eight']]];

		$bulk = $mapper->findMultipleOptimized([7, 8]);
		$this->assertSame(1, $this->builderCount);

		$this->assertSame($bulk[7], $mapper->find(7, _rbac: false, _multitenancy: false));
		$this->assertSame($bulk[8], $mapper->find('8', _rbac: false, _multitenancy: false));
		$this->assertSame(1, $this->builderCount, 'both lookups come from the bulk read');
	}

	public function testATenancyFilteredLookupStillQueries(): void {
		$mapper = $this->mapper();
		$this->queuedRows = [[['id' => 7, 'title' => 'Seven']], [['id' => 7, 'title' => 'Seven']]];

		$mapper->findMultipleOptimized([7]);
		$mapper->find(7, _rbac: false, _multitenancy: true);

		$this->assertSame(2, $this->builderCount, 'the organisation filter is not answered from the unfiltered row');
	}

	public function testOnlyTheIdIsRememberedNotTheSlug(): void {
		$mapper = $this->mapper();
		$this->queuedRows = [[['id' => 7, 'uuid' => 'u-7', 'slug' => 's-7', 'title' => 'Seven']], [['id' => 7, 'slug' => 's-7', 'title' => 'Seven']]];

		$mapper->findMultipleOptimized([7]);
		$mapper->find('s-7', _rbac: false, _multitenancy: false);

		$this->assertGreaterThan(1, $this->builderCount, 'a slug still resolves through its own lookup (oldest register wins)');
	}

	private function mapper(): RegisterMapper {
		$this->builderCount = 0;
		$this->queryLog = [];
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());

		return new RegisterMapper(
			db: $db,
			tenancy: new TenancyServices(
				$this->createMock(OrganisationMapper::class),
				$this->createMock(IUserSession::class),
				$this->createMock(IGroupManager::class)
			),
			appConfig: $this->createMock(IAppConfig::class),
			services: new RegisterMapperServices(
				schemaMapper: $this->createMock(SchemaMapper::class),
				container: $this->createMock(ContainerInterface::class),
				eventDispatcher: $this->createMock(IEventDispatcher::class),
				logger: new NullLogger()
			)
		);
	}
}//end class
