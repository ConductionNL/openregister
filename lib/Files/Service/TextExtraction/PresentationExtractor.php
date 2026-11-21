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

namespace OCA\OpenRegister\Files\Service\TextExtraction;

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
	 * The MIME types and extensions this extractor reads.
	 *
	 * @var OoxmlFormatFilter
	 */
	private readonly OoxmlFormatFilter $formats;

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
		$this->slideParser = new PresentationSlideParser();
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
	 * @spec openspec/specs/text-extraction-presentation/spec.md#requirement-the-supported-formats-can-be-asked-for-req-pptx-007
	 */
	public function supports(string $mimeType, string $fileName): bool {
		return $this->formats->accepts(mimeType: $mimeType, fileName: $fileName);
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
		$this->assertZipAvailable(file: $file);
		$read = new PresentationRead(file: $file, mimeType: (string)$file->getMimeType());
		if ($this->supports(mimeType: $read->mimeType, fileName: (string)$file->getName()) === false) {
			$this->logger->debug(
				message: '[PresentationExtractor] Not a presentation format this extractor reads',
				context: $this->context(file: $file, extra: ['mimeType' => $read->mimeType])
			);
			return null;
		}

		return $this->extractSupported(read: $read);
	}//end extract()

	/**
	 * Refuse when the server has no zip extension (a deployment error).
	 *
	 * @param File $file The deck, for the log.
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
			message: '[PresentationExtractor] PHP zip extension not available',
			context: $this->context(file: $file)
		);
		throw new Exception('The PHP zip extension is not installed. Install php-zip to read presentations.');
	}//end assertZipAvailable()

	/**
	 * Read a deck of a supported format; any failure is logged and gives null.
	 *
	 * @param PresentationRead $read The read in progress.
	 *
	 * @return array{slides: list<PresentationSlide>, truncated: bool}|null
	 */
	private function extractSupported(PresentationRead $read): ?array {
		try {
			$package = $this->openPackage(read: $read);
			$result = $this->readPresentation(package: $package);
			$this->logRefusedParts(package: $package, file: $read->file);
			return $this->accepted(read: $read, result: $result);
		} catch (Throwable $e) {
			$this->logFailure(read: $read, error: $e);
			return null;
		} finally {
			$read->close();
		}
	}//end extractSupported()

	/**
	 * Write the deck to a temp file for ZipArchive to open, as WordTextReader does, and open it.
	 *
	 * @param PresentationRead $read The read in progress; receives the handles.
	 *
	 * @return OoxmlPackage
	 *
	 * @throws RuntimeException When the bytes are not a zip package.
	 */
	private function openPackage(PresentationRead $read): OoxmlPackage {
		$read->tempFile = tmpfile();
		fwrite($read->tempFile, $read->file->getContent());

		$zip = new ZipArchive();
		$opened = $zip->open(stream_get_meta_data($read->tempFile)['uri'], ZipArchive::RDONLY);
		if ($opened !== true) {
			throw new RuntimeException('Not a zip package (ZipArchive code ' . (int)$opened . ')');
		}

		$read->zip = $zip;
		return new OoxmlPackage(zip: $zip, maxPartBytes: self::MAX_PART_BYTES);
	}//end openPackage()

	/**
	 * The result when it holds slides (logged at debug), else null (logged as a warning).
	 *
	 * @param PresentationRead                                          $read   The read in progress.
	 * @param array{slides: list<PresentationSlide>, truncated: bool}|null $result The slides read, or null.
	 *
	 * @return array{slides: list<PresentationSlide>, truncated: bool}|null
	 */
	private function accepted(PresentationRead $read, ?array $result): ?array {
		if ($result === null || $result['slides'] === []) {
			$this->logger->warning(
				message: '[PresentationExtractor] Presentation holds no readable slides',
				context: $this->context(file: $read->file, extra: ['mimeType' => $read->mimeType])
			);
			return null;
		}

		$this->logger->debug(
			message: '[PresentationExtractor] Presentation extracted',
			context: $this->context(file: $read->file, extra: ['slides' => count($result['slides']), 'truncated' => $result['truncated']])
		);
		return $result;
	}//end accepted()

	/**
	 * Log a per-document failure: structure only, never document content (ADR-005).
	 *
	 * @param PresentationRead $read  The read in progress.
	 * @param Throwable        $error The failure.
	 *
	 * @return void
	 */
	private function logFailure(PresentationRead $read, Throwable $error): void {
		$this->logger->error(
			message: '[PresentationExtractor] Presentation extraction failed; returning null',
			context: $this->context(file: $read->file, extra: ['mimeType' => $read->mimeType, 'exception' => get_class($error)])
		);
	}//end logFailure()

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
		$ids = $this->slideParser->slideRelationshipIds(presentation: $presentation);

		return [
			'slides' => $this->readSlides(package: $package, targets: $this->slideTargets(ids: $ids, relationships: $relationships)),
			'truncated' => (count($ids) > self::MAX_SLIDES),
		];
	}//end readPresentation()

	/**
	 * The part paths of the slides to read, keyed by their 1-based deck position.
	 *
	 * Only the first MAX_SLIDES positions count; a missing or external relationship is skipped.
	 *
	 * @param list<string>                                                    $ids           Slide relationship ids in deck order.
	 * @param array<string, array{type: string, target: string, external: bool}> $relationships The presentation part's relationships.
	 *
	 * @return array<int, string>
	 */
	private function slideTargets(array $ids, array $relationships): array {
		$targets = [];
		foreach (array_slice($ids, 0, self::MAX_SLIDES) as $position => $relationshipId) {
			$relationship = ($relationships[$relationshipId] ?? null);
			if ($relationship !== null && $relationship['external'] === false) {
				$targets[($position + 1)] = $relationship['target'];
			}
		}

		return $targets;
	}//end slideTargets()

	/**
	 * Read the slides at the given positions, in order.
	 *
	 * @param OoxmlPackage       $package The opened package.
	 * @param array<int, string> $targets Slide part paths keyed by 1-based deck position.
	 *
	 * @return list<PresentationSlide>
	 */
	private function readSlides(OoxmlPackage $package, array $targets): array {
		$slides = [];
		foreach ($targets as $number => $path) {
			$slides[] = (['number' => $number] + $this->readSlide(package: $package, path: $path));
		}

		return $slides;
	}//end readSlides()

	/**
	 * Read one slide and its notes. An unreadable slide keeps its place with empty fields.
	 *
	 * @param OoxmlPackage $package The opened package.
	 * @param string       $path    The slide part path.
	 *
	 * @return array{
	 *     hidden: bool,
	 *     title: string,
	 *     body: list<string>,
	 *     notes: string,
	 *     images: list<array{target: string, external: bool, name: string, description: string}>
	 * } The slide's fields, without its number.
	 *
	 * @spec openspec/specs/text-extraction-presentation/spec.md#requirement-each-slide-carries-its-title-and-its-body-text-in-shape-order-req-pptx-002
	 */
	private function readSlide(OoxmlPackage $package, string $path): array {
		$document = $package->readXml(path: $path);
		if ($document === null) {
			return ['hidden' => false, 'title' => '', 'body' => [], 'notes' => '', 'images' => []];
		}

		$relationships = $package->relationships(partPath: $path);
		$parsed = $this->slideParser->parseSlide(slide: $document, relationships: $relationships);

		return [
			'hidden' => $parsed['hidden'],
			'title' => $parsed['title'],
			'body' => $parsed['body'],
			'notes' => $this->readNotes(package: $package, relationships: $relationships),
			'images' => $parsed['images'],
		];
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
			context: $this->context(file: $file, extra: ['parts' => $refused])
		);
	}//end logRefusedParts()

	/**
	 * The log context for a deck: source location, file id, then any extra keys.
	 *
	 * @param File                 $file  The deck.
	 * @param array<string, mixed> $extra Extra context keys.
	 *
	 * @return array<string, mixed>
	 */
	private function context(File $file, array $extra = []): array {
		return array_merge(['file' => __FILE__, 'line' => __LINE__, 'fileId' => $file->getId()], $extra);
	}//end context()
}//end class
