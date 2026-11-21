<?php

/**
 * OpenRegister RedactionPackageRouteReader
 *
 * Splits a written OOXML or ODF package into the regions each leak route lives in.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Anonymisation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/redaction-release-safeguards/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Anonymisation;


use ZipArchive;

/**
 * The regions of a ZIP package (DOCX, ODT, XLSX, PPTX), one per leak route,
 * lower-cased.
 *
 * Parts are routed by name (thumbnails, document properties, comments,
 * revision logs, embedded files) and by content (XMP packets, tracked
 * deletions, ODF annotations and tracked changes, form field data). Text
 * under the mark searches every part, with its markup also read as text.
 *
 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-the-written-copy-cannot-be-read-back-req-rrs-001-carried-from-filinq-req-rwb-01
 */
final class RedactionPackageRouteReader {

	use RedactionRegionCutter;

	/**
	 * Part names that are previews.
	 */
	private const PREVIEW_PARTS = '#(^|/)thumbnails?/|(^|/)thumbnail\.#';

	/**
	 * Part names that are document properties or media carrying EXIF.
	 */
	private const PROPERTY_PARTS = '#^docprops/(core|app|custom)\.xml$|^meta\.xml$|(^|/)media/|^pictures/#';

	/**
	 * Part names that hold comments and their authors.
	 */
	private const ANNOTATION_PARTS = '#(^|/)comments[^/]*\.xml$|threadedcomments|(^|/)comments/|commentauthors|(^|/)persons/|people\.xml$#';

	/**
	 * Part names that are revision logs.
	 */
	private const REVISION_PARTS = '#(^|/)revisions/#';

	/**
	 * Part names that are files embedded in the package.
	 */
	private const ATTACHMENT_PARTS = '#(^|/)embeddings/|^object \d+/|\.bin$#';

	/**
	 * Marker pairs of tracked deletions and earlier revisions inside a part.
	 *
	 * @var list<array{0: string, 1: string}>
	 */
	private const REVISION_MARKERS = [
		['<w:del ', '</w:del>'],
		['<w:deltext', '</w:deltext>'],
		['<w:movefrom ', '</w:movefrom>'],
		['<text:tracked-changes', '</text:tracked-changes>'],
	];

	/**
	 * Marker pairs of annotations and form fields inside a part.
	 *
	 * @var list<array{0: string, 1: string}>
	 */
	private const ANNOTATION_MARKERS = [
		['<office:annotation', '</office:annotation>'],
		['<w:ffdata', '</w:ffdata>'],
		['<form:', '>'],
	];

	/**
	 * The regions of a package, or null when the bytes are not a readable ZIP.
	 *
	 * @param string $bytes The written file.
	 *
	 * @return array<string, string>|null The region of each route.
	 *
	 * @spec openspec/changes/redaction-release-safeguards/tasks.md#task-1.1
	 */
	public function regions(string $bytes): ?array {
		$parts = $this->parts(bytes: $bytes);
		if ($parts === null) {
			return null;
		}

		$regions = array_fill_keys(RedactionLeakRoute::ALL, '');
		foreach ($parts as $name => $content) {
			$regions = $this->route(regions: $regions, name: $name, content: $content);
		}

		return array_map(fn (string $region): string => $this->withText(region: $region), $regions);
	}//end regions()

	/**
	 * Add one part to the regions it belongs to.
	 *
	 * @param array<string, string> $regions The regions so far.
	 * @param string                $name    The lower-cased part name.
	 * @param string                $content The lower-cased part.
	 *
	 * @return array<string, string> The regions.
	 */
	private function route(array $regions, string $name, string $content): array {
		$regions[RedactionLeakRoute::TEXT_UNDER_MARK] .= $content . "\n";
		$regions[RedactionLeakRoute::XMP]             .= $this->between(haystack: $content, start: '<x:xmpmeta', end: '</x:xmpmeta>');

		$byName = [
			RedactionLeakRoute::EMBEDDED_PREVIEW => self::PREVIEW_PARTS,
			RedactionLeakRoute::EXIF => self::PROPERTY_PARTS,
			RedactionLeakRoute::ANNOTATIONS_AND_FIELDS => self::ANNOTATION_PARTS,
			RedactionLeakRoute::INCREMENTAL_UPDATE => self::REVISION_PARTS,
			RedactionLeakRoute::EMBEDDED_ATTACHMENT => self::ATTACHMENT_PARTS,
		];
		foreach ($byName as $route => $pattern) {
			if (preg_match($pattern, $name) === 1) {
				$regions[$route] .= $content . "\n";
			}
		}

		$regions[RedactionLeakRoute::INCREMENTAL_UPDATE]     .= $this->marked(content: $content, markers: self::REVISION_MARKERS);
		$regions[RedactionLeakRoute::ANNOTATIONS_AND_FIELDS] .= $this->marked(content: $content, markers: self::ANNOTATION_MARKERS);

		return $regions;
	}//end route()

	/**
	 * The regions of every marker pair in one part.
	 *
	 * @param string                            $content The lower-cased part.
	 * @param list<array{0: string, 1: string}> $markers The marker pairs.
	 *
	 * @return string The regions.
	 */
	private function marked(string $content, array $markers): string {
		$out = '';
		foreach ($markers as $pair) {
			$out .= $this->between(haystack: $content, start: $pair[0], end: $pair[1]);
		}

		return $out;
	}//end marked()

	/**
	 * Every part of the package, lower-cased, by lower-cased name.
	 *
	 * An embedded file that is itself a package has its parts appended, so a
	 * name inside an attached spreadsheet is found.
	 *
	 * @param string $bytes The package.
	 * @param int    $depth How deep inside embedded packages this is.
	 *
	 * @return array<string, string>|null The parts, or null when unreadable.
	 */
	private function parts(string $bytes, int $depth = 0): ?array {
		if (str_starts_with($bytes, "PK\x03\x04") === false) {
			return null;
		}

		$path = tempnam(sys_get_temp_dir(), 'or-verify-');
		if ($path === false) {
			return null;
		}

		try {
			file_put_contents($path, $bytes);
			$zip = new ZipArchive();
			if ($zip->open($path, ZipArchive::RDONLY) !== true) {
				return null;
			}

			$parts = $this->read(zip: $zip, depth: $depth);
			$zip->close();
		} finally {
			if (file_exists($path) === true) {
				unlink($path);
			}
		}

		return $parts;
	}//end parts()

	/**
	 * Read every entry of an open archive.
	 *
	 * @param ZipArchive $zip   The archive.
	 * @param int        $depth How deep inside embedded packages this is.
	 *
	 * @return array<string, string>|null The parts, or null when an entry cannot be read.
	 */
	private function read(ZipArchive $zip, int $depth): ?array {
		$parts = [];
		for ($index = 0; $index < $zip->numFiles; $index++) {
			$name    = strtolower((string) $zip->getNameIndex($index));
			$content = $zip->getFromIndex($index);
			if ($content === false) {
				return null;
			}

			$parts[$name] = strtolower($content);
			if ($depth < 2 && str_starts_with($content, "PK\x03\x04") === true) {
				$parts[$name] .= "\n" . implode("\n", ($this->parts(bytes: $content, depth: ($depth + 1)) ?? []));
			}
		}

		return $parts;
	}//end read()
}//end class
