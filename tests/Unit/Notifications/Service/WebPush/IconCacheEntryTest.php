<?php

/**
 * Unit tests for IconCacheEntry: one cached icon in the app-data folder, or none.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Service\WebPush
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Service\WebPush;

use OCA\OpenRegister\Notifications\Service\WebPush\IconCacheEntry;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Notifications\Service\WebPush\IconCacheEntry
 */
final class IconCacheEntryTest extends TestCase {

	public function testWithoutAFolderNothingExistsAndAWriteIsANoOp(): void {
		$entry = new IconCacheEntry(folder: null, key: 'openregister-icon.png');

		$this->assertFalse($entry->exists());
		$entry->write(png: 'png-bytes');
		$this->addToAssertionCount(1);
	}

	public function testExistsAsksTheFolderForItsOwnKey(): void {
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->expects($this->exactly(2))->method('fileExists')->with('decidiq-badge.png')->willReturnOnConsecutiveCalls(true, false);
		$entry = new IconCacheEntry(folder: $folder, key: 'decidiq-badge.png');

		$this->assertTrue($entry->exists());
		$this->assertFalse($entry->exists());
	}

	public function testReadReturnsTheCachedFilesContent(): void {
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getContent')->willReturn('cached-png');
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->expects($this->once())->method('getFile')->with('openregister-icon.png')->willReturn($file);

		$this->assertSame('cached-png', (new IconCacheEntry(folder: $folder, key: 'openregister-icon.png'))->read());
	}

	public function testWriteStoresThePngUnderItsKey(): void {
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->expects($this->once())->method('newFile')->with('openregister-icon.png', 'png-bytes');

		(new IconCacheEntry(folder: $folder, key: 'openregister-icon.png'))->write(png: 'png-bytes');
	}
}
