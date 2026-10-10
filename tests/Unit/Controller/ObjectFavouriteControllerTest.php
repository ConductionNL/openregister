<?php

/**
 * ObjectFavouriteControllerTest: the deprecated star routes.
 *
 * Since merge-follow-and-favourites a star is a quiet follow. Both routes still
 * answer for one release, each with a `Deprecation` header and a `Link` to the
 * follow route that replaces it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/merge-follow-and-favourites/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ObjectFavouriteController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Watcher;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Interaction\FavouriteService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Controller\ObjectFavouriteController
 * @uses \OCA\OpenRegister\Db\Watcher
 * @uses \OCA\OpenRegister\Exception\NotAuthorizedException
 */
class ObjectFavouriteControllerTest extends TestCase {

	/**
	 * The favourite facade double.
	 *
	 * @var FavouriteService&MockObject
	 */
	private FavouriteService&MockObject $favourites;

	/**
	 * The object service double.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objects;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->favourites = $this->createMock(FavouriteService::class);
		$this->objects = $this->createMock(ObjectService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Build the controller.
	 *
	 * @param bool $found Whether the object resolves.
	 *
	 * @return ObjectFavouriteController The controller.
	 */
	private function controller(bool $found = true): ObjectFavouriteController {
		if ($found === true) {
			$this->objects->method('getObject')->willReturn($this->createMock(ObjectEntity::class));
		} else {
			$this->objects->method('getObject')->willThrowException(new RuntimeException('gone'));
		}

		return new ObjectFavouriteController(
			'openregister',
			$this->createMock(IRequest::class),
			$this->objects,
			$this->favourites,
			$this->logger
		);
	}//end controller()

	/**
	 * Starring follows quietly and says the route is deprecated, pointing at the follow route.
	 *
	 * @return void
	 */
	public function testStarIsAQuietFollowWithADeprecationHeader(): void {
		$row = new Watcher();
		$row->setUserId('alice');
		$row->setObjectUuid('uuid-a');
		$row->setNotify(false);
		$this->favourites->expects($this->once())->method('star')
			->with($this->isInstanceOf(ObjectEntity::class), 'my reg', 's')
			->willReturn($row);

		$response = $this->controller()->star('my reg', 's', 'uuid-a');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['favourite']);
		$this->assertTrue($response->getData()['watching']);
		$this->assertFalse($response->getData()['notify']);
		$headers = $response->getHeaders();
		$this->assertSame('true', $headers['Deprecation']);
		$this->assertSame('</index.php/apps/openregister/api/objects/my%20reg/s/uuid-a/watch>; rel="successor-version"', $headers['Link']);
	}//end testStarIsAQuietFollowWithADeprecationHeader()

	/**
	 * Unstarring unfollows, with the same deprecation headers.
	 *
	 * @return void
	 */
	public function testUnstarIsAnUnfollow(): void {
		$this->favourites->expects($this->once())->method('unstar')->willReturn(true);

		$response = $this->controller()->unstar('r', 's', 'uuid-a');

		$this->assertSame(['favourite' => false, 'watching' => false, 'removed' => true], $response->getData());
		$this->assertSame('true', $response->getHeaders()['Deprecation']);
	}//end testUnstarIsAnUnfollow()

	/**
	 * An object that does not resolve is a 404, a non-entity too, and nothing is written.
	 *
	 * @return void
	 */
	public function testAnUnknownObjectIsNotFound(): void {
		$this->favourites->expects($this->never())->method($this->anything());
		$controller = $this->controller(false);

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->star('r', 's', 'x')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->unstar('r', 's', 'x')->getStatus());

		$objects = $this->createMock(ObjectService::class);
		$objects->method('getObject')->willReturn(null);
		$plain = new ObjectFavouriteController('openregister', $this->createMock(IRequest::class), $objects, $this->favourites, $this->logger);
		$this->assertSame(Http::STATUS_NOT_FOUND, $plain->star('r', 's', 'x')->getStatus());
	}//end testAnUnknownObjectIsNotFound()

	/**
	 * A refusal is a 403 with the service's message.
	 *
	 * @return void
	 */
	public function testARefusalIsForbidden(): void {
		$refusal = new NotAuthorizedException('Not yours');
		$this->favourites->method('star')->willThrowException($refusal);
		$this->favourites->method('unstar')->willThrowException($refusal);
		$controller = $this->controller();

		foreach ([$controller->star('r', 's', 'uuid-a'), $controller->unstar('r', 's', 'uuid-a')] as $response) {
			$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
			$this->assertSame('Not yours', $response->getData()['message']);
		}
	}//end testARefusalIsForbidden()

	/**
	 * Anything else is a logged 500.
	 *
	 * @return void
	 */
	public function testAnUnexpectedFailureIsALoggedServerError(): void {
		$boom = new RuntimeException('database down');
		$this->favourites->method('star')->willThrowException($boom);
		$this->favourites->method('unstar')->willThrowException($boom);
		$this->logger->expects($this->exactly(2))->method('error');
		$controller = $this->controller();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $controller->star('r', 's', 'uuid-a')->getStatus());
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $controller->unstar('r', 's', 'uuid-a')->getStatus());
	}//end testAnUnexpectedFailureIsALoggedServerError()
}//end class
