<?php

/**
 * Golden outcomes of {@see \OCA\OpenRegister\Controller\ObjectFavouriteController}.
 *
 * Pins every allowed and refused answer (status, body and the deprecation
 * headers) of the star endpoints, and that a refusal at the RBAC boundary is
 * reached before any favourite row is touched.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/object-interactions/spec.md#requirement-a-user-can-star-an-object-without-changing-it
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ObjectFavouriteController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Notifications\Db\Watcher;
use OCA\OpenRegister\Service\Interaction\AddressedObject;
use OCA\OpenRegister\Service\Interaction\FavouriteServiceInterface;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden outcomes of the deprecated star endpoints.
 */
class ObjectFavouriteControllerA24GoldenTest extends TestCase {

	/**
	 * Favourite service mock.
	 *
	 * @var FavouriteServiceInterface&MockObject
	 */
	private FavouriteServiceInterface&MockObject $favourites;

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->favourites = $this->createMock(FavouriteServiceInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function controller(?ObjectEntity $object, bool $resolverThrows = false): ObjectFavouriteController {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		if ($resolverThrows === true) {
			$objects->method('getObject')->willThrowException(new \RuntimeException('db'));
		} else {
			$objects->method('getObject')->willReturn($object);
		}

		return new ObjectFavouriteController(
			request: $this->createMock(IRequest::class),
			services: new \OCA\OpenRegister\Controller\Support\ObjectFavouriteControllerServices(
				objectService: $objects,
				favourites: $this->favourites,
				logger: $this->logger,
			)
		);
	}

	private function assertOutcome(int $status, mixed $body, JSONResponse $response): void {
		$this->assertSame($status, $response->getStatus());
		$this->assertSame($body, $response->getData());
	}

	private function assertDeprecated(JSONResponse $response): void {
		$headers = $response->getHeaders();
		$this->assertSame('true', $headers['Deprecation']);
		$this->assertSame('</index.php/apps/openregister/api/objects/my%20reg/s%2Fx/id-1/watch>; rel="successor-version"', $headers['Link']);
	}

	private function assertNotDeprecated(JSONResponse $response): void {
		$this->assertArrayNotHasKey('Deprecation', $response->getHeaders());
		$this->assertArrayNotHasKey('Link', $response->getHeaders());
	}

	public function testUnreadableObjectIsNotFoundBeforeAnyRowIsTouched(): void {
		$this->favourites->expects($this->never())->method($this->anything());
		foreach ([$this->controller(null), $this->controller(null, true)] as $controller) {
			$star = $controller->star('my reg', 's/x', 'id-1');
			$this->assertOutcome(404, ['message' => 'Object not found'], $star);
			$this->assertNotDeprecated($star);
			$this->assertOutcome(404, ['message' => 'Object not found'], $controller->unstar('my reg', 's/x', 'id-1'));
		}
	}

	public function testStarOutcomes(): void {
		$watcher = $this->createMock(Watcher::class);
		$watcher->method('jsonSerialize')->willReturn(['id' => 3, 'favourite' => false]);
		$this->favourites->method('star')->with($this->callback(static fn (AddressedObject $target): bool => $target->register === 'my reg' && $target->schema === 's/x'))->willReturnOnConsecutiveCalls(
			$watcher,
			$this->throwException(new NotAuthorizedException('Sign in first')),
			$this->throwException(new \InvalidArgumentException('bad'))
		);
		$controller = $this->controller(new ObjectEntity());

		$star = $controller->star('my reg', 's/x', 'id-1');
		$this->assertOutcome(200, ['id' => 3, 'favourite' => true, 'watching' => true], $star);
		$this->assertDeprecated($star);

		$refused = $controller->star('my reg', 's/x', 'id-1');
		$this->assertOutcome(403, ['message' => 'Sign in first'], $refused);
		$this->assertNotDeprecated($refused);

		$this->logger->expects($this->once())->method('error')->with('[ObjectFavouriteController] star: bad');
		$this->assertOutcome(500, ['message' => 'Could not complete the favourite request'], $controller->star('my reg', 's/x', 'id-1'));
	}

	public function testUnstarOutcomes(): void {
		$this->favourites->method('unstar')->willReturnOnConsecutiveCalls(
			true,
			$this->throwException(new NotAuthorizedException('Sign in first')),
			$this->throwException(new \RuntimeException('boom'))
		);
		$controller = $this->controller(new ObjectEntity());

		$unstar = $controller->unstar('my reg', 's/x', 'id-1');
		$this->assertOutcome(200, ['favourite' => false, 'watching' => false, 'removed' => true], $unstar);
		$this->assertDeprecated($unstar);
		$this->assertOutcome(403, ['message' => 'Sign in first'], $controller->unstar('my reg', 's/x', 'id-1'));
		$this->logger->expects($this->once())->method('error')->with('[ObjectFavouriteController] unstar: boom');
		$this->assertOutcome(500, ['message' => 'Could not complete the favourite request'], $controller->unstar('my reg', 's/x', 'id-1'));
	}
}
