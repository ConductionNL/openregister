<?php

/**
 * Golden outcomes of {@see \OCA\OpenRegister\Controller\ObjectShareLinkController}.
 *
 * Pins the one allowed answer and every refusal of the anonymous token
 * endpoint: which tokens are honoured, that every refusal has one shape, that
 * only a rejected token is counted by the throttler, and that the object is
 * loaded only after core validated the token.
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
 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/share-links-and-email-invites/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ObjectShareLinkController;
use OCA\OpenRegister\Controller\Support\ObjectShareLinkControllerServices;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\AccessLinkReaderInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Tests\Unit\Support\FindCallShape;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\Node;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden outcomes of the anonymous token endpoint.
 */
class ObjectShareLinkControllerA24GoldenTest extends TestCase {

	private const UUID = '11111111-1111-4111-8111-111111111111';

	/**
	 * The request parameters.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * Share manager mock.
	 *
	 * @var IManager&MockObject
	 */
	private IManager&MockObject $shares;

	/**
	 * Object loader mock.
	 *
	 * @var ObjectOperationsInterface&MockObject
	 */
	private ObjectOperationsInterface&MockObject $objects;

	/**
	 * Throttler mock.
	 *
	 * @var IThrottler&MockObject
	 */
	private IThrottler&MockObject $throttler;

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->shares = $this->createMock(IManager::class);
		$this->objects = $this->createMock(ObjectOperationsInterface::class);
		$this->throttler = $this->createMock(IThrottler::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function controller(): ObjectShareLinkController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $name, mixed $default = null): mixed => ($this->params[$name] ?? $default)
		);
		$request->method('getRemoteAddress')->willReturn('10.0.0.9');
		$reader = $this->createMock(AccessLinkReaderInterface::class);
		$reader->method('publish')->willReturn(['id' => self::UUID, 'title' => 'T']);

		return new ObjectShareLinkController(
			appName: 'openregister',
			request: $request,
			services: new ObjectShareLinkControllerServices(
				shareManager: $this->shares,
				objectService: $this->objects,
				reader: $reader,
				throttler: $this->throttler
			),
			logger: $this->logger
		);
	}

	private function share(
		int $type = IShare::TYPE_LINK,
		?string $password = null,
		string $nodeType = 'folder',
		string $name = self::UUID
	): IShare&MockObject {
		$node = $this->createMock(Node::class);
		$node->method('getName')->willReturn($name);
		$share = $this->createMock(IShare::class);
		$share->method('getShareType')->willReturn($type);
		$share->method('getPassword')->willReturn($password);
		$share->method('getNodeType')->willReturn($nodeType);
		$share->method('getNode')->willReturn($node);
		$share->method('getPermissions')->willReturn(17);

		return $share;
	}

	private function assertRefused(JSONResponse $response): void {
		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['message' => 'No such share'], $response->getData());
	}

	private function expectThrottled(int $times): void {
		$this->throttler->expects($this->exactly($times))->method('registerAttempt')
			->with(ObjectShareLinkController::THROTTLE_ACTION, '10.0.0.9');
	}

	public function testLiveLinkResolvesWithRbacOff(): void {
		$this->expectThrottled(0);
		$this->shares->method('getShareByToken')->with('tok')->willReturn($this->share());
		$this->objects->expects($this->once())->method('find')->with(...FindCallShape::findArgs(self::UUID, [], false, null, null, false, false))
			->willReturn(new ObjectEntity());

		$response = $this->controller()->show('tok');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['object' => ['id' => self::UUID, 'title' => 'T'], 'permissions' => 17], $response->getData());
	}

	public function testEmailShareWithMatchingPasswordResolves(): void {
		$this->expectThrottled(0);
		$share = $this->share(IShare::TYPE_EMAIL, 'hash');
		$this->params = ['password' => 'secret'];
		$this->shares->method('getShareByToken')->willReturn($share);
		$this->shares->expects($this->once())->method('checkPassword')->with($share, 'secret')->willReturn(true);
		$this->objects->method('find')->willReturn(new ObjectEntity());

		$this->assertSame(200, $this->controller()->show('tok')->getStatus());
	}

	public function testBlankTokenIsRefusedWithoutAskingCore(): void {
		$this->expectThrottled(1);
		$this->shares->expects($this->never())->method('getShareByToken');
		$this->objects->expects($this->never())->method('find');

		$this->assertRefused($this->controller()->show('  '));
	}

	public function testUnknownTokenIsRefusedAndCounted(): void {
		$this->expectThrottled(1);
		$this->shares->method('getShareByToken')->willThrowException(new \RuntimeException('not found'));
		$this->objects->expects($this->never())->method('find');

		$this->assertRefused($this->controller()->show('tok'));
	}

	public function testPrincipalShareIsRefusedAndCounted(): void {
		$this->expectThrottled(1);
		$this->shares->method('getShareByToken')->willReturn($this->share(IShare::TYPE_USER));
		$this->objects->expects($this->never())->method('find');

		$this->assertRefused($this->controller()->show('tok'));
	}

	public function testPasswordRefusals(): void {
		$this->expectThrottled(4);
		$this->objects->expects($this->never())->method('find');
		$this->shares->method('getShareByToken')->willReturn($this->share(IShare::TYPE_LINK, 'hash'));
		$this->shares->method('checkPassword')->willReturnOnConsecutiveCalls(
			false,
			$this->throwException(new \RuntimeException('hash'))
		);
		$controller = $this->controller();

		$this->assertRefused($controller->show('tok'));
		$this->params = ['password' => ''];
		$this->assertRefused($controller->show('tok'));
		$this->params = ['password' => 'wrong'];
		$this->assertRefused($controller->show('tok'));
		$this->assertRefused($controller->show('tok'));
	}

	public function testThrottlerFailureIsLoggedAndStillRefused(): void {
		$this->shares->method('getShareByToken')->willThrowException(new \RuntimeException('not found'));
		$this->throttler->method('registerAttempt')->willThrowException(new \RuntimeException('cache'));
		$this->logger->expects($this->once())->method('warning')
			->with('ObjectShareLinkController: registerAttempt failed: cache');

		$this->assertRefused($this->controller()->show('tok'));
	}

	public function testShareOfNoObjectIsRefusedUncounted(): void {
		$this->expectThrottled(0);
		$this->objects->expects($this->never())->method('find');
		$broken = $this->createMock(IShare::class);
		$broken->method('getShareType')->willReturn(IShare::TYPE_LINK);
		$broken->method('getPassword')->willReturn(null);
		$broken->method('getNodeType')->willThrowException(new \RuntimeException('node'));
		$this->shares->method('getShareByToken')->willReturnOnConsecutiveCalls(
			$this->share(IShare::TYPE_LINK, null, 'file'),
			$this->share(IShare::TYPE_LINK, null, 'folder', 'not-a-uuid'),
			$broken
		);
		$controller = $this->controller();

		$this->assertRefused($controller->show('tok'));
		$this->assertRefused($controller->show('tok'));
		$this->assertRefused($controller->show('tok'));
	}

	public function testMissingOrUnloadableObjectIsRefusedUncounted(): void {
		$this->expectThrottled(0);
		$this->shares->method('getShareByToken')->willReturn($this->share());
		$this->objects->method('find')->willReturnOnConsecutiveCalls(
			null,
			$this->throwException(new \RuntimeException('db'))
		);
		$this->logger->expects($this->once())->method('warning')->with(
			'[ObjectShareLink] Could not load the object a live token addressed',
			$this->callback(fn (array $context): bool => $context['error'] === 'db')
		);
		$controller = $this->controller();

		$this->assertRefused($controller->show('tok'));
		$this->assertRefused($controller->show('tok'));
	}
}
