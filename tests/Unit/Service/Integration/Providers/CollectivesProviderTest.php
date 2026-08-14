<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\Integration\Providers\CollectivesProvider}.
 *
 * Covers:
 *  - metadata getters (id / label / icon / group / requiredApp / storage)
 *  - `isEnabled()` honours `IAppManager::isInstalled()`
 *  - `list()` returns empty when Collectives is not installed
 *  - `list()` returns rows from link table (Tier-2 path)
 *  - `list()` falls back to marker-scan when link table is empty
 *  - `health()` reports `'unavailable'` when Collectives is not installed
 *  - `health()` reports `'ok'` when Collectives is installed
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integration-collectives/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Db\CollectiveLink;
use OCA\OpenRegister\Db\CollectiveLinkMapper;
use OCA\OpenRegister\Service\Integration\Providers\CollectivesProvider;
use OCP\App\IAppManager;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * CollectivesProviderTest.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class CollectivesProviderTest extends TestCase {

	private function buildL10n(): IL10N {
		$mock = $this->createMock(IL10N::class);
		$mock->method('t')->willReturnArgument(0);
		return $mock;
	}//end buildL10n()

	private function buildAppManager(bool $installed): IAppManager {
		$mock = $this->createMock(IAppManager::class);
		$mock->method('isInstalled')->willReturn($installed);
		return $mock;
	}//end buildAppManager()

	private function buildEmptyResultDb(): IDBConnection {
		$result = $this->createMock(\OCP\DB\IResult::class);
		$result->method('fetch')->willReturn(false);

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('iLike')->willReturn('1=1');

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturn(':p');
		$qb->method('expr')->willReturn($expr);
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		return $db;
	}//end buildEmptyResultDb()

	private function buildProvider(bool $installed, ?CollectiveLinkMapper $mapper = null, ?IDBConnection $db = null): CollectivesProvider {
		$mapper = $mapper ?? $this->createMock(CollectiveLinkMapper::class);
		$db = $db ?? $this->buildEmptyResultDb();
		return new CollectivesProvider(
			$db,
			$this->buildAppManager($installed),
			$this->buildL10n(),
			$mapper,
		);
	}//end buildProvider()

	public function testMetadataGetters(): void {
		$provider = $this->buildProvider(installed: true);

		$this->assertSame('collectives', $provider->getId());
		$this->assertSame('Knowledge', $provider->getLabel());
		$this->assertSame('BookOpenPageVariant', $provider->getIcon());
		$this->assertSame('docs', $provider->getGroup());
		$this->assertSame('collectives', $provider->getRequiredApp());
		$this->assertSame('link-table', $provider->getStorageStrategy());
		$this->assertTrue($provider->isEnabled());
	}//end testMetadataGetters()

	public function testIsEnabledFalseWhenUninstalled(): void {
		$provider = $this->buildProvider(installed: false);

		$this->assertFalse($provider->isEnabled());
	}//end testIsEnabledFalseWhenUninstalled()

	public function testListReturnsEmptyWhenCollectivesUninstalled(): void {
		$mapper = $this->createMock(CollectiveLinkMapper::class);
		$mapper->expects($this->never())->method('findByObjectUuid');

		$provider = $this->buildProvider(installed: false, mapper: $mapper);

		$this->assertSame([], $provider->list('reg', 'sch', 'obj-uuid'));
	}//end testListReturnsEmptyWhenCollectivesUninstalled()

	public function testListReturnsEmptyWhenNoLinks(): void {
		$mapper = $this->createMock(CollectiveLinkMapper::class);
		$mapper->method('findByObjectUuid')->with('obj-uuid')->willReturn([]);

		$provider = $this->buildProvider(installed: true, mapper: $mapper);

		$rows = $provider->list('reg', 'sch', 'obj-uuid');

		$this->assertSame([], $rows);
	}//end testListReturnsEmptyWhenNoLinks()

	public function testListNormalisesLinkTableRows(): void {
		$link = new CollectiveLink();
		$link->setObjectUuid('obj-uuid');
		$link->setPageId(42);
		$link->setPageTitle('Runbook');
		$link->setCollectiveName('Engineering');
		$link->setEmoji('📖');
		$link->setUrl('/index.php/apps/collectives/?fileId=42');

		$mapper = $this->createMock(CollectiveLinkMapper::class);
		$mapper->method('findByObjectUuid')->with('obj-uuid')->willReturn([$link]);

		$provider = $this->buildProvider(installed: true, mapper: $mapper);

		$rows = $provider->list('reg', 'sch', 'obj-uuid');

		$this->assertCount(1, $rows);
		$this->assertSame('42', $rows[0]['id']);
		$this->assertSame('Runbook', $rows[0]['title']);
		$this->assertSame('/index.php/apps/collectives/?fileId=42', $rows[0]['url']);
		$this->assertSame('📖', $rows[0]['emoji']);
		$this->assertSame('Engineering', $rows[0]['collectiveName']);
	}//end testListNormalisesLinkTableRows()

	public function testHealthReportsUnavailableWhenUninstalled(): void {
		$provider = $this->buildProvider(installed: false);

		$health = $provider->health();

		$this->assertSame('unavailable', $health['status']);
		$this->assertNotEmpty($health['message']);
	}//end testHealthReportsUnavailableWhenUninstalled()

	public function testHealthReportsOkWhenInstalled(): void {
		$provider = $this->buildProvider(installed: true);

		$health = $provider->health();

		$this->assertSame('ok', $health['status']);
		$this->assertNull($health['message']);
	}//end testHealthReportsOkWhenInstalled()
}//end class
