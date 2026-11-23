<?php

/**
 * Unit test for UserResponses.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\Support\UserResponses;
use OCA\OpenRegister\Service\SecurityHeadersInterface;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Every user-route response passes the security headers; runtime errors keep their code.
 *
 * @covers \OCA\OpenRegister\Controller\Support\UserResponses
 */
class UserResponsesTest extends TestCase {
	/**
	 * The responses under test or its double.
	 *
	 * @var UserResponses
	 */
	private UserResponses $responses;

	protected function setUp(): void {
		$security = $this->createMock(SecurityHeadersInterface::class);
		$security->method('addSecurityHeaders')->willReturnCallback(
			static function (JSONResponse $response): JSONResponse {
				$response->addHeader('X-Secured', 'yes');
				return $response;
			}
		);
		$this->responses = new UserResponses(securityService: $security);
	}

	public function testSecuredWrapsTheDataAndAddsTheHeaders(): void {
		$response = $this->responses->secured(['a' => 1], 201);

		$this->assertSame(['a' => 1], $response->getData());
		$this->assertSame(201, $response->getStatus());
		$this->assertSame('yes', $response->getHeaders()['X-Secured']);
	}

	public function testSecuredDefaultsTo200(): void {
		$this->assertSame(200, $this->responses->secured([])->getStatus());
	}

	public function testErrorIsASecuredErrorBody(): void {
		$response = $this->responses->error('nope', 403);

		$this->assertSame(['error' => 'nope'], $response->getData());
		$this->assertSame(403, $response->getStatus());
		$this->assertSame('yes', $response->getHeaders()['X-Secured']);
	}

	public function testRuntimeStatusIsTheCodeOr500(): void {
		$this->assertSame(409, $this->responses->runtimeStatus(new RuntimeException('x', 409)));
		$this->assertSame(500, $this->responses->runtimeStatus(new RuntimeException('x')));
	}

	public function testRuntimeErrorWrapsTheMessageWhenTheStatusIsNotThePayloadOne(): void {
		$response = $this->responses->runtimeError(new RuntimeException('{"field":"bad"}', 400), 422);

		$this->assertSame(['error' => '{"field":"bad"}'], $response->getData());
		$this->assertSame(400, $response->getStatus());
	}

	public function testRuntimeErrorDecodesAJsonMessageForThePayloadStatus(): void {
		$response = $this->responses->runtimeError(new RuntimeException('{"field":"bad"}', 422), 422);

		$this->assertSame(['field' => 'bad'], $response->getData());
		$this->assertSame(422, $response->getStatus());
	}

	public function testRuntimeErrorFallsBackToTheMessageWhenThePayloadIsNotJson(): void {
		$response = $this->responses->runtimeError(new RuntimeException('plain text', 422), 422);

		$this->assertSame(['error' => 'plain text'], $response->getData());
	}

	public function testRuntimeErrorWithoutACodeIs500(): void {
		$this->assertSame(500, $this->responses->runtimeError(new RuntimeException('crash'))->getStatus());
	}
}
