<?php

/**
 * Golden test for AnalyticsSeriesService::register().
 *
 * Pins, on the code as it was before the register() refactor, the full
 * stored row, the declared page widget, the insert/update choice and the
 * validation order and messages.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
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
 * @spec openspec/changes/integration-leaf-foundation-shares-analytics/specs/integration-leaf-foundation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers take positional args by convention.

use InvalidArgumentException;
use OCA\OpenRegister\Db\AnalyticsSeries;
use OCA\OpenRegister\Db\AnalyticsSeriesMapper;
use OCA\OpenRegister\Service\AnalyticsSeriesDraft;
use OCA\OpenRegister\Service\AnalyticsSeriesService;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden outcomes of AnalyticsSeriesService::register().
 */
class AnalyticsSeriesServiceRegisterGoldenTest extends TestCase {

	/**
	 * Mapper calls in order.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * The registry the service declares widgets on.
	 *
	 * @var IntegrationRegistry
	 */
	private IntegrationRegistry $registry;

	private function service(?AnalyticsSeries $existing, ?string $uid): AnalyticsSeriesService {
		$this->calls    = [];
		$this->registry = new IntegrationRegistry(logger: $this->createMock(LoggerInterface::class));

		$mapper = $this->createMock(AnalyticsSeriesMapper::class);
		$mapper->method('findByKey')->willReturnCallback(
			function (string $key) use ($existing): ?AnalyticsSeries {
				$this->calls[] = 'findByKey:' . $key;
				return $existing;
			}
		);
		$mapper->method('insert')->willReturnCallback(
			function (AnalyticsSeries $s): AnalyticsSeries {
				$this->calls[] = 'insert';
				$s->setId(1);
				return $s;
			}
		);
		$mapper->method('update')->willReturnCallback(
			function (AnalyticsSeries $s): AnalyticsSeries {
				$this->calls[] = 'update';
				return $s;
			}
		);

		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturnCallback(
			function () use ($user): ?IUser {
				$this->calls[] = 'getUser';
				return $user;
			}
		);

		return new AnalyticsSeriesService(
			mapper: $mapper,
			registry: $this->registry,
			userSession: $session,
			groupManager: $this->createMock(IGroupManager::class),
		);
	}//end service()

	/**
	 * The stored row without its two timestamps, which are asserted separately.
	 *
	 * @param array<string, mixed> $row The serialised row.
	 *
	 * @return array<string, mixed>
	 */
	private function withoutTimes(array $row): array {
		$this->assertNotNull($row['updatedAt']);
		unset($row['createdAt'], $row['updatedAt']);
		return $row;
	}//end withoutTimes()

	public function testANewSeriesIsInsertedWithItsCreatorAndDeclaresTheWidget(): void {
		$service = $this->service(existing: null, uid: 'alice');

		$row = $service->registerSeries(
			draft: (new AnalyticsSeriesDraft(seriesKey: 'sla-breaches', title: 'SLA breaches'))
				->withData(labels: ['a' => 'Wk1', 'b' => 'Wk2'], datasets: ['x' => ['label' => 'Breaches', 'data' => [3, 5]]])
				->shownAs(chartType: 'bar', visibility: 'group')
				->inScope(registerId: 2, schemaId: 4)
		);

		$this->assertSame(['getUser', 'findByKey:sla-breaches', 'insert'], $this->calls);
		$this->assertSame($row['createdAt'], $row['updatedAt']);
		$this->assertSame(
			[
				'id'         => 1,
				'seriesKey'  => 'sla-breaches',
				'registerId' => 2,
				'schemaId'   => 4,
				'title'      => 'SLA breaches',
				'chartType'  => 'bar',
				'labels'     => ['Wk1', 'Wk2'],
				'datasets'   => [['label' => 'Breaches', 'data' => [3, 5]]],
				'visibility' => 'group',
				'createdBy'  => 'alice',
			],
			array_intersect_key(
				$this->withoutTimes($row),
				array_flip(['id', 'seriesKey', 'registerId', 'schemaId', 'title', 'chartType', 'labels', 'datasets', 'visibility', 'createdBy'])
			)
		);
		$this->assertSame(
			[
				'id'         => 'analytics-series:sla-breaches',
				'type'       => 'chart',
				'title'      => 'SLA breaches',
				'providerId' => 'analytics-series',
				'config'     => [
					'seriesKey'  => 'sla-breaches',
					'chartType'  => 'bar',
					'registerId' => 2,
					'schemaId'   => 4,
				],
			],
			$this->registry->getPageWidget('analytics-series:sla-breaches')
		);
	}//end testANewSeriesIsInsertedWithItsCreatorAndDeclaresTheWidget()

