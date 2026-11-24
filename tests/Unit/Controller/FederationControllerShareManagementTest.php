<?php

/**
 * Listing, creating, revoking and renewing federation shares answer what the share service answers, and
 * map each service failure to its status: 400 for bad input, 403 for a refusal, 404 for an unknown share,
 * 500 (logged, without the internal message) for anything else.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Controller\FederationController;
use OCA\OpenRegister\Controller\Support\FederationControllerServices;
use OCA\OpenRegister\Db\FederatedShare;
use OCA\OpenRegister\Db\FederatedShareMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Kernel\Contracts\Value\BulkBatchDefaults;
use OCA\OpenRegister\Kernel\Contracts\Value\BulkSaveOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\SaveActor;
use OCA\OpenRegister\Kernel\Contracts\Value\SavePolicy;
use OCA\OpenRegister\Service\FederationShareService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\FederationControllerFactory;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * An instance administrator managing outgoing shares.
 */
#[CoversClass(FederationController::class)]
#[UsesClass(FederationControllerServices::class)]
#[UsesClass(BulkBatchDefaults::class)]
#[UsesClass(BulkSaveOptions::class)]
#[UsesClass(ObjectFindTarget::class)]
#[UsesClass(ObjectSaveOptions::class)]
#[UsesClass(SaveActor::class)]
#[UsesClass(SavePolicy::class)]
final class FederationControllerShareManagementTest extends TestCase {
	/** @var FederationShareService&MockObject */
	private FederationShareService $shares;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/**
	 * Fresh collaborators per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->shares = $this->createMock(FederationShareService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	/**
	 * The controller for the given request params.
	 *
	 * @param array<string, mixed> $params The request params.
	 *
	 * @return FederationController
	 */
	private function controller(array $params=[]): FederationController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default=null): mixed => ($params[$key] ?? $default)
		);

		return FederationControllerFactory::create(
			'openregister',
			$request,
			$this->createMock(FederatedShareMapper::class),
			$this->createMock(ObjectService::class),
			$this->shares,
			$this->createMock(IThrottler::class),
			$this->logger
		);
	}

	/**
	 * The listing passes the direction as a string and counts the shares.
	 *
	 * @return void
	 */
	public function testTheListingPassesTheDirectionAndCounts(): void {
		$this->shares->expects($this->once())
			->method('listShares')
			->with('outgoing')
			->willReturn([['id' => 1], ['id' => 2]]);

		$response = $this->controller(['direction' => 'outgoing'])->shares();

		$this->assertSame(['results' => [['id' => 1], ['id' => 2]], 'total' => 2], $response->getData());
	}

	/**
	 * Without a direction every share is listed.
	 *
	 * @return void
	 */
	public function testTheListingWithoutADirectionAsksForAll(): void {
		$this->shares->expects($this->once())->method('listShares')->with(null)->willReturn([]);

		$response = $this->controller()->shares();

		$this->assertSame(['results' => [], 'total' => 0], $response->getData());
	}

	/**
	 * A create passes every share parameter, defaulting scope to schema and permissions to read, and answers 201.
	 *
	 * @return void
	 */
	public function testACreatePassesTheParametersWithDefaultsAndAnswers201(): void {
		$share = $this->createMock(FederatedShare::class);
		$this->shares->expects($this->once())
			->method('createOutgoingShare')
			->with(
				[
					'scope' => 'schema',
					'register' => '3',
					'schema' => '9',
					'objectUri' => null,
					'queryFilter' => null,
					'permissions' => 'read',
					'sharedWith' => 'gemeente-b',
					'remoteInstanceUrl' => 'https://b.example.org',
					'crosses' => null,
				]
			)
			->willReturn($share);

		$response = $this->controller(
			['register' => '3', 'schema' => '9', 'sharedWith' => 'gemeente-b', 'remoteInstanceUrl' => 'https://b.example.org']
		)->createShare();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame($share, $response->getData());
	}

	/**
	 * The create failures, each with its status and the body the caller sees.
	 *
	 * @return array<string, array{Throwable, int, string}>
	 */
	public static function createFailures(): array {
		return [
			'bad input' => [new InvalidArgumentException('A schema is required'), Http::STATUS_BAD_REQUEST, 'A schema is required'],
			'refused' => [new NotAuthorizedException('Not your register'), Http::STATUS_FORBIDDEN, 'Not your register'],
			'anything else' => [new RuntimeException('db down'), Http::STATUS_INTERNAL_SERVER_ERROR, 'Could not create share'],
		];
	}

	/**
	 * A failed create answers its status; only the unexpected failure is logged and its message is hidden.
	 *
	 * @param Throwable $failure The service's failure.
	 * @param int       $status  The expected status.
	 * @param string    $error   The expected error text.
	 *
	 * @return void
	 */
	#[DataProvider('createFailures')]
	public function testAFailedCreateAnswersItsStatus(Throwable $failure, int $status, string $error): void {
		$this->shares->method('createOutgoingShare')->willThrowException($failure);
		$logged = $this->never();
		if ($status === Http::STATUS_INTERNAL_SERVER_ERROR) {
			$logged = $this->once();
		}

		$this->logger->expects($logged)
			->method('error')
			->with('[Federation] create share failed: db down');

		$response = $this->controller(['scope' => 'object'])->createShare();

		$this->assertSame($status, $response->getStatus());
		$this->assertSame(['error' => $error], $response->getData());
	}

	/**
	 * A revoke sets the share's status to revoked and answers the share.
	 *
	 * @return void
	 */
	public function testARevokeSetsTheStatusToRevoked(): void {
		$share = $this->createMock(FederatedShare::class);
		$this->shares->expects($this->once())->method('setStatus')->with(4, 'revoked')->willReturn($share);

		$response = $this->controller()->revokeShare(4);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($share, $response->getData());
	}

	/**
	 * The revoke failures, each with its status and the body the caller sees.
	 *
	 * @return array<string, array{Throwable, int, string}>
	 */
	public static function revokeFailures(): array {
		return [
			'unknown' => [new DoesNotExistException('x'), Http::STATUS_NOT_FOUND, 'Not found'],
			'refused' => [new NotAuthorizedException('Only the creator may revoke'), Http::STATUS_FORBIDDEN, 'Only the creator may revoke'],
			'anything else' => [new RuntimeException('db down'), Http::STATUS_INTERNAL_SERVER_ERROR, 'Could not revoke share'],
		];
	}

	/**
	 * A failed revoke answers its status.
	 *
	 * @param Throwable $failure The service's failure.
	 * @param int       $status  The expected status.
	 * @param string    $error   The expected error text.
	 *
	 * @return void
	 */
	#[DataProvider('revokeFailures')]
	public function testAFailedRevokeAnswersItsStatus(Throwable $failure, int $status, string $error): void {
		$this->shares->method('setStatus')->willThrowException($failure);

		$response = $this->controller()->revokeShare(4);

		$this->assertSame($status, $response->getStatus());
		$this->assertSame(['error' => $error], $response->getData());
	}

	/**
	 * An unexpected renewal failure is logged and answered as a 500 without the internal message.
	 *
	 * @return void
	 */
	public function testAnUnexpectedRenewalFailureIs500(): void {
		$this->shares->method('renewShare')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('error')->with('[Federation] renew share failed: db down');

		$response = $this->controller()->renewShare(4);

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Could not renew share'], $response->getData());
	}
}
