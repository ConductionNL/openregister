<?php

/**
 * OutputModeCoverageTest
 *
 * The suite guard of REQ-RRS-001: every output mode the anonymisation
 * pipeline can write has a verifier entry, so a new mode cannot pass by
 * omission.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-the-written-copy-cannot-be-read-back-req-rrs-001-carried-from-filinq-req-rwb-01
 */

declare(strict_types=1);

namespace Unit\Service\Anonymisation;

use OCA\OpenRegister\Files\Service\File\DocumentProcessingHandler;
use OCA\OpenRegister\Files\Service\File\OfficeDocumentSanitizer;
use OCA\OpenRegister\Files\Service\File\Sanitizer\SanitizerInterface;
use OCA\OpenRegister\Service\Anonymisation\RedactionOutputMode;
use OCP\Files\IRootFolder;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * Every output mode has a verifier entry.
 *
 * @covers \OCA\OpenRegister\Service\Anonymisation\RedactionOutputMode
 * @uses   \OCA\OpenRegister\Files\Service\File\OfficeDocumentSanitizer
 * @uses   \OCA\OpenRegister\Files\Service\File\Sanitizer\DocxSanitizer
 * @uses   \OCA\OpenRegister\Files\Service\File\Sanitizer\OdtSanitizer
 * @uses   \OCA\OpenRegister\Files\Service\File\Sanitizer\XlsxSanitizer
 * @uses   \OCA\OpenRegister\Files\Service\File\Sanitizer\PptxSanitizer
 * @uses   \OCA\OpenRegister\Files\Service\File\Sanitizer\OoxmlCommonParts
 * @uses   \OCA\OpenRegister\Files\Service\File\Sanitizer\OfficePartSurgery
 */
class OutputModeCoverageTest extends TestCase {

	/**
	 * Every declared mode, the dispatch's extensions and the sanitised
	 * formats resolve to a mode with a reader.
	 *
	 * @return void
	 */
	public function testEveryOutputModeHasAVerifierEntry(): void {
		self::assertSame([], RedactionOutputMode::unverified(), 'output modes without a verifier entry');

		foreach ($this->dispatchedExtensions() + $this->sanitisedExtensions() as $extension => $source) {
			$mode = RedactionOutputMode::forFileName(fileName: 'output.' . $extension);
			self::assertNotSame(RedactionOutputMode::TEXT, $mode, sprintf('%s (%s) is written in its own format but verified as text', $extension, $source));
			self::assertNotNull(RedactionOutputMode::readerFor(mode: $mode), sprintf('%s (%s) has no verifier entry', $extension, $source));
		}

		self::assertSame(RedactionOutputMode::READER_TEXT, RedactionOutputMode::readerFor(mode: RedactionOutputMode::forFileName(fileName: 'notes.txt')));
	}//end testEveryOutputModeHasAVerifierEntry()

	/**
	 * The guard notices what it guards: the dispatch and the sanitiser list
	 * are found, so an empty scan cannot pass.
	 *
	 * @return void
	 */
	public function testTheGuardReadsTheDispatchAndTheSanitisers(): void {
		self::assertSame(['doc', 'docx', 'pdf'], array_keys($this->dispatchedExtensions()));
		self::assertSame(['docx', 'odt', 'xlsx', 'pptx'], array_keys($this->sanitisedExtensions()));
	}//end testTheGuardReadsTheDispatchAndTheSanitisers()

	/**
	 * An unknown mode has no reader, and a file name resolves by extension.
	 *
	 * @return void
	 */
	public function testAnUndeclaredModeHasNoReader(): void {
		self::assertNull(RedactionOutputMode::readerFor(mode: 'image'));
		self::assertSame(RedactionOutputMode::XLSX, RedactionOutputMode::forFileName(fileName: 'Begroting_anonymized.XLSX'));
		self::assertSame(RedactionOutputMode::DOCX, RedactionOutputMode::forFileName(fileName: 'oud.doc'));
		self::assertSame(RedactionOutputMode::TEXT, RedactionOutputMode::forFileName(fileName: 'zonder-extensie'));
	}//end testAnUndeclaredModeHasNoReader()

	/**
	 * The extensions DocumentProcessingHandler dispatches on in its own format,
	 * read from its source so a new branch is seen.
	 *
	 * @return array<string, string> Extension => where it was found.
	 */
	private function dispatchedExtensions(): array {
		$source = (string) file_get_contents((string) (new ReflectionClass(DocumentProcessingHandler::class))->getFileName());
		$found  = [];
		preg_match_all("/in_array\\(\\\$fileExtension, \\[([^\\]]*)\\]/", $source, $lists);
		foreach ($lists[1] as $list) {
			preg_match_all("/'([a-z0-9]+)'/", $list, $items);
			foreach ($items[1] as $item) {
				$found[$item] = 'DocumentProcessingHandler dispatch';
			}
		}

		preg_match_all("/\\\$fileExtension === '([a-z0-9]+)'/", $source, $single);
		foreach ($single[1] as $item) {
			$found[$item] = 'DocumentProcessingHandler dispatch';
		}

		ksort($found);
		return $found;
	}//end dispatchedExtensions()

	/**
	 * The extensions of every format the registered sanitisers accept.
	 *
	 * @return array<string, string> Extension => the sanitiser.
	 */
	private function sanitisedExtensions(): array {
		$sanitizer = new OfficeDocumentSanitizer(
			rootFolder: $this->createMock(IRootFolder::class),
			tempManager: $this->createMock(ITempManager::class),
			logger: $this->createMock(LoggerInterface::class)
		);
		$strategies = (new ReflectionClass(OfficeDocumentSanitizer::class))->getProperty('strategies')->getValue($sanitizer);
		$extension  = new ReflectionMethod(OfficeDocumentSanitizer::class, 'extensionForMime');

		$found = [];
		foreach ($strategies as $strategy) {
			self::assertInstanceOf(SanitizerInterface::class, $strategy);
			$mime = (string) (new ReflectionClass($strategy))->getConstant('MIME');
			self::assertTrue($strategy->supports($mime));
			$found[ltrim((string) $extension->invoke($sanitizer, $mime), '.')] = $strategy::class;
		}

		return $found;
	}//end sanitisedExtensions()
}//end class
