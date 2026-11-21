<?php

/**
 * OpenRegister Document Extractor
 *
 * Reads a Word document (docx, and the same-format docm, dotx and dotm) into
 * its structure: the title, and sections that each start at a heading and
 * carry its level, holding the paragraphs, lists, tables and picture
 * references under that heading in document order. The flat text comes along,
 * exactly as WordExtractor returns it for search, so one call gives both. Sits
 * next to WordExtractor and PresentationExtractor and follows the latter's
 * shape: a public supports(), a public extract(File), a guard that throws when
 * the server lacks the zip extension, and per-document failures degraded to
 * null with a log line that carries no document content (ADR-005). A consumer
 * can turn one document into one lesson draft with a block per heading section
 * (docx-structured-reader).
 *
 * The structure is read with ZipArchive and DOMDocument through the bounded
 * OoxmlPackage reader that PresentationExtractor uses. PhpWord's object model
 * has already dropped the style ids, outline levels and picture relationships
 * this needs (see the change's design.md).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\TextExtraction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/text-extraction-document/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\TextExtraction;

use DOMDocument;
use Exception;
use OCP\Files\File;
use Psr\Log\LoggerInterface;
use Throwable;
use ZipArchive;

/**
 * Extracts the structure and the flat text of Word documents.
 *
 * @psalm-import-type DocumentSection from DocumentBodyParser
 * @psalm-import-type DocumentBlock from DocumentBodyParser
 * @psalm-type DocumentStructure = array{title: string, sections: list<DocumentSection>, truncated: bool}
 * @psalm-type DocumentResult = array{title: string, sections: list<DocumentSection>, text: string, truncated: bool}
 *
 * @spec openspec/specs/text-extraction-document/spec.md
 */
class DocumentExtractor {

	/**
	 * The most bytes read from any one XML part of the package (20 MiB), as for presentations.
	 *
	 * @var int
	 */
	public const MAX_PART_BYTES = 20971520;

	/**
	 * MIME types read directly (lower case).
	 *
	 * @var list<string>
	 */
	private const SUPPORTED_MIME_TYPES = [
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'application/vnd.ms-word.document.macroenabled.12',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.template',
		'application/vnd.ms-word.template.macroenabled.12',
	];

	/**
	 * Extensions read when the MIME type is generic.
	 *
	 * @var list<string>
	 */
	private const SUPPORTED_EXTENSIONS = ['docx', 'docm', 'dotx', 'dotm'];

	/**
	 * Turns the document part into sections and blocks.
	 *
	 * @var DocumentBodyParser
	 */
	private readonly DocumentBodyParser $bodyParser;

