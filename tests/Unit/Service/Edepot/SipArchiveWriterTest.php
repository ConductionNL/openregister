<?php

/**
 * Tests for SipArchiveWriter.
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

use OCA\OpenRegister\Service\Edepot\SipArchiveWriter;
use OCA\OpenRegister\Service\Edepot\SipPart;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * @covers \OCA\OpenRegister\Service\Edepot\SipArchiveWriter
 */
class SipArchiveWriterTest extends TestCase {

	public function testAZipHoldsTheEntriesAndABagHoldsThemUnderData(): void {
		$base = (string)tempnam(sys_get_temp_dir(), 'sipw');
		$temp = $this->createMock(ITempManager::class);
		$temp->method('getTemporaryFile')->willReturnCallback(static fn (string $postFix): string => $base.$postFix);
		$writer  = new SipArchiveWriter(tempManager: $temp);
		$entries = [['path' => 'a.txt', 'kind' => 'string', 'content' => 'abc']];

		$zipPath = $writer->write(part: new SipPart(transferId: 't', sequenceNumber: 2, totalPackages: 2, format: 'zip'), entries: $entries);
		$bagPath = $writer->write(part: new SipPart(transferId: 't', sequenceNumber: 1, totalPackages: 1, format: 'bagit'), entries: $entries);

		$this->assertSame($base.'.sip-part2.zip', $zipPath);
		$this->assertSame($base.'.bag.zip', $bagPath);
		$zip = new ZipArchive();
		$this->assertTrue($zip->open($zipPath) === true);
		$this->assertSame('abc', $zip->getFromName('a.txt'));
		$zip->close();
		$this->assertTrue($zip->open($bagPath) === true);
		$this->assertSame('abc', $zip->getFromName('data/a.txt'));
		$this->assertNotFalse($zip->getFromName('tagmanifest-sha256.txt'));
		$zip->close();
		@unlink($zipPath);
		@unlink($bagPath);
		@unlink($base);
	}

	public function testNoTemporaryFileIsARefusal(): void {
		$temp = $this->createMock(ITempManager::class);
		$temp->method('getTemporaryFile')->willReturn(false);
		$part = new SipPart(transferId: 't', sequenceNumber: 1, totalPackages: 1, format: 'zip');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Failed to allocate a temporary file for the SIP archive');
		(new SipArchiveWriter(tempManager: $temp))->write(part: $part, entries: []);
	}
}//end class
