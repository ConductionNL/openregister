<?php

/**
 * DocxSanitizationRun
 *
 * The state of one DocxSanitizer::sanitize() call: the open archive, the
 * per-category counters and the removal targets collected for the two shared
 * index parts ([Content_Types].xml and word/_rels/document.xml.rels).
 *
 * Built by its one owner, {@see DocxSanitizer::sanitize()}, for a single run
 * and never shared between runs.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File\Sanitizer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/office-document-sanitization/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File\Sanitizer;

use ZipArchive;

/**
 * One sanitisation run over an open DOCX archive.
 *
 * @spec openspec/specs/office-document-sanitization/spec.md
 */
final class DocxSanitizationRun {
	/**
	 * Per-category counters.
	 *
	 * @var array<string, int>
	 */
	private array $counts;

	/**
	 * Collected Override PartNames to drop (with leading slash).
	 *
	 * @var string[]
	 */
	private array $overridePartNames;

	/**
	 * Collected document.xml.rels Target basenames to drop.
	 *
	 * @var string[]
	 */
	private array $documentRelTargets;

	/**
	 * Constructor.
	 *
	 * @param ZipArchive $zip The open destination archive.
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	public function __construct(
		public readonly ZipArchive $zip,
	) {
		$this->counts = [
			'commentsRemoved' => 0,
			'trackedChangesAccepted' => 0,
			'trackedChangesDropped' => 0,
			'revisionAttributesStripped' => 0,
			'hyperlinksFlattened' => 0,
			'metadataFieldsScrubbed' => 0,
			'customXmlPartsDropped' => 0,
			'fieldCodesStripped' => 0,
		];
		$this->overridePartNames = [];
		$this->documentRelTargets = [];
	}//end __construct()

	/**
	 * Add to one category counter.
	 *
	 * @param string $category The counter name.
	 * @param int $amount The amount to add.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	public function count(string $category, int $amount = 1): void {
		$this->counts[$category] += $amount;
	}//end count()

	/**
	 * The counter map.
	 *
	 * @return array<string, int>
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	public function counts(): array {
		return $this->counts;
	}//end counts()

	/**
	 * Record a part whose [Content_Types].xml Override is to be dropped.
	 *
	 * @param string $partName The part name without leading slash.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	public function dropOverride(string $partName): void {
		$this->overridePartNames[] = '/' . $partName;
	}//end dropOverride()

	/**
	 * Record a document.xml.rels Target basename to drop.
	 *
	 * @param string $basename The Target basename.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	public function dropDocumentRelTarget(string $basename): void {
		$this->documentRelTargets[] = $basename;
	}//end dropDocumentRelTarget()

	/**
	 * The collected Override PartNames to drop.
	 *
	 * @return string[]
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	public function overridePartNames(): array {
		return $this->overridePartNames;
	}//end overridePartNames()

	/**
	 * The collected document.xml.rels Target basenames to drop.
	 *
	 * @return string[]
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	public function documentRelTargets(): array {
		return $this->documentRelTargets;
	}//end documentRelTargets()
}//end class
