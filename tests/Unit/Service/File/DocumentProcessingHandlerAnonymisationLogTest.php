<?php

/**
 * Characterisation test for the per-run anonymisation log row that
 * DocumentProcessingHandler::anonymize() persists.
 *
 * Pins the row's file id, MIME type, engine, status, replacement count and
 * sanitisation column, the id-0 fallback, and the PII-free warning when the
 * insert fails.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/office-document-sanitization/spec.md
 */

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Files\Service\File\DocumentAnonymisationRequest;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Files\Db\AnonymisationLog;
use OCA\OpenRegister\Files\Db\AnonymisationLogMapper;
use OCA\OpenRegister\Files\Service\File\DocumentProcessingHandler;
use OCA\OpenRegister\Files\Service\File\DocumentProcessingPlatform;
use OCA\OpenRegister\Files\Service\File\DocumentRedactionServices;
use OCA\OpenRegister\Files\Service\File\OfficeDocumentSanitizer;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Files\Service\File\DocumentProcessingHandler
 */
class DocumentProcessingHandlerAnonymisationLogTest extends TestCase {

	/**
	 * @var array<int, array<string, mixed>>
	 */
	private array $warnings = [];

	public function testRowCarriesTheRunFacts(): void {
		$rows = [];
		$this->anonymise(fileId: 42, rows: $rows, failInsert: false);

		$this->assertCount(1, $rows);
		$this->assertSame(
			[
				'fileId' => 42,
				'mimeType' => 'text/plain',
				'engine' => 'TextReplacer',
				'status' => AnonymisationLog::STATUS_SUCCESS,
				'replacements' => 2,
				'sanitization' => null,
			],
			$rows[0]
		);
		$this->assertSame([], $this->warnings);
	}//end testRowCarriesTheRunFacts()

	public function testNodeWithoutIdLogsFileIdZero(): void {
		$rows = [];
		$this->anonymise(fileId: 0, rows: $rows, failInsert: false);

		$this->assertCount(1, $rows);
		$this->assertSame(0, $rows[0]['fileId']);
		$this->assertSame(2, $rows[0]['replacements']);
	}//end testNodeWithoutIdLogsFileIdZero()

	public function testInsertFailureIsLoggedWithoutPii(): void {
		$rows = [];
		$this->anonymise(fileId: 42, rows: $rows, failInsert: true);

		$this->assertSame(
			[
				[
					'message' => 'DocumentProcessingHandler: AnonymisationLog persistence failed',
					'context' => ['fileId' => 42, 'error' => 'db gone'],
				],
			],
			$this->warnings
		);
	}//end testInsertFailureIsLoggedWithoutPii()

	/**
	 * @param array<int, array<string, mixed>> $rows
	 */
	private function anonymise(int $fileId, array &$rows, bool $failInsert): void {
		$this->warnings = [];
		$warnings = &$this->warnings;
		$logger = new class($warnings) extends AbstractLogger {
			/**
			 * @param array<int, array<string, mixed>> $warnings
			 */
			public function __construct(private array &$warnings) {
			}

			public function log($level, string|\Stringable $message, array $context = []): void {
				if ($level === 'warning') {
					$this->warnings[] = ['message' => (string)$message, 'context' => $context];
				}
			}
		};

		$logMapper = $this->createMock(AnonymisationLogMapper::class);
		$logMapper->method('insert')->willReturnCallback(
			function (AnonymisationLog $entity) use (&$rows, $failInsert): AnonymisationLog {
				if ($failInsert === true) {
					throw new RuntimeException('db gone');
				}

				$rows[] = [
					'fileId' => $entity->getFileId(),
					'mimeType' => $entity->getMimeType(),
					'engine' => $entity->getEngine(),
					'status' => $entity->getStatus(),
					'replacements' => $entity->getReplacements(),
					'sanitization' => $entity->getSanitization(),
				];
				return $entity;
			}
		);

		$sanitizer = $this->createMock(OfficeDocumentSanitizer::class);
		$sanitizer->method('isSanitizable')->willReturn(false);

		$handler = new DocumentProcessingHandler(
			platform: new DocumentProcessingPlatform(
				rootFolder: $this->createMock(IRootFolder::class),
				userSession: $this->createMock(IUserSession::class),
				l10n: null
			),
			redaction: new DocumentRedactionServices(
				entityRelationMapper: $this->createMock(EntityRelationMapper::class),
				sanitizer: $sanitizer,
				anonymisationLogMapper: $logMapper
			),
			logger: $logger
		);

		$parent = $this->createMock(Folder::class);
		$parent->method('nodeExists')->willReturn(false);
		$parent->method('newFile')->willReturnCallback(
			function (string $path): File {
				$out = $this->createMock(File::class);
				$out->method('getPath')->willReturn('/files/' . $path);
				return $out;
			}
		);
		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn($fileId);
		$node->method('getName')->willReturn('letter.txt');
		$node->method('getPath')->willReturn('/files/letter.txt');
		$node->method('getMimeType')->willReturn('text/plain');
		$node->method('getContent')->willReturn('Jan Jansen woont in Amsterdam.');
		$node->method('getParent')->willReturn($parent);

		$handler->anonymize(new DocumentAnonymisationRequest(
			node: $node,
			entities: [
				['text' => 'Jan Jansen', 'entityType' => 'PERSON', 'key' => 'p1'],
				['text' => 'Amsterdam', 'entityType' => 'LOCATION', 'key' => 'l1'],
			]
		));
	}//end anonymise()
}//end class
