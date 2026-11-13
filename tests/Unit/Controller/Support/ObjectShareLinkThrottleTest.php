<?php

/**
 * Unit test for ObjectShareLinkThrottle.
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

use OCA\OpenRegister\Controller\Support\ObjectShareLinkThrottle;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Counting a rejected share-link attempt against the caller's address.
 *
 * @covers \OCA\OpenRegister\Controller\Support\ObjectShareLinkThrottle
 */
class ObjectShareLinkThrottleTest extends TestCase {
	public function testARejectionIsRegisteredUnderTheAction(): void {
		$throttler = $this->createMock(IThrottler::class);
		$throttler->expects($this->once())->method('registerAttempt')->with('share_link_open', '10.0.0.1');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		(new ObjectShareLinkThrottle($throttler, $logger, 'share_link_open', 'ShareLink'))->countRejected('10.0.0.1');
	}

	public function testAThrottlerFailureIsLoggedNotThrown(): void {
		$throttler = $this->createMock(IThrottler::class);
		$throttler->method('registerAttempt')->willThrowException(new RuntimeException('cache down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('ShareLink: registerAttempt failed: cache down');

		$throttle = new ObjectShareLinkThrottle($throttler, $logger, 'a', 'ShareLink');
		$throttle->countRejected('::1');

		$this->assertSame('a', $throttle->action);
		$this->assertSame($throttler, $throttle->throttler);
	}
}
