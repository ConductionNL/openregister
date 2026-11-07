<?php

/**
 * OpenRegister Office Open XML package reader
 *
 * Bounded, read-only access to the parts and relationships of an Office Open
 * XML package (ECMA-376 part 2, Open Packaging Conventions) that is already
 * opened as a ZipArchive. Every part is treated as hostile input: it is read
 * only up to a size cap (never trusting the size the zip directory claims),
 * and any part that declares a DOCTYPE is refused, which closes entity
 * expansion and external entity loading together. Used by
 * PresentationExtractor (pptx-structured-reader) and DocumentExtractor
 * (docx-structured-reader).
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
 * @spec openspec/specs/text-extraction-presentation/spec.md#requirement-hostile-input-is-bounded-req-pptx-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\TextExtraction;

use DOMDocument;
use DOMElement;
use ZipArchive;

/**
 * Reads XML parts and relationships from an opened OOXML package, within bounds.
 *
 * @spec openspec/specs/text-extraction-presentation/spec.md#requirement-hostile-input-is-bounded-req-pptx-006
 */
class OoxmlPackage {

	/**
	 * Parts this reader refused (too large, a DOCTYPE, not XML), for the caller's log.
	 *
	 * @var list<string>
	 */
	private array $refusedParts = [];

	/**
	 * Constructor.
	 *
	 * @param ZipArchive $zip The opened package.
	 * @param int $maxPartBytes The most bytes read from any one part.
	 */
	public function __construct(
		private readonly ZipArchive $zip,
		private readonly int $maxPartBytes,
	) {
	}//end __construct()

	/**
	 * The path of the package's main part (the officeDocument relationship), or null.
	 *
	 * @return string|null E.g. `ppt/presentation.xml`.
	 *
	 * @spec openspec/specs/text-extraction-presentation/spec.md#requirement-a-deck-that-cannot-be-read-degrades-to-no-result-req-pptx-005
	 */
	public function mainPartPath(): ?string {
		foreach ($this->relationships(partPath: '') as $relationship) {
			if ($relationship['external'] === false && str_ends_with($relationship['type'], '/officeDocument') === true) {
				return $relationship['target'];
			}
		}

		return null;
	}//end mainPartPath()

	/**
	 * Parse one XML part, or null when it is missing, too large, declares a DOCTYPE or is not XML.
	 *
	 * @param string $path The part path inside the package, without a leading slash.
	 *
	 * @return DOMDocument|null
	 *
	 * @spec openspec/specs/text-extraction-presentation/spec.md#requirement-hostile-input-is-bounded-req-pptx-006
	 */
	public function readXml(string $path): ?DOMDocument {
		$xml = $this->rawPart(path: $path);
		if ($xml === null) {
			return null;
		}

		$document = null;
		if ($this->withinBounds(xml: $xml) === true) {
			$document = $this->parse(xml: $xml);
		}

		if ($document === null) {
			$this->refusedParts[] = $path;
		}

		return $document;
	}//end readXml()

	/**
	 * The relationships of a part, keyed by relationship id.
	 *
	 * Internal targets are resolved to package paths relative to the part's folder;
	 * a target marked external, or one that climbs above the package root, is kept
	 * as written and flagged external.
	 *
	 * @param string $partPath The part whose relationships to read; '' for the package itself.
	 *
	 * @return array<string, array{type: string, target: string, external: bool}>
	 *
	 * @spec openspec/specs/text-extraction-presentation/spec.md#requirement-each-slide-carries-its-image-references-in-shape-order-req-pptx-004
	 */
	public function relationships(string $partPath): array {
		$folder = $this->folderOf(path: $partPath);
		$document = $this->readXml(path: $this->relationshipsPathOf(partPath: $partPath));
		if ($document === null) {
			return [];
		}

		$relationships = [];
		foreach ($document->getElementsByTagNameNS('*', 'Relationship') as $element) {
			$relationships[$element->getAttribute('Id')] = $this->relationship(element: $element, folder: $folder);
		}

		return $relationships;
	}//end relationships()

	/**
	 * The parts this reader refused so far, so the caller can log their names (never their content).
	 *
	 * @return list<string>
	 *
	 * @spec openspec/specs/text-extraction-presentation/spec.md#requirement-hostile-input-is-bounded-req-pptx-006
	 */
	public function refusedParts(): array {
		return $this->refusedParts;
	}//end refusedParts()

