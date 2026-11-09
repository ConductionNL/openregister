<?php

/**
 * FilePropertyLog writes file property log lines.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyLog;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\FilePropertyLog
 */
final class FilePropertyLogTest extends TestCase {

	/**
	 * write() puts file, line and app ahead of the given context, at the given level.
	 *
	 * @return void
	 */
	public function testWritePrefixesFileLineAndApp(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'msg',
			$this->callback(
				static fn (array $context): bool => array_keys($context) === ['file', 'line', 'app', 'k']
					&& $context['app'] === 'openregister' && $context['k'] === 'v'
			)
		);

		(new FilePropertyLog($logger))->write('warning', 'msg', ['k' => 'v']);
	}//end testWritePrefixesFileLineAndApp()

	/**
	 * note() logs with only file and line as context.
	 *
	 * @return void
	 */
	public function testNoteHasOnlyFileAndLine(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with(
			'deleted',
			$this->callback(static fn (array $context): bool => array_keys($context) === ['file', 'line'])
		);

		(new FilePropertyLog($logger))->note('info', 'deleted');
	}//end testNoteHasOnlyFileAndLine()
}//end class
