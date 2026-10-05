<?php

/**
 * OpenRegister Presentation Extractor
 *
 * Reads a PowerPoint deck (pptx, and the same-format pptm and ppsx) into
 * structured slides: for each slide, in the order the deck presents them, its
 * number, whether it is hidden, its title, its body paragraphs in shape order,
 * its speaker notes, and its image references with their alt text. Sits next to
 * WordExtractor and follows its shape: a logger-only constructor, a public
 * extract(File), a guard that throws when the server lacks the zip extension,
 * and per-document failures degraded to null with a log line that carries no
 * document content (ADR-005). Where WordExtractor returns flat text for search,
 * this returns structure, so a consumer can turn one deck into one lesson draft
 * with a block per slide (pptx-structured-reader).
 *
 * The package is read with ZipArchive and DOMDocument rather than
 * phpoffice/phppresentation: no tagged release of that library installs next to
 * this app's phpoffice/phpspreadsheet ^5 (see the change's design.md). The result
 * does not expose the parser, so the internals can switch later.
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
 * @spec openspec/specs/text-extraction-presentation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\TextExtraction;

use Exception;
use OCP\Files\File;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Extracts structured slides from PowerPoint decks.
 *
 * @psalm-type PresentationSlide = array{
 *     number: int,
 *     hidden: bool,
 *     title: string,
 *     body: list<string>,
 *     notes: string,
 *     images: list<array{target: string, external: bool, name: string, description: string}>
 * }
 *
 * @spec openspec/specs/text-extraction-presentation/spec.md
 */
class PresentationExtractor {

	/**
	 * The most slides read from one deck; the result then says `truncated: true`.
	 *
	 * @var int
	 */
	public const MAX_SLIDES = 500;

	/**
	 * The most bytes read from any one XML part of the package (20 MiB).
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
		'application/vnd.openxmlformats-officedocument.presentationml.presentation',
		'application/vnd.ms-powerpoint.presentation.macroenabled.12',
		'application/vnd.openxmlformats-officedocument.presentationml.slideshow',
	];

	/**
	 * MIME types too generic to decide on; the file extension decides instead.
	 *
	 * @var list<string>
	 */
	private const GENERIC_MIME_TYPES = ['', 'application/octet-stream', 'application/zip', 'application/x-zip-compressed'];

	/**
	 * Extensions read when the MIME type is generic.
	 *
	 * @var list<string>
	 */
	private const SUPPORTED_EXTENSIONS = ['pptx', 'pptm', 'ppsx'];

