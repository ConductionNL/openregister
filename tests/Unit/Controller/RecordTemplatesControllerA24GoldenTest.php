<?php

/**
 * Golden outcomes of RecordTemplatesController's answer mapping, pinned before a refactor.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\RecordTemplatesController;
use OCA\OpenRegister\Exception\RecordTemplateRefused;
use OCA\OpenRegister\Service\Rbac\ViewerReach;
use OCA\OpenRegister\Service\Rbac\ViewerReachResolver;
use OCA\OpenRegister\Service\RecordTemplate\RecordTemplateService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the exact 401, refusal and 500 bodies.
 */
class RecordTemplatesControllerA24GoldenTest extends TestCase {

	/**
	 * templates.
	 *
	 * @var RecordTemplateService&MockObject
	 */
	private RecordTemplateService&MockObject $templates;

	/**
	 * logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->templates = $this->createMock(RecordTemplateService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Build the controller for a caller.
	 *
	 * @param string $userId The caller, '' for none.
	 *
	 * @return RecordTemplatesController
	 */
	private function controller(string $userId): RecordTemplatesController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn([]);
		$viewers = $this->createMock(ViewerReachResolver::class);
		$viewers->method('currentUid')->willReturn($userId);
		$viewers->method('reachOf')->willReturnCallback(static fn (string $userId): ViewerReach => new ViewerReach(
			userId: $userId,
			groups: [],
			isAdmin: false
		));
		return new RecordTemplatesController('openregister', $request, $this->templates, $viewers, $this->logger);
	}//end controller()

	/**
	 * Unauthenticated body.
	 *
	 * @return void
	 */
	public function testUnauthenticatedBody(): void {
		$this->templates->expects($this->never())->method('get');
		$r = $this->controller('')->show('t-1');
		$this->assertSame([401, ['error' => 'User not authenticated']], [$r->getStatus(), $r->getData()]);
	}

	/**
	 * Refusal bodies.
	 *
	 * @return void
	 */
	public function testRefusalBodies(): void {
		$this->templates->method('get')->willReturnOnConsecutiveCalls(
			$this->throwException(new RecordTemplateRefused(status: 403, message: 'Not yours')),
			$this->throwException(new RecordTemplateRefused(status: 400, message: 'Bad', findings: [['code' => 'x']]))
		);
		$c = $this->controller('anna');
		$r = $c->show('t-1');
		$this->assertSame([403, ['error' => 'Not yours']], [$r->getStatus(), $r->getData()]);
		$r = $c->show('t-1');
		$this->assertSame([400, ['error' => 'Bad', 'findings' => [['code' => 'x']]]], [$r->getStatus(), $r->getData()]);
	}

	/**
	 * Failure body and log.
	 *
	 * @return void
	 */
	public function testFailureBodyAndLog(): void {
		$this->templates->method('get')->willThrowException(new RuntimeException('secret'));
		$this->logger->expects($this->once())->method('error')->with(
			'[RecordTemplatesController] Record template request failed',
			['exception' => 'secret']
		);
		$r = $this->controller('anna')->show('t-1');
		$this->assertSame([500, ['error' => 'The record template request failed.']], [$r->getStatus(), $r->getData()]);
	}
}//end class
