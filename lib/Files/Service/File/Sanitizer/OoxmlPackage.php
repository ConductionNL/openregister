<?php

/**
 * OpenRegister OOXML package, held in memory for sanitisation
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service\File\Sanitizer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-spreadsheets-and-slides-are-sanitised-like-documents-req-rrs-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File\Sanitizer;

use DOMDocument;
use DOMElement;
use OCA\OpenRegister\Exception\SanitizationException;
use ZipArchive;

/**
 * Every part of an OOXML package, read once, changed in memory, written once.
 *
 * The DOCX sanitiser works on an open ZipArchive and has to collect its index
 * edits for one reconciliation at the end, because ZipArchive does not show a
 * pending write to a later read. A spreadsheet or a presentation removes whole
 * part trees (a hidden sheet with its comments and drawings, a hidden slide
 * with its notes), each touching `[Content_Types].xml` and several `.rels`
 * files, so these two sanitisers read the package into memory instead and
 * write it back in one go.
 *
 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-spreadsheets-and-slides-are-sanitised-like-documents-req-rrs-003
 */
final class OoxmlPackage {

	/**
	 * The OPC relationships namespace.
	 */
	public const NS_RELS = 'http://schemas.openxmlformats.org/package/2006/relationships';

	/**
	 * The officeDocument relationships namespace (`r:id`).
	 */
	public const NS_R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

	/**
	 * The content types part.
	 */
	private const CONTENT_TYPES = '[Content_Types].xml';

	/**
	 * The parts, by name, in their original order.
	 *
	 * @var array<string, string>
	 */
	private array $parts = [];

	/**
	 * Read a package from disk.
	 *
	 * @param string $path The package file.
	 *
	 * @return self
	 *
	 * @throws SanitizationException When the file is not a readable OOXML package.
	 *
	 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-spreadsheets-and-slides-are-sanitised-like-documents-req-rrs-003
	 */
	public static function read(string $path): self {
		$zip = new ZipArchive();
		if ($zip->open($path) !== true) {
			throw new SanitizationException(reason: SanitizationException::REASON_CORRUPT_ZIP, message: 'Document is not a valid zip package');
		}

		$package = new self();
		for ($index = 0; $index < $zip->numFiles; $index++) {
			$name  = $zip->getNameIndex($index);
			$bytes = $zip->getFromIndex($index);
			if ($name === false || $bytes === false) {
				$zip->close();
				throw new SanitizationException(reason: SanitizationException::REASON_CORRUPT_ZIP, message: 'Document package has an unreadable part');
			}

			$package->parts[$name] = $bytes;
		}

		$zip->close();

		if ($package->has(name: self::CONTENT_TYPES) === false) {
			throw new SanitizationException(reason: SanitizationException::REASON_ENCRYPTED, message: 'Document is encrypted or not a valid OOXML package');
		}

		return $package;
	}//end read()

	/**
	 * Write the package to disk, replacing what is there.
	 *
	 * @param string $path The destination.
	 *
	 * @return void
	 *
	 * @throws SanitizationException When the package cannot be written.
	 *
	 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-spreadsheets-and-slides-are-sanitised-like-documents-req-rrs-003
	 */
	public function write(string $path): void {
		$zip = new ZipArchive();
		if ($zip->open($path, (ZipArchive::CREATE | ZipArchive::OVERWRITE)) !== true) {
			throw new SanitizationException(reason: SanitizationException::REASON_INTERNAL, message: 'The sanitised package could not be written');
		}

		foreach ($this->parts as $name => $bytes) {
			$zip->addFromString($name, $bytes);
		}

		if ($zip->close() !== true) {
			throw new SanitizationException(reason: SanitizationException::REASON_INTERNAL, message: 'The sanitised package could not be written');
		}
	}//end write()

	/**
	 * Whether the package has a part.
	 *
	 * @param string $name The part name.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-spreadsheets-and-slides-are-sanitised-like-documents-req-rrs-003
	 */
	public function has(string $name): bool {
		return array_key_exists($name, $this->parts);
	}//end has()

