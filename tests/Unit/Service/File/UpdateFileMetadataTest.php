<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

/*
 * Unit test: UpdateFileHandler::updateFileMetadata().
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use Exception;
use OCA\OpenRegister\Files\Db\File as FileRow;
use OCA\OpenRegister\Files\Db\FileMapperInterface;
use OCA\OpenRegister\Files\Service\File\FileOwnershipHandler;
use OCA\OpenRegister\Service\File\FileValidationHandler;
use OCA\OpenRegister\Service\File\FolderManagementHandler;
use OCA\OpenRegister\Service\File\FileMetadataChange;
use OCA\OpenRegister\Service\File\ReadFileHandler;
use OCA\OpenRegister\Service\File\UpdateFileGuards;
use OCA\OpenRegister\Service\File\UpdateFileHandler;
use OCA\OpenRegister\Service\File\UpdateFileLocator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins which OR-side metadata writes run, in which order, and what is returned and logged.
 */
class UpdateFileMetadataTest extends TestCase {

	/** @var list<string> */
	private array $calls = [];

	/** @var list<array<string, mixed>> */
	private array $logged = [];

	private function handler(?FileMapperInterface $mapper): UpdateFileHandler {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(function (string $message, array $context = []): void {
			unset($context['file'], $context['line']);
			$this->logged[] = ['message' => $message] + $context;
		});

		return new UpdateFileHandler(
			new UpdateFileLocator($this->createMock(FolderManagementHandler::class), $this->createMock(ReadFileHandler::class)),
			new UpdateFileGuards($this->createMock(FileValidationHandler::class), $this->createMock(FileOwnershipHandler::class)),
			$logger,
			$mapper
		);
	}//end handler()

	private function mapper(): FileMapperInterface {
		$mapper = $this->createMock(FileMapperInterface::class);
		$mapper->method('findOrCreateByFileId')->willReturnCallback(function (int $fileId): FileRow {
			$this->calls[] = 'find:' . $fileId;
			return $this->row(name: 'found');
		});
		$mapper->method('setDescriptionForFile')->willReturnCallback(function (int $fileId, string $description): FileRow {
			$this->calls[] = 'description:' . $description;
			return $this->row(name: 'description');
		});
		$mapper->method('setCategoryForFile')->willReturnCallback(function (int $fileId, string $category): FileRow {
			$this->calls[] = 'category:' . $category;
			return $this->row(name: 'category');
		});
		$mapper->method('setLabelsForFile')->willReturnCallback(function (int $fileId, array $labels): FileRow {
			$this->calls[] = 'labels:' . implode(',', $labels);
			return $this->row(name: 'labels');
		});
		return $mapper;
	}//end mapper()

	private function row(string $name): FileRow {
		$row = new FileRow();
		$row->setCategory($name);
		return $row;
	}//end row()

	public function testWithoutAMapperItRefuses(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('FileMapper is not wired; cannot update OR-side metadata');
		$this->handler(null)->updateFileMetadata(new FileMetadataChange(1, 'd'));
	}//end testWithoutAMapperItRefuses()

	public function testOnlyTheGivenFieldsAreWrittenInOrder(): void {
		$result = $this->handler($this->mapper())->updateFileMetadata(new FileMetadataChange(4, '', null, ['a', 'b']));

		$this->assertSame(['find:4', 'description:', 'labels:a,b'], $this->calls);
		$this->assertSame('labels', $result->getCategory());
		$this->assertSame(
			[[
				'message' => '[UpdateFileHandler] OR-side metadata updated for file 4',
				'fileId' => 4,
				'descriptionTouched' => true,
				'categoryTouched' => false,
				'labelsTouched' => true,
			]],
			$this->logged
		);
	}//end testOnlyTheGivenFieldsAreWrittenInOrder()

	public function testAllFieldsAreWrittenAndTheLastWriteIsReturned(): void {
		$result = $this->handler($this->mapper())->updateFileMetadata(new FileMetadataChange(4, 'd', 'c', []));

		$this->assertSame(['find:4', 'description:d', 'category:c', 'labels:'], $this->calls);
		$this->assertSame('labels', $result->getCategory());
	}//end testAllFieldsAreWrittenAndTheLastWriteIsReturned()

	public function testNothingToWriteReturnsTheFoundRow(): void {
		$result = $this->handler($this->mapper())->updateFileMetadata(new FileMetadataChange(4));

		$this->assertSame(['find:4'], $this->calls);
		$this->assertSame('found', $result->getCategory());
		$this->assertFalse($this->logged[0]['descriptionTouched']);
	}//end testNothingToWriteReturnsTheFoundRow()
}//end class
