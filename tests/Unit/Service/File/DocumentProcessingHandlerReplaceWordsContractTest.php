<?php

/**
 * DocumentProcessingHandlerReplaceWordsContractTest
 *
 * Characterisation of the replaceWords() entry contract: a non-file node is
 * refused, and the default output name follows the source name.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\File;

use Exception;
use OCA\OpenRegister\Db\EntityRelationMapper;
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
use Psr\Log\LoggerInterface;

/**
 * Entry contract of {@see DocumentProcessingHandler::replaceWords()}.
 */
class DocumentProcessingHandlerReplaceWordsContractTest extends TestCase {

	/**
	 * Build a handler with mocked collaborators.
	 *
	 * @return DocumentProcessingHandler
	 */
	private function makeHandler(): DocumentProcessingHandler {
		return new DocumentProcessingHandler(
			platform: new DocumentProcessingPlatform(
				rootFolder: $this->createMock(IRootFolder::class),
				userSession: $this->createMock(IUserSession::class),
				l10n: null
			),
			redaction: new DocumentRedactionServices(
				entityRelationMapper: $this->createMock(EntityRelationMapper::class),
				sanitizer: $this->createMock(OfficeDocumentSanitizer::class),
				anonymisationLogMapper: $this->createMock(AnonymisationLogMapper::class)
			),
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end makeHandler()

	/**
	 * A text file whose parent records the written name and content.
	 *
	 * @param string               $name    Source file name.
	 * @param array<string, mixed> $written Receives 'path' and 'content'.
	 *
	 * @return File
	 */
	private function textFile(string $name, array &$written): File {
		$output = $this->createMock(File::class);
		$output->method('getPath')->willReturn('/out');

		$folder = $this->createMock(Folder::class);
		$folder->method('nodeExists')->willReturn(false);
		$folder->method('newFile')->willReturnCallback(
			function (string $path, $content) use (&$written, $output) {
				$written = ['path' => $path, 'content' => $content];
				return $output;
			}
		);

		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getPath')->willReturn('/' . $name);
		$file->method('getContent')->willReturn('Aanvraag van Jan Jansen.');
		$file->method('getParent')->willReturn($folder);
		return $file;
	}//end textFile()

	/**
	 * A folder is refused before anything is written.
	 *
	 * @return void
	 */
	public function testFolderIsRefused(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Node must be a file');

		$this->makeHandler()->replaceWords(node: $this->createMock(Folder::class), replacements: ['a' => 'b']);
	}//end testFolderIsRefused()

	/**
	 * Without an output name the source base name gets `_replaced` and keeps its extension.
	 *
	 * @return void
	 */
	public function testDefaultOutputNameKeepsExtension(): void {
		$written = [];
		$this->makeHandler()->replaceWords(
			node: $this->textFile('note.txt', $written),
			replacements: ['jan jansen' => '[PERSON: 1]']
		);

		self::assertSame('note_replaced.txt', $written['path']);
		self::assertSame('Aanvraag van [PERSON: 1].', $written['content']);
	}//end testDefaultOutputNameKeepsExtension()

	/**
	 * A source without an extension gets a bare `_replaced` name.
	 *
	 * @return void
	 */
	public function testDefaultOutputNameWithoutExtension(): void {
		$written = [];
		$this->makeHandler()->replaceWords(node: $this->textFile('README', $written), replacements: []);

		self::assertSame('README_replaced', $written['path']);
	}//end testDefaultOutputNameWithoutExtension()

	/**
	 * An explicit output name is used as given.
	 *
	 * @return void
	 */
	public function testExplicitOutputNameIsUsed(): void {
		$written = [];
		$this->makeHandler()->replaceWords(
			node: $this->textFile('note.txt', $written),
			replacements: [],
			outputName: 'custom.txt'
		);

		self::assertSame('custom.txt', $written['path']);
	}//end testExplicitOutputNameIsUsed()
}//end class
