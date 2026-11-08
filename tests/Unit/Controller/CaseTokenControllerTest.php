<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Controller
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\CaseTokenController;
use OCA\OpenRegister\Service\CaseTokenServiceInterface;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the allowed and refused answers of the public case-token endpoint.
 */
class CaseTokenControllerTest extends TestCase {

	/**
	 * The token resolver.
	 *
	 * @var CaseTokenServiceInterface&MockObject
	 */
	private CaseTokenServiceInterface&MockObject $tokens;

	/**
	 * The brute-force throttler.
	 *
	 * @var IThrottler&MockObject
	 */
	private IThrottler&MockObject $throttler;

	/**
	 * System under test.
	 *
	 * @var CaseTokenController
	 */
	private CaseTokenController $controller;

	/**
	 * Build the controller over doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->tokens = $this->createMock(CaseTokenServiceInterface::class);
		$this->throttler = $this->createMock(IThrottler::class);
		$request = $this->createMock(IRequest::class);
		$request->method('getRemoteAddress')->willReturn('192.0.2.7');
		$this->controller = new CaseTokenController(
			$request,
			$this->tokens,
			$this->throttler,
			$this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * A token that resolves answers 200 with what it resolves to and registers no attempt.
	 *
	 * @return void
	 */
	public function testAResolvedTokenIsAnswered(): void {
		$this->tokens->method('resolve')->with('good')->willReturn(['case' => 'c-1']);
		$this->throttler->expects($this->never())->method('registerAttempt');
		$response = $this->controller->resolve('good');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['case' => 'c-1'], $response->getData());
	}//end testAResolvedTokenIsAnswered()

	/**
	 * An unresolved token answers a uniform 404 and registers a throttled attempt from the caller's address.
	 *
	 * @return void
	 */
	public function testAnUnresolvedTokenIsRefusedAndThrottled(): void {
		$this->tokens->method('resolve')->willReturn(null);
		$this->throttler->expects($this->once())->method('registerAttempt')
			->with(CaseTokenController::THROTTLE_ACTION, '192.0.2.7');
		$response = $this->controller->resolve('bad');
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['message' => 'Not Found'], $response->getData());
	}//end testAnUnresolvedTokenIsRefusedAndThrottled()

	/**
	 * A throttler failure still refuses with the same 404.
	 *
	 * @return void
	 */
	public function testAThrottlerFailureStillRefuses(): void {
		$this->tokens->method('resolve')->willReturn(null);
		$this->throttler->method('registerAttempt')->willThrowException(new \RuntimeException('down'));
		$response = $this->controller->resolve('bad');
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testAThrottlerFailureStillRefuses()
}//end class
