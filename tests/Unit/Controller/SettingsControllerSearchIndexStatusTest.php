<?php

/**
 * The wire contract of GET /api/settings/search-index.
 *
 * This is the one endpoint an administrator reads to learn the state of the
 * indexes behind object search, and the one `occ openregister:tables:search-index
 * status` mirrors. Its response shape is the contract: a caller that renders
 * `indexCount` or reads `lastRun` breaks silently if a key is renamed, so the
 * keys are asserted by name and the counts are asserted against the inventory
 * they summarise.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\SettingsController;
use OCA\OpenRegister\Service\Search\SearchIndexMaintenance;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\VectorizationService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * SettingsController::getSearchIndexStatus(), answered and refused.
 */
class SettingsControllerSearchIndexStatusTest extends TestCase {

	/**
	 * A controller whose container hands out the given maintenance service.
	 *
	 * @param SearchIndexMaintenance $maintenance The service the endpoint reads.
	 *
	 * @return SettingsController The controller under test.
	 */
	private function controllerWith(SearchIndexMaintenance $maintenance): SettingsController {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')
			->with(SearchIndexMaintenance::class)
			->willReturn($maintenance);

		return new SettingsController(
			appName: 'openregister',
			request: $this->createMock(originalClassName: IRequest::class),
			config: $this->createMock(originalClassName: IAppConfig::class),
			db: $this->createMock(originalClassName: IDBConnection::class),
			container: $container,
			appManager: $this->createMock(originalClassName: IAppManager::class),
			settingsService: $this->createMock(originalClassName: SettingsService::class),
			vectorizationService: $this->createMock(originalClassName: VectorizationService::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
	}//end controllerWith()

	/**
	 * The inventory, the platform answer and the last run arrive under their names.
	 *
	 * @return void
	 */
	public function testTheStatusCarriesTheInventoryAndTheLastRun(): void {
		$lastRun = ['finishedAt' => '2026-09-16T08:00:00+00:00', 'created' => 2, 'failed' => 0];

		$maintenance = $this->createMock(originalClassName: SearchIndexMaintenance::class);
		$maintenance->method('supportsConcurrentRebuild')->willReturn(true);
		$maintenance->method('tablesInScope')->willReturn(['oc_openregister_table_1_2', 'oc_openregister_table_1_3']);
		$maintenance->method('indexesFor')->willReturnMap(
			[
				['oc_openregister_table_1_2', ['idx_a' => 'CREATE INDEX idx_a', 'idx_b' => 'CREATE INDEX idx_b']],
				['oc_openregister_table_1_3', ['idx_c' => 'CREATE INDEX idx_c']],
			]
		);
		$maintenance->method('lastRun')->willReturn($lastRun);

		$response = $this->controllerWith(maintenance: $maintenance)->getSearchIndexStatus();

		$this->assertSame(expected: 200, actual: $response->getStatus());
		$this->assertSame(
			expected: [
				'concurrentRebuildSupported' => true,
				'tables' => [
					'oc_openregister_table_1_2' => ['idx_a', 'idx_b'],
					'oc_openregister_table_1_3' => ['idx_c'],
				],
				'tableCount' => 2,
				'indexCount' => 3,
				'lastRun' => $lastRun,
			],
			actual: $response->getData()
		);
	}//end testTheStatusCarriesTheInventoryAndTheLastRun()

	/**
	 * Before the first rebuild the counts are zero and lastRun is empty, not missing.
	 *
	 * A caller that reads `lastRun` must find the key on a fresh instance too.
	 *
	 * @return void
	 */
	public function testAFreshInstanceAnswersWithEmptyValuesRatherThanMissingKeys(): void {
		$maintenance = $this->createMock(originalClassName: SearchIndexMaintenance::class);
		$maintenance->method('supportsConcurrentRebuild')->willReturn(false);
		$maintenance->method('tablesInScope')->willReturn([]);
		$maintenance->method('lastRun')->willReturn([]);

		$data = $this->controllerWith(maintenance: $maintenance)->getSearchIndexStatus()->getData();

		$this->assertSame(expected: false, actual: $data['concurrentRebuildSupported']);
		$this->assertSame(expected: [], actual: $data['tables']);
		$this->assertSame(expected: 0, actual: $data['tableCount']);
		$this->assertSame(expected: 0, actual: $data['indexCount']);
		$this->assertSame(expected: [], actual: $data['lastRun']);
	}//end testAFreshInstanceAnswersWithEmptyValuesRatherThanMissingKeys()

	/**
	 * An inventory that cannot be read is a 500 naming the error.
	 *
	 * @return void
	 */
	public function testAnUnreadableInventoryIsA500NamingTheError(): void {
		$maintenance = $this->createMock(originalClassName: SearchIndexMaintenance::class);
		$maintenance->method('supportsConcurrentRebuild')->willReturn(true);
		$maintenance->method('tablesInScope')->willThrowException(new Exception('information_schema is unreachable'));

		$response = $this->controllerWith(maintenance: $maintenance)->getSearchIndexStatus();

		$this->assertSame(expected: 500, actual: $response->getStatus());
		$this->assertSame(expected: ['error' => 'information_schema is unreachable'], actual: $response->getData());
	}//end testAnUnreadableInventoryIsA500NamingTheError()
}//end class
