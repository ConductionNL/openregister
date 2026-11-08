<?php

/**
 * Characterisation of how the merged feed gathers its sources: which rows are
 * handed in, how the two fetched sources degrade, and in which order the
 * degradations are named and logged.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
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
 * @spec openspec/changes/activity-leaf/specs/integration-activity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Audit\ObjectHistoryForReaders;
use OCA\OpenRegister\Service\Integration\ActivityFeedMerge;
use OCA\OpenRegister\Service\Integration\ActivityFeedService;
use OCA\OpenRegister\Service\Integration\Providers\ActivityProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The feed's source gathering, pinned.
 *
 * @spec openspec/changes/activity-leaf/specs/integration-activity/spec.md
 */
class ActivityFeedServiceSourcesGoldenTest extends TestCase {

	/**
	 * What the doubles were asked and what was logged.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $calls = [];

	/**
	 * The service over a history and an Activity provider that answer or fail.
	 *
	 * @param bool $historyFails  Whether the history read throws.
	 * @param bool $activityFails Whether the Activity read throws.
	 *
	 * @return ActivityFeedService The service.
	 */
	private function service(bool $historyFails, bool $activityFails): ActivityFeedService {
		$history = $this->getMockBuilder(ObjectHistoryForReaders::class)
			->disableOriginalConstructor()
			->onlyMethods(['page'])
			->getMock();
		$history->method('page')->willReturnCallback(
			function (string $register, string $schema, string $id) use ($historyFails): array {
				$this->calls[] = ['history', $register, $schema, $id];
				if ($historyFails === true) {
					throw new RuntimeException('trail down');
				}

				return ['results' => [], 'total' => 0];
			}
		);

		$provider = $this->getMockBuilder(ActivityProvider::class)
			->disableOriginalConstructor()
			->onlyMethods(['list'])
			->getMock();
		$provider->method('list')->willReturnCallback(
			function (string $register, string $schema, string $objectId, array $filters = []) use ($activityFails): array {
				$this->calls[] = ['activity', $register, $schema, $objectId, $filters];
				if ($activityFails === true) {
					throw new RuntimeException('activity down');
				}

				return [['id' => 'act1', 'timestamp' => 300, 'affecteduser' => 'bob', 'subject' => 'gedeeld']];
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->calls[] = ['warning', $message, $context];
			}
		);

		return new ActivityFeedService(new ActivityFeedMerge(), $history, $provider, $logger);
	}//end service()

	public function testBothSourcesAreReadInOrderForThisObject(): void {
		$page = $this->service(false, false)->page('dossiq', 'case', 'obj-1');

		$this->assertSame(
			[
				['history', 'dossiq', 'case', 'obj-1'],
				['activity', 'dossiq', 'case', 'obj-1', []],
			],
			$this->calls
		);
		$this->assertSame(['activity'], array_column($page['rows'], 'kind'));
		$this->assertSame([], $page['degraded']);
	}//end testBothSourcesAreReadInOrderForThisObject()

	public function testAnUnreadableActivitySourceIsNamedAndLogged(): void {
		$page = $this->service(false, true)->page('dossiq', 'case', 'obj-1');

		$this->assertSame([], $page['rows']);
		$this->assertSame(['activity'], $page['degraded']);
		$this->assertSame(
			[
				'warning',
				'[ActivityFeedService] the Activity rows could not be read for the merged feed',
				['objectId' => 'obj-1', 'exception' => 'activity down'],
			],
			$this->calls[2]
		);
	}//end testAnUnreadableActivitySourceIsNamedAndLogged()

	public function testTwoUnreadableSourcesAreNamedAuditFirst(): void {
		$page = $this->service(true, true)->page('dossiq', 'case', 'obj-1');

		$this->assertSame(['audit', 'activity'], $page['degraded']);
		$this->assertSame(
			[
				['history', 'dossiq', 'case', 'obj-1'],
				[
					'warning',
					'[ActivityFeedService] the audit trail could not be read for the merged feed',
					['objectId' => 'obj-1', 'exception' => 'trail down'],
				],
				['activity', 'dossiq', 'case', 'obj-1', []],
				[
					'warning',
					'[ActivityFeedService] the Activity rows could not be read for the merged feed',
					['objectId' => 'obj-1', 'exception' => 'activity down'],
				],
			],
			$this->calls
		);
	}//end testTwoUnreadableSourcesAreNamedAuditFirst()

	public function testOnlyArrayRowsOfTheThreeHandedInKindsAreMerged(): void {
		$page = $this->service(false, true)->page(
			'dossiq',
			'case',
			'obj-1',
			[],
			[
				'mail' => 'not rows',
				'note' => [['id' => 'n1', 'timestamp' => 400, 'summary' => 'gebeld']],
				'other' => [['id' => 'x1', 'timestamp' => 500, 'summary' => 'ignored']],
			]
		);

		$this->assertSame(['note'], array_column($page['rows'], 'kind'));
		$this->assertSame(['activity'], $page['degraded']);
	}//end testOnlyArrayRowsOfTheThreeHandedInKindsAreMerged()
	public function testAHistoryThatAnswersNothingGivesNoAuditRowsAndNoDegradedSource(): void {
		$history = $this->getMockBuilder(ObjectHistoryForReaders::class)
			->disableOriginalConstructor()
			->onlyMethods(['page'])
			->getMock();
		$history->method('page')->willReturn(null);
		$provider = $this->getMockBuilder(ActivityProvider::class)
			->disableOriginalConstructor()
			->onlyMethods(['list'])
			->getMock();
		$provider->method('list')->willReturn([]);
		$service = new ActivityFeedService(new ActivityFeedMerge(), $history, $provider, $this->createMock(LoggerInterface::class));

		$page = $service->page('dossiq', 'case', 'obj-1');

		$this->assertSame([], $page['rows']);
		$this->assertSame([], $page['degraded']);
	}//end testAHistoryThatAnswersNothingGivesNoAuditRowsAndNoDegradedSource()
}//end class
