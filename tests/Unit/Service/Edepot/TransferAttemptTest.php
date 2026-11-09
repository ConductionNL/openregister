<?php

/**
 * Tests for TransferAttempt.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Edepot
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Edepot;

use OCA\OpenRegister\Service\Edepot\TransferAttempt;
use OCA\OpenRegister\Service\Edepot\Transport\TransportInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Edepot\TransferAttempt
 */
class TransferAttemptTest extends TestCase {

	public function testAnAttemptIsAppendedAndEarlierAttemptsAreKept(): void {
		$transport = $this->createMock(TransportInterface::class);
		$transport->method('getName')->willReturn('sftp');
		$attempt = new TransferAttempt(number: 2, transport: $transport);

		$list = $attempt->appendTo(transferList: ['attempts' => [['attempt' => 1]]], outcome: 'failed', error: 'down');

		$this->assertCount(2, $list['attempts']);
		$this->assertSame(['attempt' => 1], $list['attempts'][0]);
		$this->assertSame(2, $list['attempts'][1]['attempt']);
		$this->assertSame('sftp', $list['attempts'][1]['transport']);
		$this->assertSame('failed', $list['attempts'][1]['outcome']);
		$this->assertSame('down', $list['attempts'][1]['error']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $list['attempts'][1]['timestamp']);
	}

	public function testMalformedAttemptsStartOver(): void {
		$transport = $this->createMock(TransportInterface::class);
		$transport->method('getName')->willReturn('rest');

		$list = (new TransferAttempt(number: 1, transport: $transport))->appendTo(transferList: ['attempts' => 'x'], outcome: 'completed', error: null);

		$this->assertCount(1, $list['attempts']);
		$this->assertNull($list['attempts'][0]['error']);
	}
}//end class
