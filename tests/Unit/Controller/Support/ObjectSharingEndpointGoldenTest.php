<?php

/**
 * Golden test for ObjectSharingEndpoint: the resolve and refusal outcomes.
 *
 * Pins, on the code before the round 12 refactor, which object a route
 * resolves to and when it is refused with 404 (unreadable or missing object),
 * and how an operation's failure is answered: 403 for a refusal, 400 for a
 * rejected input only when the operation validates, and a logged generic 500
 * for everything else, whose message is never the exception's.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Controller\Support
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use InvalidArgumentException;
use OCA\OpenRegister\Controller\Support\ObjectSharingEndpoint;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class ObjectSharingEndpointGoldenTest extends TestCase {

	/** @var ObjectOperationsInterface&MockObject The RBAC-bounded resolver. */
	private ObjectOperationsInterface $objects;

	/** @var LoggerInterface&MockObject The failure log. */
	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectOperationsInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function endpoint(): ObjectSharingEndpoint {
		return new ObjectSharingEndpoint($this->objects, $this->logger, '/app/lib/Controller/FooController.php', 'Generic failure');
	}

	public function testResolveAimsAtTheRouteAndReturnsTheReadableObject(): void {
		$object = new ObjectEntity();
		$this->objects->expects($this->once())->method('setRegister')->with('reg');
		$this->objects->expects($this->once())->method('setSchema')->with('sch');
		$this->objects->expects($this->once())->method('setObject')->with('uuid-1');
		$this->objects->method('getObject')->willReturn($object);

		$this->assertSame($object, $this->endpoint()->resolve(new ObjectRef('reg', 'sch', 'uuid-1')));
	}

	public function testResolveRefusesAnUnreadableObjectWith404(): void {
		$this->objects->method('getObject')->willThrowException(new NotAuthorizedException());

		$this->assertNotFound($this->endpoint()->resolve(new ObjectRef('reg', 'sch', 'uuid-1')));
	}

	public function testResolveRefusesAMissingObjectWith404(): void {
		$this->objects->method('getObject')->willReturn(null);

		$this->assertNotFound($this->endpoint()->resolve(new ObjectRef('reg', 'sch', 'uuid-1')));
	}

	public function testResolveLetsAnUnknownRegisterPropagate(): void {
		$this->objects->method('setRegister')->willThrowException(new RuntimeException('no register'));
		$this->objects->expects($this->never())->method('getObject');

		$this->expectException(RuntimeException::class);
		$this->endpoint()->resolve(new ObjectRef('reg', 'sch', 'uuid-1'));
	}

	public function testRunReturnsTheOperationResult(): void {
		$this->logger->expects($this->never())->method('error');

		$this->assertSame(['ok' => true], $this->endpoint()->run(fn (): array => ['ok' => true], 'ctx'));
	}

	public function testRunAnswersARefusalWith403AndItsMessage(): void {
		$result = $this->endpoint()->runValidated(static fn () => throw new NotAuthorizedException('Not yours'), 'ctx');

		$this->assertAnswer($result, 403, 'Not yours');
	}

	public function testRunAnswersARejectedInputWith400WhenTheOperationValidates(): void {
		$this->logger->expects($this->never())->method('error');

		$result = $this->endpoint()->runValidated(static fn () => throw new InvalidArgumentException('Bad owner'), 'ctx');

		$this->assertAnswer($result, 400, 'Bad owner');
	}

	public function testRunLogsARejectedInputAsUnexpectedWhenTheOperationDoesNotValidate(): void {
		$this->logger->expects($this->once())->method('error')
			->with(
				'[FooController] ctx: Bad owner',
				$this->callback(static fn (array $context): bool => $context['file'] === '/app/lib/Controller/FooController.php')
			);

		$result = $this->endpoint()->run(static fn () => throw new InvalidArgumentException('Bad owner'), 'ctx');

		$this->assertAnswer($result, 500, 'Generic failure');
	}

	public function testRunHidesAnyOtherFailureBehindTheGeneric500(): void {
		$this->logger->expects($this->once())->method('error');

		$result = $this->endpoint()->runValidated(static fn () => throw new RuntimeException('/var/data/secret path'), 'ctx');

		$this->assertAnswer($result, 500, 'Generic failure');
	}

	public function testAnswerWrapsTheResultWithTheGivenStatus(): void {
		$response = $this->endpoint()->answerCreated(fn (): array => ['id' => 7], 'ctx');

		$this->assertSame(201, $response->getStatus());
		$this->assertSame(['id' => 7], $response->getData());
	}

	public function testAnswerPassesARefusalThrough(): void {
		$response = $this->endpoint()->answer(static fn () => throw new NotAuthorizedException('Not yours'), 'ctx');

		$this->assertAnswer($response, 403, 'Not yours');
	}

	private function assertNotFound(mixed $result): void {
		$this->assertAnswer($result, 404, 'Object not found');
	}

	private function assertAnswer(mixed $result, int $status, string $message): void {
		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertSame($status, $result->getStatus());
		$this->assertSame(['message' => $message], $result->getData());
	}
}
