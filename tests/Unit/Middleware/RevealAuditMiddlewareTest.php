<?php

/**
 * The reveal audit flush around every controller call.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Middleware
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Middleware;

use OCA\OpenRegister\Middleware\RevealAuditMiddleware;
use OCA\OpenRegister\Service\Rbac\RevealFlusherInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pending reveals are flushed after a controller succeeds and after it throws, and a failing
 * flush never changes the response or the exception.
 *
 * @covers \OCA\OpenRegister\Middleware\RevealAuditMiddleware
 */
final class RevealAuditMiddlewareTest extends TestCase {

	private RevealFlusherInterface&MockObject $flusher;

	private LoggerInterface&MockObject $logger;

	private RevealAuditMiddleware $middleware;

	/**
	 * Fresh collaborators per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->flusher = $this->createMock(RevealFlusherInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->middleware = new RevealAuditMiddleware(flusher: $this->flusher, logger: $this->logger);
	}//end setUp()

	/**
	 * After a controller returns, reveals are flushed and the same response goes out.
	 *
	 * @return void
	 */
	public function testAfterTheControllerRevealsAreFlushedAndTheResponseKept(): void {
		$response = new DataResponse(['ok' => true]);
		$this->flusher->expects($this->once())->method('flush');
		$this->logger->expects($this->never())->method('error');

		$this->assertSame($response, $this->middleware->afterController($this->createMock(Controller::class), 'show', $response));
	}//end testAfterTheControllerRevealsAreFlushedAndTheResponseKept()

	/**
	 * A failing flush is logged and the response still goes out.
	 *
	 * @return void
	 */
	public function testAFailingFlushIsLoggedAndTheResponseKept(): void {
		$response = new DataResponse([]);
		$this->flusher->method('flush')->willThrowException(new RuntimeException('audit db down'));
		$this->logger->expects($this->once())->method('error')->with(
			'[RevealAuditMiddleware] The reveal flush failed; the request is unaffected',
			$this->callback(static fn (array $c): bool => $c['exception'] === 'audit db down')
		);

		$this->assertSame($response, $this->middleware->afterController($this->createMock(Controller::class), 'show', $response));
	}//end testAFailingFlushIsLoggedAndTheResponseKept()

	/**
	 * After a controller throws, reveals are still flushed and the same exception is re-thrown.
	 *
	 * @return void
	 */
	public function testAfterAnExceptionRevealsAreFlushedAndItIsRethrown(): void {
		$failure = new RuntimeException('controller failed');
		$this->flusher->expects($this->once())->method('flush');

		try {
			$this->middleware->afterException($this->createMock(Controller::class), 'show', $failure);
			$this->fail('The exception was swallowed.');
		} catch (RuntimeException $thrown) {
			$this->assertSame($failure, $thrown);
		}
	}//end testAfterAnExceptionRevealsAreFlushedAndItIsRethrown()

	/**
	 * A failing flush after an exception does not replace that exception.
	 *
	 * @return void
	 */
	public function testAFailingFlushAfterAnExceptionKeepsTheOriginal(): void {
		$failure = new RuntimeException('controller failed');
		$this->flusher->method('flush')->willThrowException(new RuntimeException('flush failed'));
		$this->logger->expects($this->once())->method('error');

		$this->expectExceptionObject($failure);
		$this->middleware->afterException($this->createMock(Controller::class), 'show', $failure);
	}//end testAFailingFlushAfterAnExceptionKeepsTheOriginal()
}//end class
