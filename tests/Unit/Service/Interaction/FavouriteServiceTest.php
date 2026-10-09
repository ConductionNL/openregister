<?php

/**
 * Unit tests for FavouriteService, the star's deprecated facade over the follow.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Interaction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/merge-follow-and-favourites/specs/object-interactions/spec.md#requirement-a-user-can-star-an-object-without-changing-it
 */

declare(strict_types=1);

namespace Unit\Service\Interaction;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Watcher;
use OCA\OpenRegister\Service\Interaction\FavouriteService;
use OCA\OpenRegister\Service\Interaction\WatcherService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Every star verb answers through the follow, so the two cannot disagree.
 *
 * @coversDefaultClass \OCA\OpenRegister\Service\Interaction\FavouriteService
 */
class FavouriteServiceTest extends TestCase {

	/**
	 * The follow primitive the facade delegates to.
	 *
	 * @var WatcherService&MockObject
	 */
	private $watchers;

	/**
	 * Build a fresh double for each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->watchers = $this->createMock(originalClassName: WatcherService::class);

	}//end setUp()

	/**
	 * Starring is following quietly, with the addressed scope.
	 *
	 * @return void
	 */
	public function testStarIsAQuietFollow(): void {
		$object = $this->createMock(originalClassName: ObjectEntity::class);
		$this->watchers->expects($this->once())
			->method('followQuietly')
			->with($object, 'zaken', 'zaak')
			->willReturn(new Watcher());

		(new FavouriteService($this->watchers))->star(object: $object, register: 'zaken', schema: 'zaak');

	}//end testStarIsAQuietFollow()

	/**
	 * Unstarring is unfollowing.
	 *
	 * @return void
	 */
	public function testUnstarIsAnUnfollow(): void {
		$object = $this->createMock(originalClassName: ObjectEntity::class);
		$this->watchers->expects($this->once())->method('unwatch')->with($object)->willReturn(true);

		$this->assertTrue(condition: (new FavouriteService($this->watchers))->unstar(object: $object));

	}//end testUnstarIsAnUnfollow()

	/**
	 * "Starred" means "followed", whatever the notify switch says.
	 *
	 * @return void
	 */
	public function testStarredMeansFollowed(): void {
		$this->watchers->method('isWatchedByCaller')->willReturnMap(
			[
				['uuid-case-1', true],
				['uuid-case-2', false],
			]
		);
		$service = new FavouriteService($this->watchers);

		$this->assertTrue(condition: $service->isStarredByCaller(objectUuid: 'uuid-case-1'));
		$this->assertFalse(condition: $service->isStarredByCaller(objectUuid: 'uuid-case-2'));

	}//end testStarredMeansFollowed()
}//end class