	/**
	 * A part's bytes, or null when absent.
	 *
	 * @param string $name The part name.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-spreadsheets-and-slides-are-sanitised-like-documents-req-rrs-003
	 */
	public function get(string $name): ?string {
		return ($this->parts[$name] ?? null);
	}//end get()

	/**
	 * Replace or add a part.
	 *
	 * @param string $name  The part name.
	 * @param string $bytes The bytes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-spreadsheets-and-slides-are-sanitised-like-documents-req-rrs-003
	 */
	public function put(string $name, string $bytes): void {
		$this->parts[$name] = $bytes;
	}//end put()

	/**
	 * The part names, optionally those matching a pattern.
	 *
	 * @param string|null $pattern A PCRE pattern the name must match.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-spreadsheets-and-slides-are-sanitised-like-documents-req-rrs-003
	 */
	public function names(?string $pattern = null): array {
		$names = array_map('strval', array_keys($this->parts));
		if ($pattern === null) {
			return $names;
		}

		return array_values(array_filter($names, static fn (string $name): bool => preg_match($pattern, $name) === 1));
	}//end names()

	/**
	 * Parse a part as XML, or null when absent.
	 *
	 * @param string $name The part name.
	 *
	 * @return DOMDocument|null
	 *
	 * @throws SanitizationException When the part is not well-formed XML.
	 *
	 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-spreadsheets-and-slides-are-sanitised-like-documents-req-rrs-003
	 */
	public function dom(string $name): ?DOMDocument {
		$xml = $this->get(name: $name);
		if ($xml === null || trim($xml) === '') {
			return null;
		}

		$dom = new DOMDocument();
		$dom->preserveWhiteSpace = true;
		$dom->formatOutput       = false;
		if ($dom->loadXML($xml, (LIBXML_NONET | LIBXML_COMPACT)) === false) {
			throw new SanitizationException(reason: SanitizationException::REASON_CORRUPT_ZIP, message: 'Document part is not well-formed XML');
		}

		return $dom;
	}//end dom()

	/**
	 * Store a parsed part.
	 *
	 * @param string      $name The part name.
	 * @param DOMDocument $dom  The document.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-spreadsheets-and-slides-are-sanitised-like-documents-req-rrs-003
	 */
	public function putDom(string $name, DOMDocument $dom): void {
		$this->put(name: $name, bytes: (string) $dom->saveXML());
	}//end putDom()

	/**
	 * The relationships file of a part (`xl/_rels/workbook.xml.rels` for `xl/workbook.xml`).
	 *
	 * @param string $part The source part name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-spreadsheets-and-slides-are-sanitised-like-documents-req-rrs-003
	 */
	public static function relsName(string $part): string {
		$directory = dirname($part);
		$prefix    = '';
		if ($directory !== '.') {
			$prefix = $directory . '/';
		}

		return $prefix . '_rels/' . basename($part) . '.rels';
	}//end relsName()

	/**
	 * The relationships of a part, with each internal target resolved to a part name.
	 *
	 * @param string $part The source part name.
	 *
	 * @return array<int, array{id: string, type: string, target: string|null}> `target` is null for an external one.
	 *
	 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-spreadsheets-and-slides-are-sanitised-like-documents-req-rrs-003
	 */
	public function relationships(string $part): array {
		$dom = $this->dom(name: self::relsName(part: $part));
		if ($dom === null) {
			return [];
		}

		$relationships = [];
		foreach (iterator_to_array($dom->getElementsByTagNameNS(self::NS_RELS, 'Relationship')) as $element) {
			assert($element instanceof DOMElement);
			$target = null;
			if (strtolower($element->getAttribute('TargetMode')) !== 'external') {
				$target = self::resolve(source: $part, target: $element->getAttribute('Target'));
			}

			$relationships[] = ['id' => $element->getAttribute('Id'), 'type' => $element->getAttribute('Type'), 'target' => $target];
		}

		return $relationships;
	}//end relationships()

