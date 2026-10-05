<?php

/**
 * The shared List-Unsubscribe helper.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/opt-out-before-send/specs/external-recipient-opt-out/spec.md#requirement-openregister-owns-one-shared-list-unsubscribe-helper-req-ero-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Notification;

use OCA\OpenRegister\Service\Notification\UnsubscribeHeaders;
use OCA\OpenRegister\Tests\Unit\Service\Notification\Fixture\RecordingMessage;
use OCA\OpenRegister\Tests\Unit\Service\Notification\Fixture\RecordingSymfonyMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Notification\UnsubscribeHeaders
 */
class UnsubscribeHeadersTest extends TestCase {

	private function helper(): UnsubscribeHeaders {
		return new UnsubscribeHeaders(logger: $this->createMock(LoggerInterface::class));
	}

	public function testTheHelperSetsBothHeaders(): void {
		$message = new RecordingSymfonyMessage();

		$set = $this->helper()->apply($message, ['url' => 'https://nc.example/u/page', 'oneClickUrl' => 'https://nc.example/u/abc']);

		$this->assertTrue($set);
		$this->assertSame(
			[
				'List-Unsubscribe' => '<https://nc.example/u/abc>',
				'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
			],
			$message->headers
		);
	}

	public function testItFallsBackToTheUrlAndReplacesAnEarlierHeader(): void {
		$message = new RecordingSymfonyMessage();
		$message->headers['List-Unsubscribe'] = '<https://old.example/x>';

		$this->assertTrue($this->helper()->apply($message, ['url' => 'https://nc.example/u/abc']));
		$this->assertSame('<https://nc.example/u/abc>', $message->headers['List-Unsubscribe']);
		$this->assertCount(2, $message->headers);
	}

	public function testAMailerWithoutHeadersReturnsFalseAndDoesNotThrow(): void {
		$message = new RecordingMessage();

		$this->assertFalse($this->helper()->apply($message, ['oneClickUrl' => 'https://nc.example/u/abc']));
		$this->assertSame([], $message->headers);
	}

	/**
	 * @dataProvider unsafeUrls
	 */
	public function testAnUnsafeOrMissingUrlSetsNothing(array $material): void {
		$message = new RecordingSymfonyMessage();

		$this->assertFalse($this->helper()->apply($message, $material));
		$this->assertSame([], $message->headers);
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function unsafeUrls(): array {
		return [
			'empty' => [[]],
			'header injection' => [['oneClickUrl' => "https://nc.example/u/abc\r\nBcc: x@evil.example"]],
			'closing bracket' => [['oneClickUrl' => 'https://nc.example/u/a>b']],
			'mailto only' => [['oneClickUrl' => 'mailto:stop@nc.example']],
		];
	}
}
