<?php

/**
 * FileScopeReaderTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Files\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Files\Service\File;

use OCA\OpenRegister\Files\Db\FileMapperInterface;
use OCA\OpenRegister\Files\Service\File\FileReadScope;
use OCA\OpenRegister\Files\Service\File\FileScopeReader;
use OCA\OpenRegister\Kernel\Contracts\Search\FileScopeInterface;
use PHPUnit\Framework\TestCase;

/**
 * The Files answer to the Search file-scope port.
 *
 * @covers \OCA\OpenRegister\Files\Service\File\FileScopeReader
 */
final class FileScopeReaderTest extends TestCase
{

    /**
     * It is the port's implementation.
     *
     * @return void
     */
    public function testItImplementsTheSearchPort(): void
    {
        $reader = new FileScopeReader($this->createMock(FileMapperInterface::class), $this->createMock(FileReadScope::class));

        $this->assertInstanceOf(FileScopeInterface::class, $reader);
    }//end testItImplementsTheSearchPort()

    /**
     * An object's files come from the one batched file-cache lookup, unchanged.
     *
     * @return void
     */
    public function testObjectFilesAreTheBatchedLookup(): void
    {
        $files = $this->createMock(FileMapperInterface::class);
        $files->expects($this->once())->method('getFileIdsForObjects')->with(['a', 'b'])->willReturn(['a' => [3, 1], 'b' => []]);

        $reader = new FileScopeReader($files, $this->createMock(FileReadScope::class));

        $this->assertSame(['a' => [3, 1], 'b' => []], $reader->fileIdsOfObjects(uuids: ['a', 'b']));
    }//end testObjectFilesAreTheBatchedLookup()

    /**
     * A folder's files are its direct children minus sub-folders, in file-cache order, as integers.
     *
     * @return void
     */
    public function testFolderFilesLeaveSubFoldersOut(): void
    {
        $files = $this->createMock(FileMapperInterface::class);
        $files->expects($this->once())->method('getFiles')->with(7)->willReturn(
            [
                ['fileid' => '30', 'mimetype' => 'text/plain'],
                ['fileid' => 31, 'mimetype' => FileScopeReader::FOLDER_MIME],
                ['fileid' => 12, 'mimetype' => 'application/pdf'],
                ['fileid' => 13],
            ]
        );

        $reader = new FileScopeReader($files, $this->createMock(FileReadScope::class));

        $this->assertSame([30, 12, 13], $reader->fileIdsInFolder(folderId: 7));
    }//end testFolderFilesLeaveSubFoldersOut()

    /**
     * The read right is the file read rule's answer.
     *
     * @return void
     */
    public function testTheReadRightIsTheFileReadRule(): void
    {
        $readScope = $this->createMock(FileReadScope::class);
        $readScope->method('mayReadFile')->willReturnCallback(static fn (int $fileId): bool => $fileId === 5);

        $reader = new FileScopeReader($this->createMock(FileMapperInterface::class), $readScope);

        $this->assertTrue($reader->mayReadFile(fileId: 5));
        $this->assertFalse($reader->mayReadFile(fileId: 6));
    }//end testTheReadRightIsTheFileReadRule()
}//end class