	/**
	 * Turns slide and notes XML into fields.
	 *
	 * @var PresentationSlideParser
	 */
	private readonly PresentationSlideParser $slideParser;

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
		$this->slideParser = new PresentationSlideParser();
	}//end __construct()

	/**
	 * Whether a file is a format this extractor reads, by MIME type or, when that is generic, by extension.
	 *
	 * @param string $mimeType The file MIME type.
	 * @param string $fileName The file name.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/text-extraction-presentation/spec.md#requirement-the-supported-formats-can-be-asked-for-req-pptx-007
	 */
	public function supports(string $mimeType, string $fileName): bool {
		$mimeType = strtolower($mimeType);
		if (in_array($mimeType, self::SUPPORTED_MIME_TYPES, true) === true) {
			return true;
		}

		if (in_array($mimeType, self::GENERIC_MIME_TYPES, true) === false) {
			return false;
		}

		return in_array(strtolower(pathinfo($fileName, PATHINFO_EXTENSION)), self::SUPPORTED_EXTENSIONS, true);
	}//end supports()

	/**
	 * Read a deck into structured slides.
	 *
	 * @param File $file The deck.
	 *
	 * @return array{slides: list<PresentationSlide>, truncated: bool}|null The slides in deck order, or null when
	 *                                                                      the file is not a readable deck.
	 *
	 * @throws Exception When the server has no zip extension (a deployment error).
	 *
	 * @spec openspec/specs/text-extraction-presentation/spec.md#requirement-a-deck-that-cannot-be-read-degrades-to-no-result-req-pptx-005
	 */
	public function extract(File $file): ?array {
		if (class_exists(ZipArchive::class) === false) {
			$this->logger->warning(
				message: '[PresentationExtractor] PHP zip extension not available',
				context: ['file' => __FILE__, 'line' => __LINE__, 'fileId' => $file->getId()]
			);
			throw new Exception('The PHP zip extension is not installed. Install php-zip to read presentations.');
		}

		$mimeType = (string)$file->getMimeType();
		if ($this->supports(mimeType: $mimeType, fileName: (string)$file->getName()) === false) {
			$this->logger->debug(
				message: '[PresentationExtractor] Not a presentation format this extractor reads',
				context: ['file' => __FILE__, 'line' => __LINE__, 'fileId' => $file->getId(), 'mimeType' => $mimeType]
			);
			return null;
		}

		$tempFile = null;
		$zip = null;
		try {
			// Write the content to a temp file for ZipArchive to open, as WordExtractor does for PhpWord.
			$tempFile = tmpfile();
			fwrite($tempFile, $file->getContent());

			$zip = new ZipArchive();
			$opened = $zip->open(stream_get_meta_data($tempFile)['uri'], ZipArchive::RDONLY);
			if ($opened !== true) {
				$zip = null;
				throw new RuntimeException('Not a zip package (ZipArchive code ' . (int)$opened . ')');
			}

			$package = new OoxmlPackage(zip: $zip, maxPartBytes: self::MAX_PART_BYTES);
			$result = $this->readPresentation(package: $package);
			$this->logRefusedParts(package: $package, file: $file);

			if ($result === null || $result['slides'] === []) {
				$this->logger->warning(
					message: '[PresentationExtractor] Presentation holds no readable slides',
					context: ['file' => __FILE__, 'line' => __LINE__, 'fileId' => $file->getId(), 'mimeType' => $mimeType]
				);
				return null;
			}

			$this->logger->debug(
				message: '[PresentationExtractor] Presentation extracted',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'fileId' => $file->getId(),
					'slides' => count($result['slides']),
					'truncated' => $result['truncated'],
				]
			);

			return $result;
		} catch (Throwable $e) {
			// Per-document failure: log structure only, never document content (ADR-005).
			$this->logger->error(
				message: '[PresentationExtractor] Presentation extraction failed; returning null',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'fileId' => $file->getId(),
					'mimeType' => $mimeType,
					'exception' => get_class($e),
				]
			);
			return null;
		} finally {
			if ($zip !== null) {
				$zip->close();
			}

			if (is_resource($tempFile) === true) {
				fclose($tempFile);
			}
		}//end try
	}//end extract()

	/**
	 * Read the slide list and every slide, in deck order, up to MAX_SLIDES.
	 *
	 * @param OoxmlPackage $package The opened package.
	 *
	 * @return array{slides: list<PresentationSlide>, truncated: bool}|null Null when there is no presentation part.
	 *
	 * @spec openspec/specs/text-extraction-presentation/spec.md#requirement-slides-come-back-in-presentation-order-req-pptx-001
	 * @spec openspec/specs/text-extraction-presentation/spec.md#requirement-hostile-input-is-bounded-req-pptx-006
	 */
	private function readPresentation(OoxmlPackage $package): ?array {
		$mainPath = ($package->mainPartPath() ?? 'ppt/presentation.xml');
		$presentation = $package->readXml(path: $mainPath);
		if ($presentation === null) {
			return null;
		}

		$relationships = $package->relationships(partPath: $mainPath);
		$slides = [];
		$truncated = false;
		foreach ($this->slideParser->slideRelationshipIds(presentation: $presentation) as $position => $relationshipId) {
			if ($position >= self::MAX_SLIDES) {
				$truncated = true;
				break;
			}

			$relationship = ($relationships[$relationshipId] ?? null);
			if ($relationship === null || $relationship['external'] === true) {
				continue;
			}

			$slides[] = $this->readSlide(package: $package, path: $relationship['target'], number: ($position + 1));
		}

		return ['slides' => $slides, 'truncated' => $truncated];
	}//end readPresentation()

	/**
	 * Read one slide and its notes. An unreadable slide keeps its place with empty fields.
	 *
	 * @param OoxmlPackage $package The opened package.
	 * @param string $path The slide part path.
	 * @param int $number The slide's 1-based position in the deck.
	 *
	 * @return PresentationSlide
	 *
	 * @spec openspec/specs/text-extraction-presentation/spec.md#requirement-each-slide-carries-its-title-and-its-body-text-in-shape-order-req-pptx-002
	 */
	private function readSlide(OoxmlPackage $package, string $path, int $number): array {
		$slide = ['number' => $number, 'hidden' => false, 'title' => '', 'body' => [], 'notes' => '', 'images' => []];

		$document = $package->readXml(path: $path);
		if ($document === null) {
			return $slide;
		}

		$relationships = $package->relationships(partPath: $path);
		$parsed = $this->slideParser->parseSlide(slide: $document, relationships: $relationships);

		$slide['hidden'] = $parsed['hidden'];
		$slide['title'] = $parsed['title'];
		$slide['body'] = $parsed['body'];
		$slide['images'] = $parsed['images'];
		$slide['notes'] = $this->readNotes(package: $package, relationships: $relationships);

		return $slide;
	}//end readSlide()

	/**
	 * The speaker notes of a slide, found through its notesSlide relationship.
	 *
	 * @param OoxmlPackage $package The opened package.
	 * @param array<string, array{type: string, target: string, external: bool}> $relationships The slide's relationships.
	 *
	 * @return string The notes, or '' when the slide has none.
	 *
	 * @spec openspec/specs/text-extraction-presentation/spec.md#requirement-each-slide-carries-its-speaker-notes-req-pptx-003
	 */
	private function readNotes(OoxmlPackage $package, array $relationships): string {
		foreach ($relationships as $relationship) {
			if ($relationship['external'] === true || str_ends_with($relationship['type'], '/notesSlide') === false) {
				continue;
			}

			$document = $package->readXml(path: $relationship['target']);
			if ($document === null) {
				return '';
			}

			return $this->slideParser->parseNotes(notes: $document);
		}

		return '';
	}//end readNotes()

	/**
	 * Log the parts the package refused (names only, which are structure, not content).
	 *
	 * @param OoxmlPackage $package The package.
	 * @param File $file The deck.
	 *
	 * @return void
	 */
	private function logRefusedParts(OoxmlPackage $package, File $file): void {
		$refused = $package->refusedParts();
		if ($refused === []) {
			return;
		}

		$this->logger->warning(
			message: '[PresentationExtractor] Refused parts that were too large, declared a DOCTYPE or were not XML',
			context: ['file' => __FILE__, 'line' => __LINE__, 'fileId' => $file->getId(), 'parts' => $refused]
		);
	}//end logRefusedParts()
}//end class
