<?php

/**
 * A file property is not written into a register whose documents are moving.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\File\DocumentStoreMoveGuard;
use OCA\OpenRegister\Service\File\DocumentStoreMoving;
use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyHandler;
use OCA\OpenRegister\Service\Object\SaveObject\FileStoreRequest;
use OCA\OpenRegister\Service\ObjectFilesInterface;
use OCP\Files\File;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Stores a base64 file property with the register's move flag on and off.
 */
class FilePropertyMoveGuardTest extends TestCase {

	private function handler(ObjectFilesInterface $files, bool $moving): FilePropertyHandler {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($moving === true && $key === 'document_store_moving_5') ? '1' : $default
		);
		return new FilePropertyHandler(new NullLogger(), $files, new DocumentStoreMoveGuard($config));
	}

	private function request(): FileStoreRequest {
		$object = new ObjectEntity();
		$object->setUuid('obj-uuid');
		$object->setRegister('5');
		return new FileStoreRequest(objectEntity: $object, propertyName: 'doc', fileConfig: [], index: null);
	}

	public function testNothingIsWrittenWhileTheRegisterMoves(): void {
		$files = $this->createMock(ObjectFilesInterface::class);
		$files->expects($this->never())->method('addFile');

		$this->expectException(DocumentStoreMoving::class);
		$this->expectExceptionCode(503);
		$this->expectExceptionMessage('document-store-move');

		$this->handler($files, moving: true)->processSingleFileProperty($this->request(), 'data:text/plain;base64,aGVsbG8=');
	}

	public function testTheFileIsWrittenWhenNoMoveRuns(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(77);
		$files = $this->createMock(ObjectFilesInterface::class);
		$files->expects($this->once())->method('addFile')->willReturn($file);

		$id = $this->handler($files, moving: false)->processSingleFileProperty($this->request(), 'data:text/plain;base64,aGVsbG8=');

		$this->assertSame(77, $id);
	}
}//end class