	/**
	 * One relationship entry, with its target resolved.
	 *
	 * @param DOMElement $element The Relationship element.
	 * @param string $folder The folder of the part that owns the relationship.
	 *
	 * @return array{type: string, target: string, external: bool}
	 */
	private function relationship(DOMElement $element, string $folder): array {
		$target = $element->getAttribute('Target');
		if ($element->getAttribute('TargetMode') === 'External') {
			return ['type' => $element->getAttribute('Type'), 'target' => $target, 'external' => true];
		}

		$resolved = $this->resolve(folder: $folder, target: rawurldecode($target));
		if ($resolved === null) {
			return ['type' => $element->getAttribute('Type'), 'target' => $target, 'external' => true];
		}

		return ['type' => $element->getAttribute('Type'), 'target' => $resolved, 'external' => false];
	}//end relationship()

	/**
	 * The bytes of one part, read at most one byte past the cap, or null when
	 * the part is missing, unreadable or empty.
	 *
	 * Reading one byte past the cap proves a part is too large, whatever size
	 * the zip directory claims.
	 *
	 * @param string $path The part path inside the package, without a leading slash.
	 *
	 * @return string|null
	 */
	private function rawPart(string $path): ?string {
		$index = $this->zip->locateName($path, ZipArchive::FL_NOCASE);
		if ($index === false) {
			return null;
		}

		$xml = $this->zip->getFromIndex($index, ($this->maxPartBytes + 1));
		if ($xml === false || $xml === '') {
			return null;
		}

		return $xml;
	}//end rawPart()

	/**
	 * Whether part bytes fit the cap and carry no DOCTYPE.
	 *
	 * @param string $xml The part bytes.
	 *
	 * @return bool
	 */
	private function withinBounds(string $xml): bool {
		return strlen($xml) <= $this->maxPartBytes && stripos($xml, '<!DOCTYPE') === false;
	}//end withinBounds()

	/**
	 * Parse part bytes, or null when they are not XML, have no root or declare a DOCTYPE.
	 *
	 * The byte check in withinBounds() misses a DOCTYPE in a UTF-16 part; the parsed tree does not.
	 *
	 * @param string $xml The part bytes.
	 *
	 * @return DOMDocument|null
	 */
	private function parse(string $xml): ?DOMDocument {
		$previous = libxml_use_internal_errors(true);
		$document = new DOMDocument();
		$loaded = $document->loadXML($xml, (LIBXML_NONET | LIBXML_COMPACT));
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		if ($loaded === false || $document->documentElement === null || $document->doctype !== null) {
			return null;
		}

		return $document;
	}//end parse()

	/**
	 * The path of a part's relationships part.
	 *
	 * @param string $partPath The part path; '' for the package itself.
	 *
	 * @return string E.g. `ppt/slides/_rels/slide1.xml.rels`.
	 */
	private function relationshipsPathOf(string $partPath): string {
		if ($partPath === '') {
			return '_rels/.rels';
		}

		return ltrim($this->folderOf(path: $partPath) . '/_rels/' . basename($partPath) . '.rels', '/');
	}//end relationshipsPathOf()

	/**
	 * Resolve a relative (or package-absolute) target against a folder.
	 *
	 * @param string $folder The base folder, '' for the package root.
	 * @param string $target The target as written, e.g. `../media/image1.png`.
	 *
	 * @return string|null The package path, or null when it climbs above the root.
	 */
	private function resolve(string $folder, string $target): ?string {
		$combined = $folder . '/' . $target;
		if (str_starts_with($target, '/') === true) {
			$combined = $target;
		}

		$segments = [];
		foreach (explode('/', $combined) as $segment) {
			$segments = $this->applySegment(segments: $segments, segment: $segment);
			if ($segments === null) {
				return null;
			}
		}

		return implode('/', $segments);
	}//end resolve()

	/**
	 * Apply one path segment to the segments resolved so far.
	 *
	 * @param list<string> $segments The segments so far.
	 * @param string       $segment  The next segment.
	 *
	 * @return list<string>|null The new segments, or null when `..` climbs above the root.
	 */
	private function applySegment(array $segments, string $segment): ?array {
		if ($segment === '' || $segment === '.') {
			return $segments;
		}

		if ($segment !== '..') {
			$segments[] = $segment;
			return $segments;
		}

		if ($segments === []) {
			return null;
		}

		array_pop($segments);
		return $segments;
	}//end applySegment()

	/**
	 * The folder of a part path, '' for a part at the package root.
	 *
	 * @param string $path The part path.
	 *
	 * @return string
	 */
	private function folderOf(string $path): string {
		$folder = dirname($path);
		if ($folder === '.') {
			return '';
		}

		return $folder;
	}//end folderOf()
}//end class