	/**
	 * The MIME types and extensions this extractor reads.
	 *
	 * @var OoxmlFormatFilter
	 */
	private readonly OoxmlFormatFilter $formats;

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logger.
	 * @param WordExtractor $wordExtractor The flat-text extractor search indexing uses.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly WordExtractor $wordExtractor,
	) {
		$this->bodyParser = new DocumentBodyParser();
		$this->formats = new OoxmlFormatFilter(mimeTypes: self::SUPPORTED_MIME_TYPES, extensions: self::SUPPORTED_EXTENSIONS);
	}//end __construct()

	/**
	 * Whether a file is a format this extractor reads, by MIME type or, when that is generic, by extension.
	 *
	 * @param string $mimeType The file MIME type.
	 * @param string $fileName The file name.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/text-extraction-document/spec.md#requirement-the-supported-formats-can-be-asked-for-req-docx-010
	 */
	public function supports(string $mimeType, string $fileName): bool {
		return $this->formats->accepts(mimeType: $mimeType, fileName: $fileName);
	}//end supports()

	/**
	 * Read a document into its structure and its flat text.
	 *
	 * @param File $file The document.
	 *
	 * @return DocumentResult|null The structure and flat text, or null when the file is not a readable document.
	 *
	 * @throws Exception When the server has no zip extension (a deployment error).
	 *
	 * @spec openspec/specs/text-extraction-document/spec.md#requirement-a-document-that-cannot-be-read-degrades-to-no-result-req-docx-008
	 */
	public function extract(File $file): ?array {
		$this->assertZipAvailable(file: $file);
		$read = new DocumentRead(file: $file, mimeType: (string)$file->getMimeType());
		if ($this->supports(mimeType: $read->mimeType, fileName: (string)$file->getName()) === false) {
			$this->logger->debug(
				message: '[DocumentExtractor] Not a document format this extractor reads',
				context: ['file' => __FILE__, 'line' => __LINE__, 'fileId' => $file->getId(), 'mimeType' => $read->mimeType]
			);
			return null;
		}

		return $this->extractSupported(read: $read);
	}//end extract()

	/**
	 * Refuse when the server has no zip extension (a deployment error).
	 *
	 * @param File $file The document, for the log.
	 *
	 * @return void
	 *
	 * @throws Exception When the zip extension is missing.
	 */
	private function assertZipAvailable(File $file): void {
		if (class_exists(ZipArchive::class) === true) {
			return;
		}

		$this->logger->warning(
			message: '[DocumentExtractor] PHP zip extension not available',
			context: ['file' => __FILE__, 'line' => __LINE__, 'fileId' => $file->getId()]
		);
		throw new Exception('The PHP zip extension is not installed. Install php-zip to read documents.');
	}//end assertZipAvailable()

	/**
	 * Read a document of a supported format into its structure and flat text.
	 *
	 * @param DocumentRead $read The read in progress.
	 *
	 * @return DocumentResult|null Null when the file is not a readable document.
	 */
	private function extractSupported(DocumentRead $read): ?array {
		$structure = $this->readStructure(read: $read);
		if ($structure === null) {
			return null;
		}

		$this->logger->debug(
			message: '[DocumentExtractor] Document extracted',
			context: [
				'file' => __FILE__, 'line' => __LINE__, 'fileId' => $read->file->getId(), 'sections' => count($structure['sections']),
				'truncated' => $structure['truncated'],
			]
		);

		return $this->result(file: $read->file, structure: $structure);
	}//end extractSupported()

	/**
	 * The structure with the flat text added.
	 *
	 * @param File              $file      The document.
	 * @param DocumentStructure $structure The structure already read.
	 *
	 * @return DocumentResult
	 */
	private function result(File $file, array $structure): array {
		return [
			'title' => $structure['title'],
			'sections' => $structure['sections'],
			'text' => $this->flatText(file: $file, structure: $structure),
			'truncated' => $structure['truncated'],
		];
	}//end result()

	/**
	 * Open the package and read the structure; null when there is nothing readable.
	 *
	 * @param DocumentRead $read The read in progress.
	 *
	 * @return DocumentStructure|null
	 */
	private function readStructure(DocumentRead $read): ?array {
		$packageFile = new OoxmlPackageFile(maxPartBytes: self::MAX_PART_BYTES);
		try {
			$package = $packageFile->open(file: $read->file);
			$structure = $this->readDocument(package: $package);
			$this->logRefusedParts(package: $package, file: $read->file);
			return $this->withContent(read: $read, structure: $structure);
		} catch (Throwable $e) {
			$this->logFailure(read: $read, error: $e);
			return null;
		} finally {
			$packageFile->close();
		}
	}//end readStructure()

	/**
	 * The structure when it holds a title or a section, else null (logged as a warning).
	 *
	 * @param DocumentRead           $read      The read in progress.
	 * @param DocumentStructure|null $structure The structure read, or null.
	 *
	 * @return DocumentStructure|null
	 */
	private function withContent(DocumentRead $read, ?array $structure): ?array {
		if ($structure === null || ($structure['title'] === '' && $structure['sections'] === [])) {
			$this->logger->warning(
				message: '[DocumentExtractor] Document holds no readable content',
				context: ['file' => __FILE__, 'line' => __LINE__, 'fileId' => $read->file->getId(), 'mimeType' => $read->mimeType]
			);
			return null;
		}

		return $structure;
	}//end withContent()

	/**
	 * Log a per-document failure: structure only, never document content (ADR-005).
	 *
	 * @param DocumentRead $read  The read in progress.
	 * @param Throwable    $error The failure.
	 *
	 * @return void
	 */
	private function logFailure(DocumentRead $read, Throwable $error): void {
		$this->logger->error(
			message: '[DocumentExtractor] Document extraction failed; returning null',
			context: [
				'file' => __FILE__, 'line' => __LINE__, 'fileId' => $read->file->getId(), 'mimeType' => $read->mimeType,
				'exception' => get_class($error),
			]
		);
	}//end logFailure()

	/**
	 * Read the document part with its styles, numbering and title.
	 *
	 * @param OoxmlPackage $package The opened package.
	 *
	 * @return DocumentStructure|null Null when there is no readable document part.
	 *
	 * @spec openspec/specs/text-extraction-document/spec.md#requirement-content-comes-back-in-sections-under-their-heading-req-docx-001
	 * @spec openspec/specs/text-extraction-document/spec.md#requirement-hostile-input-is-bounded-req-docx-009
	 */
	private function readDocument(OoxmlPackage $package): ?array {
		$mainPath = ($package->mainPartPath() ?? 'word/document.xml');
		$document = $package->readXml(path: $mainPath);
		if ($document === null) {
			return null;
		}

		$relationships = $package->relationships(partPath: $mainPath);
		$structure = $this->bodyParser->parsePart(part: new DocumentPart(
			document: $document,
			styles: $this->relatedPart(
				package: $package,
				lookup: new RelatedPartLookup(relationships: $relationships, typeSuffix: '/styles', fallback: 'word/styles.xml')
			),
			numbering: $this->relatedPart(
				package: $package,
				lookup: new RelatedPartLookup(relationships: $relationships, typeSuffix: '/numbering', fallback: 'word/numbering.xml')
			),
			relationships: $relationships
		));
		if ($structure !== null && $structure['title'] === '') {
			$structure['title'] = $this->coreTitle(package: $package);
		}

		return $structure;
	}//end readDocument()

	/**
	 * The part a relationship of the given type points at, else the conventional path.
	 *
	 * @param OoxmlPackage      $package The opened package.
	 * @param RelatedPartLookup $lookup  The owner's relationships, the type sought and the fallback path.
	 *
	 * @return DOMDocument|null
	 */
	private function relatedPart(OoxmlPackage $package, RelatedPartLookup $lookup): ?DOMDocument {
		foreach ($lookup->relationships as $relationship) {
			if ($relationship['external'] === false && str_ends_with($relationship['type'], $lookup->typeSuffix) === true) {
				return $package->readXml(path: $relationship['target']);
			}
		}

		return $package->readXml(path: $lookup->fallback);
	}//end relatedPart()

	/**
	 * The title in the core properties part, or ''.
	 *
	 * @param OoxmlPackage $package The opened package.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/text-extraction-document/spec.md#requirement-the-document-carries-a-title-req-docx-002
	 */
	private function coreTitle(OoxmlPackage $package): string {
		$core = $this->relatedPart(
			package: $package,
			lookup: new RelatedPartLookup(
				relationships: $package->relationships(partPath: ''),
				typeSuffix: '/core-properties',
				fallback: 'docProps/core.xml'
			)
		);

		return trim((string)$core?->getElementsByTagNameNS('*', 'title')->item(0)?->textContent);
	}//end coreTitle()

	/**
	 * The flat text WordExtractor returns for the file, or the structure as plain text when that gives nothing.
	 *
	 * @param File $file The document.
	 * @param DocumentStructure $structure The structure already read.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/text-extraction-document/spec.md#requirement-the-flat-text-comes-along-unchanged-req-docx-007
	 */
	private function flatText(File $file, array $structure): string {
		try {
			$text = $this->wordExtractor->extract(file: $file);
		} catch (Throwable $e) {
			// A WordExtractor failure does not stop the structure, which does not need it.
			$this->logger->warning(
				message: '[DocumentExtractor] Flat text unavailable; using the structure',
				context: ['file' => __FILE__, 'line' => __LINE__, 'fileId' => $file->getId(), 'exception' => get_class($e)]
			);
			$text = null;
		}

		if ($text !== null && trim($text) !== '') {
			return $text;
		}

		return $this->plainText(structure: $structure);
	}//end flatText()

	/**
	 * The structure as plain text: title, headings, paragraphs, list items and table rows, one per line.
	 *
	 * @param DocumentStructure $structure The structure.
	 *
	 * @return string
	 */
	private function plainText(array $structure): string {
		$lines = [$structure['title']];
		foreach ($structure['sections'] as $section) {
			$lines[] = $section['heading'];
			foreach ($section['blocks'] as $block) {
				array_push($lines, ...$this->blockLines(block: $block));
			}
		}

		return implode("\n", array_filter($lines, static fn (string $line): bool => $line !== ''));
	}//end plainText()

	/**
	 * The plain-text lines of one block; a picture has none.
	 *
	 * @param DocumentBlock $block The block.
	 *
	 * @return list<string>
	 */
	private function blockLines(array $block): array {
		if ($block['type'] === 'paragraph') {
			return [$block['text']];
		}

		if ($block['type'] === 'list') {
			return array_map(static fn (array $item): string => $item['text'], $block['items']);
		}

		if ($block['type'] === 'table') {
			return array_map(static fn (array $row): string => implode("\t", $row), $block['rows']);
		}

		return [];
	}//end blockLines()

	/**
	 * Log the parts the package refused (names only, which are structure, not content).
	 *
	 * @param OoxmlPackage $package The package.
	 * @param File $file The document.
	 *
	 * @return void
	 */
	private function logRefusedParts(OoxmlPackage $package, File $file): void {
		$refused = $package->refusedParts();
		if ($refused === []) {
			return;
		}

		$this->logger->warning(
			message: '[DocumentExtractor] Refused parts that were too large, declared a DOCTYPE or were not XML',
			context: ['file' => __FILE__, 'line' => __LINE__, 'fileId' => $file->getId(), 'parts' => $refused]
		);
	}//end logRefusedParts()
}//end class
