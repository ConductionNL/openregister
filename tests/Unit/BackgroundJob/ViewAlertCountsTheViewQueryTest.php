<?php

/**
 * The view alert sweep counts the VIEW's query, through the real ObjectService.
 *
 * 🔴 LIVE DEFECT (live pass, 5 Oct, O5): a view whose query named register 102
 * and schema 1683 (three live records) alerted at "5" on its first evaluation.
 * The sweep handed the view's query to ObjectService::count(), which reads only
 * `filters` and the service's ambient register/schema context. The sweep never
 * sets that context, so the number was whatever the service last pointed at,
 * here every table on the instance. The old sweep test stubbed count() to 23,
 * so it could not see that count() ignores the query it was given.
 *
 * These tests run the REAL ObjectService (only runAs() is replaced, by one that
 * runs the callable) and read what reaches the mapper.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/saved-view-count-alert/specs/saved-search-views/spec.md#requirement-a-view-alert-fires-once-per-crossing-and-re-arms
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\ViewAlertSweepJob;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\View;
use OCA\OpenRegister\Db\ViewMapper;
use OCA\OpenRegister\Event\ViewAlertCrossedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionNamedType;

class ViewAlertCountsTheViewQueryTest extends TestCase {

	private MagicMapper&MockObject $mapper;

	/**
	 * Queries that reached the mapper's count.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $counted = [];

	/**
	 * Events the pass dispatched.
	 *
	 * @var array<int, Event>
	 */
	private array $dispatched = [];

	/**
	 * Run one sweep over one view, with the real ObjectService.
	 *
	 * @param array<string, mixed> $query The view's stored query.
	 * @param int                  $rows  What the mapper's count of the view's query answers.
	 *
	 * @return void
	 */
	private function sweepOne(array $query, int $rows): void {
		$this->mapper = $this->createMock(MagicMapper::class);
		$this->mapper->method('countSearchObjects')->willReturnCallback(
			function (array $query = []) use ($rows): int {
				$this->counted[] = $query;
				return $rows;
			}
		);
		// The defect's number: every table on the instance, whatever the query said.
		$this->mapper->method('countAll')->willReturn(5);

		$objects = $this->realObjectService();

		$view = new View();
		$view->setUuid('04d8079a-b84a-410e-b5df-75f04a130068');
		$view->setName('livepass keyed count alert');
		$view->setOwner('admin');
		$view->setQuery($query);
		$view->setAlert(['operator' => 'gte', 'threshold' => 4, 'recipients' => ['user:admin'], 'every' => 900]);

		$views = $this->createMock(ViewMapper::class);
		$views->method('findWithAlerts')->willReturn([$view]);

		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(IUser::class));

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event): void {
				$this->dispatched[] = $event;
			}
		);

		$job = new ViewAlertSweepJob($this->createMock(ITimeFactory::class), $views, $objects, $users, $dispatcher, new NullLogger());
		$run = new \ReflectionMethod(ViewAlertSweepJob::class, 'run');
		$run->setAccessible(true);
		$run->invoke($job, null);
	}//end sweepOne()

	/**
	 * The real ObjectService over doubles, with this test's mapper.
	 *
	 * @return ObjectService
	 */
	private function realObjectService(): ObjectService {
		$args = [];
		foreach ((new ReflectionClass(ObjectService::class))->getConstructor()->getParameters() as $param) {
			$type = $param->getType();
			if ($param->isOptional() === true) {
				$args[] = $param->getDefaultValue();
				continue;
			}

			$this->assertInstanceOf(ReflectionNamedType::class, $type);
			if ($type->getName() === MagicMapper::class) {
				$args[] = $this->mapper;
				continue;
			}

			$args[] = $this->createMock($type->getName());
		}

		$service = $this->getMockBuilder(ObjectService::class)
			->setConstructorArgs($args)
			->onlyMethods(['runAs'])
			->getMock();
		$service->method('runAs')->willReturnCallback(
			static function (IUser $user, callable $operation) {
				return $operation();
			}
		);

		return $service;
	}//end realObjectService()

	/**
	 * 🔴 The live case: three records behind the view, no alert at gte 4.
	 *
	 * @return void
	 */
	public function testTheSweepCountsTheRegisterAndSchemaTheViewNames(): void {
		$this->sweepOne(['registers' => [102], 'schemas' => [1683]], 3);

		$this->assertCount(1, $this->counted, 'the view\'s own query must reach the mapper\'s count');
		$this->assertSame([102], $this->counted[0]['@self']['register']);
		$this->assertSame([1683], $this->counted[0]['@self']['schema']);
		$this->assertSame([], $this->dispatched, 'three records is under a threshold of four: no alert');
	}//end testTheSweepCountsTheRegisterAndSchemaTheViewNames()

	/**
	 * The view's filters and search terms bound the count too.
	 *
	 * @return void
	 */
	public function testTheSweepCountsTheViewsFiltersAndSearch(): void {
		$this->sweepOne(
			[
				'registers'    => [102],
				'schemas'      => [1683],
				'searchTerms'  => ['urgent', 'north'],
				'facetFilters' => ['status' => ['open'], '@self.organisation' => ['org-1'], 'colour' => []],
				'filters'      => ['priority' => 'high'],
				'enabledFacets' => ['status' => true],
				'source'       => 'auto',
			],
			7
		);

		$this->assertCount(1, $this->counted);
		$query = $this->counted[0];
		$this->assertSame('urgent north', $query['_search']);
		$this->assertSame(['open'], $query['status']);
		$this->assertSame(['org-1'], $query['@self']['organisation']);
		$this->assertSame('high', $query['priority']);
		$this->assertArrayNotHasKey('colour', $query, 'an empty facet selection is no filter');
		$this->assertArrayNotHasKey('enabledFacets', $query);
		$this->assertArrayNotHasKey('source', $query);

		$this->assertCount(1, $this->dispatched);
		$this->assertInstanceOf(ViewAlertCrossedEvent::class, $this->dispatched[0]);
		$this->assertSame(7, $this->dispatched[0]->getCount());
	}//end testTheSweepCountsTheViewsFiltersAndSearch()

	/**
	 * A query stored in the API's single-id form counts the same table.
	 *
	 * @return void
	 */
	public function testASingleRegisterAndSchemaFormIsCountedToo(): void {
		$this->sweepOne(['_register' => 3, '_schema' => 5], 1);

		$this->assertCount(1, $this->counted);
		$this->assertSame([3], $this->counted[0]['@self']['register']);
		$this->assertSame([5], $this->counted[0]['@self']['schema']);
	}//end testASingleRegisterAndSchemaFormIsCountedToo()

	/**
	 * Two facet filters that exclude each other count nothing, without a query.
	 *
	 * @return void
	 */
	public function testFiltersThatExcludeEachOtherCountZero(): void {
		$this->sweepOne(
			['registers' => [102], 'schemas' => [1683], 'filters' => ['status' => 'closed'], 'facetFilters' => ['status' => ['open']]],
			9
		);

		$this->assertSame([], $this->counted);
		$this->assertSame([], $this->dispatched);
	}//end testFiltersThatExcludeEachOtherCountZero()

	/**
	 * A view that names no register and no schema is not counted as zero.
	 *
	 * The search answers 0 for an unbounded query; storing that would re-arm a
	 * fired alert. The state is left as it was, as for a failed count.
	 *
	 * @return void
	 */
	public function testAViewThatNamesNoRegisterOrSchemaIsNotCounted(): void {
		$this->sweepOne(['searchTerms' => ['urgent']], 0);

		$this->assertSame([], $this->counted);
		$this->assertSame([], $this->dispatched);
	}//end testAViewThatNamesNoRegisterOrSchemaIsNotCounted()
}//end class
