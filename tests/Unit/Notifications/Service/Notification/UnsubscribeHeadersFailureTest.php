<?php

/**
 * Unit tests for UnsubscribeHeaders when the mail object refuses its headers.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Service\Notification;

use OCA\OpenRegister\Notifications\Service\Notification\UnsubscribeHeaders;
use OCA\OpenRegister\Tests\Unit\Service\Notification\Fixture\RecordingMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Notifications\Service\Notification\UnsubscribeHeaders
 */
final class UnsubscribeHeadersFailureTest extends TestCase {

	public function testAMailObjectThatThrowsSendsWithTheBodyLinkOnly(): void {
		$message = new class extends RecordingMessage {
			public function getSymfonyEmail(): object {
				throw new RuntimeException('mail object gone');
			}
		};
		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(static function (string $line, array $context = []) use (&$logged): void {
			$logged[] = [$line, $context];
		});

		$set = (new UnsubscribeHeaders(logger: $logger))->apply($message, ['oneClickUrl' => 'https://nc.example/u/abc']);

		$this->assertFalse($set);
		$this->assertSame([], $message->headers);
		$this->assertSame(
			[['[UnsubscribeHeaders] could not set the headers; sending with the body link only', ['exception' => 'mail object gone']]],
			$logged
		);
	}

	public function testAHeaderBagThatThrowsIsNotAnError(): void {
		$message = new class extends RecordingMessage {
			public function getSymfonyEmail(): object {
				return new class {
					public function getHeaders(): object {
						throw new RuntimeException('headers locked');
					}
				};
			}
		};

		$this->assertFalse((new UnsubscribeHeaders(logger: $this->createMock(LoggerInterface::class)))->apply($message, ['url' => 'https://nc.example/u/abc']));
	}
}
