<?php

/**
 * Unit tests for audit-trail rows with no page or an unreadable moment in the activity feed.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

use OCA\OpenRegister\Service\Audit\ObjectHistoryForReaders;
use OCA\OpenRegister\Service\Integration\ActivityFeedMerge;
use OCA\OpenRegister\Service\Integration\ActivityFeedObject;
use OCA\OpenRegister\Service\Integration\ActivityFeedService;
use OCA\OpenRegister\Service\Integration\Providers\ActivityProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * No trail page means no audit rows; an entry whose moment cannot be read sorts as the epoch.
 *
 * @covers \OCA\OpenRegister\Service\Integration\ActivityFeedService
 * @uses   \OCA\OpenRegister\Kernel\Contracts\ObjectRef
 * @uses   \OCA\OpenRegister\Service\Integration\ActivityFeedMerge
 * @uses   \OCA\OpenRegister\Service\Integration\ActivityFeedObject
 * @uses   \OCA\OpenRegister\Service\Integration\ActivityFeedOptions
 * @uses \OCA\OpenRegister\Kernel\Contracts\ObjectRef
 * @uses \OCA\OpenRegister\Service\Integration\ActivityFeedMerge
 * @uses \OCA\OpenRegister\Service\Integration\ActivityFeedObject
 * @uses \OCA\OpenRegister\Service\Integration\ActivityFeedOptions
 */
class ActivityFeedServiceAuditRowsTest extends TestCase {

	/**
	 * A feed over a trail answering the given page and no activity rows.
	 *
	 * @param array<string, mixed>|null $page The trail page.
	 *
	 * @return array<string, mixed> The feed page.
	 */
	private function feed(?array $page): array {
		$history = $this->getMockBuilder(ObjectHistoryForReaders::class)
			->disableOriginalConstructor()
			->onlyMethods(['page'])
			->getMock();
		$history->method('page')->willReturn($page);

		$provider = $this->getMockBuilder(ActivityProvider::class)
			->disableOriginalConstructor()
			->onlyMethods(['list'])
			->getMock();
		$provider->method('list')->willReturn([]);

		return (new ActivityFeedService(new ActivityFeedMerge(), $history, $provider, $this->createMock(LoggerInterface::class)))
			->page(new ActivityFeedObject('5', '7', 'obj-1'));

	}//end feed()

	/**
	 * Without a trail page there are no audit rows and nothing is degraded.
	 *
	 * @return void
	 */
	public function testNoTrailPageMeansNoAuditRows(): void {
		$page = $this->feed(null);

		$this->assertSame([], $page['rows']);
		$this->assertSame([], $page['degraded']);

	}//end testNoTrailPageMeansNoAuditRows()

	/**
	 * An entry with an unreadable moment is kept, at timestamp 0, after the dated one.
	 *
	 * @return void
	 */
	public function testAnUnreadableMomentSortsAsTheEpoch(): void {
		$page = $this->feed(
			[
				'results' => [
					['uuid' => 'a-1', 'action' => 'update', 'created' => 'not a date', 'changed' => 'garbled', 'userName' => 'alice'],
					['uuid' => 'a-2', 'action' => 'create', 'created' => '2026-09-01T10:00:00+00:00', 'user' => 'bob'],
				],
				'total' => 2,
			]
		);

		$this->assertSame(['a-2', 'a-1'], array_column($page['rows'], 'id'));
		$this->assertSame(0, $page['rows'][1]['timestamp']);
		$this->assertSame(['bob', 'alice'], array_column($page['rows'], 'actor'));

	}//end testAnUnreadableMomentSortsAsTheEpoch()
}//end class
