<?php

/**
 * Unit test for GdprRegisterReplies.
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

use InvalidArgumentException;
use OCA\OpenRegister\Controller\Support\GdprRegisterReplies;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The error replies of the GDPR register endpoints, and the write guard.
 *
 * @covers \OCA\OpenRegister\Controller\Support\GdprRegisterReplies
 */
class GdprRegisterRepliesTest extends TestCase {
	/**
	 * The replies under test.
	 *
	 * @var GdprRegisterReplies
	 */
	private GdprRegisterReplies $replies;

	protected function setUp(): void {
		$this->replies = new GdprRegisterReplies();
	}

	public function testUnauthorizedIs401(): void {
		$response = $this->replies->unauthorized();

		$this->assertSame(401, $response->getStatus());
		$this->assertSame(['error' => 'Authentication required'], $response->getData());
	}

	public function testForbiddenCarriesTheMessage(): void {
		$response = $this->replies->forbidden('No access');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['error' => 'No access'], $response->getData());
	}

	public function testNotFoundNamesTheIdentifier(): void {
		$response = $this->replies->notFound('Activity not found', 'va-1');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'Activity not found', 'identifier' => 'va-1'], $response->getData());
	}

	public function testUnprocessableIs422(): void {
		$response = $this->replies->unprocessable('Bad legal basis');

		$this->assertSame(422, $response->getStatus());
		$this->assertSame(['error' => 'Bad legal basis'], $response->getData());
	}

	public function testAttemptReturnsTheWriteResult(): void {
		$this->assertSame(['saved' => true], $this->replies->attempt(static fn (): array => ['saved' => true], InvalidArgumentException::class));
	}

	public function testAttemptTurnsTheCaughtTypeInto422(): void {
		$response = $this->replies->attempt(
			static fn () => throw new InvalidArgumentException('Unknown status'),
			InvalidArgumentException::class
		);

		$this->assertSame(422, $response->getStatus());
		$this->assertSame(['error' => 'Unknown status'], $response->getData());
	}

	public function testAttemptRethrowsAnyOtherType(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('db down');

		$this->replies->attempt(static fn () => throw new RuntimeException('db down'), InvalidArgumentException::class);
	}
}