	public function testAnExistingSeriesIsUpdatedAndKeepsItsCreator(): void {
		$existing = new AnalyticsSeries();
		$existing->setId(9);
		$existing->setSeriesKey('k');
		$existing->setCreatedBy('bob');
		$existing->setRegisterId(7);
		$existing->setSchemaId(8);
		$existing->setTitle('Old');

		$service = $this->service(existing: $existing, uid: 'alice');
		$row     = $service->registerSeries(
			draft: (new AnalyticsSeriesDraft(seriesKey: 'k', title: null))
				->withData(labels: [], datasets: [])
		);

		$this->assertSame(['getUser', 'findByKey:k', 'update'], $this->calls);
		$this->assertNull($row['createdAt']);
		$this->assertSame(9, $row['id']);
		$this->assertSame('bob', $row['createdBy']);
		$this->assertNull($row['registerId']);
		$this->assertNull($row['schemaId']);
		$this->assertNull($row['title']);
		$this->assertSame('line', $row['chartType']);
		$this->assertSame('private', $row['visibility']);
		$this->assertSame([], $row['labels']);
		$this->assertSame(
			[
				'id'         => 'analytics-series:k',
				'type'       => 'chart',
				'title'      => null,
				'providerId' => 'analytics-series',
				'config'     => ['seriesKey' => 'k', 'chartType' => 'line', 'registerId' => null, 'schemaId' => null],
			],
			$this->registry->getPageWidget('analytics-series:k')
		);
	}//end testAnExistingSeriesIsUpdatedAndKeepsItsCreator()

	/**
	 * @return array<string, array{0: string, 1: string, 2: string, 3: ?string, 4: string}>
	 */
	public static function refusals(): array {
		$charts = 'Invalid chartType — expected one of: line, bar, pie, doughnut, area, radar';
		return [
			'blank key before anything else'  => ['  ', 'nope', 'sankey', null, 'seriesKey is required to register a series'],
			'visibility before chart type'    => ['k', 'world', 'sankey', null, 'Invalid visibility — expected one of: private, group, public'],
			'chart type'                      => ['k', 'public', 'sankey', 'alice', $charts],
			'validation before the user'      => ['k', 'public', 'sankey', null, $charts],
			'anonymous caller'                => ['k', 'public', 'pie', null, 'A logged-in user is required to register a series'],
		];
	}//end refusals()

	/**
	 * Each refusal, its message, and that nothing was read or written.
	 *
	 * @param string      $key        The series key.
	 * @param string      $visibility The visibility.
	 * @param string      $chartType  The chart type.
	 * @param string|null $uid        The logged-in user, or null.
	 * @param string      $message    The expected message.
	 *
	 * @return void
	 *
	 * @dataProvider refusals
	 */
	public function testRefusals(string $key, string $visibility, string $chartType, ?string $uid, string $message): void {
		$service = $this->service(existing: null, uid: $uid);

		try {
			$service->registerSeries(
				draft: (new AnalyticsSeriesDraft(seriesKey: $key, title: null))
					->withData(labels: [], datasets: [])
					->shownAs(chartType: $chartType, visibility: $visibility)
			);
			$this->fail('expected a refusal');
		} catch (InvalidArgumentException $e) {
			$this->assertSame($message, $e->getMessage());
		}

		$this->assertNotContains('insert', $this->calls);
		$this->assertNotContains('update', $this->calls);
		$this->assertNotContains('findByKey:' . $key, $this->calls);
		$this->assertNull($this->registry->getPageWidget('analytics-series:' . $key));
	}//end testRefusals()
}//end class