	/**
	 * Remove relationships of a part by id.
	 *
	 * @param string             $part The source part name.
	 * @param array<int, string> $ids  The relationship ids.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-spreadsheets-and-slides-are-sanitised-like-documents-req-rrs-003
	 */
	public function removeRelationships(string $part, array $ids): void {
		$relsName = self::relsName(part: $part);
		$dom      = $this->dom(name: $relsName);
		if ($dom === null || $ids === []) {
			return;
		}

		foreach (iterator_to_array($dom->getElementsByTagNameNS(self::NS_RELS, 'Relationship')) as $element) {
			assert($element instanceof DOMElement);
			if (in_array($element->getAttribute('Id'), $ids, true) === true) {
				$element->parentNode?->removeChild($element);
			}
		}

		$this->putDom(name: $relsName, dom: $dom);
	}//end removeRelationships()

	/**
	 * Remove a part, its relationships file and its content-type override, then
	 * every internal part it pointed to that nothing else still points to.
	 *
	 * @param string $name The part name.
	 *
	 * @return array<int, string> Every part removed, the given one first.
	 *
	 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-spreadsheets-and-slides-are-sanitised-like-documents-req-rrs-003
	 */
	public function removeTree(string $name): array {
		if ($this->has(name: $name) === false) {
			return [];
		}

		$targets = array_filter(array_column($this->relationships(part: $name), 'target'));
		unset($this->parts[$name], $this->parts[self::relsName(part: $name)]);
		$this->removeOverride(name: $name);

		$removed = [$name];
		foreach (array_unique($targets) as $target) {
			if ($this->isReferenced(name: (string) $target) === false) {
				$removed = array_merge($removed, $this->removeTree(name: (string) $target));
			}
		}

		return $removed;
	}//end removeTree()

	/**
	 * Whether any remaining relationships file points to a part.
	 *
	 * @param string $name The part name.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-spreadsheets-and-slides-are-sanitised-like-documents-req-rrs-003
	 */
	public function isReferenced(string $name): bool {
		foreach ($this->names(pattern: '#(^|/)_rels/[^/]+\.rels$#') as $relsName) {
			$source = $this->sourceOf(relsName: $relsName);
			if (in_array($name, array_column($this->relationships(part: $source), 'target'), true) === true) {
				return true;
			}
		}

		return false;
	}//end isReferenced()

	/**
	 * Remove the content-type override of a part.
	 *
	 * @param string $name The part name.
	 *
	 * @return void
	 */
	private function removeOverride(string $name): void {
		$dom = $this->dom(name: self::CONTENT_TYPES);
		if ($dom === null) {
			return;
		}

		foreach (iterator_to_array($dom->getElementsByTagName('Override')) as $element) {
			assert($element instanceof DOMElement);
			if (ltrim($element->getAttribute('PartName'), '/') === $name) {
				$element->parentNode?->removeChild($element);
			}
		}

		$this->putDom(name: self::CONTENT_TYPES, dom: $dom);
	}//end removeOverride()

	/**
	 * The part a relationships file belongs to (`` for the package root).
	 *
	 * @param string $relsName The relationships file.
	 *
	 * @return string
	 */
	private function sourceOf(string $relsName): string {
		$directory = dirname(dirname($relsName));
		$prefix    = '';
		if ($directory !== '.') {
			$prefix = $directory . '/';
		}

		return $prefix . substr(basename($relsName), 0, -strlen('.rels'));
	}//end sourceOf()

	/**
	 * A relationship target as a part name: relative to the source's folder, or absolute from the root.
	 *
	 * @param string $source The source part name.
	 * @param string $target The relationship's Target.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-spreadsheets-and-slides-are-sanitised-like-documents-req-rrs-003
	 */
	public static function resolve(string $source, string $target): string {
		if (str_starts_with($target, '/') === true) {
			return ltrim($target, '/');
		}

		$directory = dirname($source);
		$segments  = [];
		if ($directory !== '.' && $directory !== '') {
			$segments = explode('/', $directory);
		}

		foreach (explode('/', $target) as $segment) {
			if ($segment === '..') {
				array_pop($segments);
			} elseif ($segment !== '.' && $segment !== '') {
				$segments[] = $segment;
			}
		}

		return implode('/', $segments);
	}//end resolve()
}//end class
