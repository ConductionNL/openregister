<?php

/**
 * OpenRegister XLSX sanitiser
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
use OCA\OpenRegister\Files\Service\File\SanitizationHiddenCounts;
use OCA\OpenRegister\Files\Service\File\SanitizationReport;
use OCA\OpenRegister\Files\Service\File\SanitizationRevisionCounts;
use OCA\OpenRegister\Files\Service\File\SanitizationStructureCounts;

/**
 * Takes out of a workbook what a reader of the visible sheets does not see.
 *
 * Hidden and very hidden sheets are removed with the parts only they used.
 * The cells of hidden rows and hidden columns are cleared, keeping the
 * layout. Comments, threaded comments and their authors, the shared-workbook
 * revision log, custom XML and the calculation chain are removed, and the
 * package metadata is scrubbed.
 *
 * Removing a cell does not remove its text: a string cell points into
 * `xl/sharedStrings.xml`. So every shared string no remaining cell points to
 * is emptied at the end, in place, so the indexes of the others stay valid.
 *
 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-spreadsheets-and-slides-are-sanitised-like-documents-req-rrs-003
 */
final class XlsxSanitizer implements SanitizerInterface {

	/**
	 * The XLSX MIME type.
	 */
	private const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

	/**
	 * SpreadsheetML main namespace.
	 */
	private const NS_S = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

	/**
	 * The workbook part.
	 */
	private const WORKBOOK = 'xl/workbook.xml';

	/**
	 * Relationship types (by their last path segment) whose parts hold comments.
	 */
	private const COMMENT_TYPES = ['comments', 'threadedComment', 'person'];

	/**
	 * The shared package steps, built with this sanitiser's sentinel.
	 *
	 * @var OoxmlCommonParts
	 */
	private readonly OoxmlCommonParts $common;

	/**
	 * Constructor.
	 *
	 * @param string $sentinel The sentinel applied to scrubbed metadata.
	 *
	 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-spreadsheets-and-slides-are-sanitised-like-documents-req-rrs-003
	 */
	public function __construct(
		private readonly string $sentinel = 'DocuDesk Anonymisation',
	) {
		$this->common = new OoxmlCommonParts(surgery: new OfficePartSurgery(sentinel: $sentinel));
	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $mimeType The file MIME type.
	 *
	 * @return bool True for the XLSX MIME type.
	 *
	 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-spreadsheets-and-slides-are-sanitised-like-documents-req-rrs-003
	 */
	public function supports(string $mimeType): bool {
		return $mimeType === self::MIME;
	}//end supports()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $sourcePath Path to the source .xlsx.
	 * @param string $destPath   Path to write the sanitised .xlsx.
	 *
	 * @return SanitizationReport Per-category counts.
	 *
	 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-spreadsheets-and-slides-are-sanitised-like-documents-req-rrs-003
	 */
	public function sanitize(string $sourcePath, string $destPath): SanitizationReport {
		$package = OoxmlPackage::read(path: $sourcePath);
		$counts  = ['sheets' => 0, 'rows' => 0, 'columns' => 0, 'comments' => 0, 'revisions' => 0];

		$counts['sheets'] = $this->removeHiddenSheets(package: $package);
		$sheets           = $this->sheetParts(package: $package);
		foreach ($sheets as $sheet) {
			$counts['comments'] += $this->removeSheetComments(package: $package, sheet: $sheet);
			[$rows, $columns]    = $this->clearHiddenCells(package: $package, sheet: $sheet);
			$counts['rows']     += $rows;
			$counts['columns']  += $columns;
		}

		// The threaded comments' author list names people; the revision log
		// keeps every edit with its author; the calculation chain lists the
		// cells of removed sheets.
		$counts['revisions'] = count($package->names(pattern: '#^xl/revisions/revisionLog\d*\.xml$#'));
		$this->removeWorkbookParts(package: $package, types: ['person', 'revisionHeaders', 'usernames', 'calcChain']);
		$customXml = $this->common->removeCustomXml(package: $package);
		$metadata  = $this->common->scrubMetadata(package: $package);
		$this->emptyUnreferencedStrings(package: $package, sheets: $sheets);

		$package->write(path: $destPath);

		return new SanitizationReport(
			revisions: new SanitizationRevisionCounts(commentsRemoved: $counts['comments'], trackedChangesDropped: $counts['revisions']),
			structure: new SanitizationStructureCounts(metadataFieldsScrubbed: $metadata, customXmlPartsDropped: $customXml),
			sentinelApplied: $this->sentinel,
			hidden: new SanitizationHiddenCounts(
				hiddenSheetsRemoved: $counts['sheets'],
				hiddenRowsCleared: $counts['rows'],
				hiddenColumnsCleared: $counts['columns']
			)
		);
	}//end sanitize()

	/**
	 * Remove every hidden and very hidden sheet, with the parts only it used.
	 *
	 * A defined name scoped to a removed sheet, or naming it in its formula,
	 * goes with it; the local scopes of the sheets after it shift down by one.
	 *
	 * @param OoxmlPackage $package The package.
	 *
	 * @return int The number of sheets removed.
	 */
	private function removeHiddenSheets(OoxmlPackage $package): int {
		$workbook = $package->dom(name: self::WORKBOOK);
		if ($workbook === null) {
			return 0;
		}

		$targets = $this->relationshipTargets(package: $package, part: self::WORKBOOK);
		$removed = [];
		$sheets  = iterator_to_array($workbook->getElementsByTagNameNS(self::NS_S, 'sheet'));
		foreach ($sheets as $index => $sheet) {
			assert($sheet instanceof DOMElement);
			if (in_array($sheet->getAttribute('state'), ['hidden', 'veryHidden'], true) === false) {
				continue;
			}

			$removed[$index] = $sheet->getAttribute('name');
			$id              = $sheet->getAttributeNS(OoxmlPackage::NS_R, 'id');
			$sheet->parentNode?->removeChild($sheet);
			$package->removeRelationships(part: self::WORKBOOK, ids: [$id]);
			if (isset($targets[$id]) === true) {
				$package->removeTree(name: $targets[$id]);
			}
		}

		if ($removed === []) {
			return 0;
		}

		$this->dropDefinedNames(workbook: $workbook, removed: $removed);
		$this->resetActiveTab(workbook: $workbook);
		$package->putDom(name: self::WORKBOOK, dom: $workbook);

		return count($removed);
	}//end removeHiddenSheets()

	/**
	 * Drop the defined names of removed sheets and renumber the local scopes of the rest.
	 *
	 * @param DOMDocument           $workbook The workbook.
	 * @param array<int, string>    $removed  The removed sheets' names, by their former index.
	 *
	 * @return void
	 */
	private function dropDefinedNames(DOMDocument $workbook, array $removed): void {
		foreach (iterator_to_array($workbook->getElementsByTagNameNS(self::NS_S, 'definedName')) as $name) {
			assert($name instanceof DOMElement);
			$scope = $name->getAttribute('localSheetId');
			if (($scope !== '' && isset($removed[(int) $scope]) === true) || $this->namesASheet(formula: $name->textContent, sheets: $removed) === true) {
				$name->parentNode?->removeChild($name);
				continue;
			}

			if ($scope !== '') {
				$before = count(array_filter(array_keys($removed), static fn (int $index): bool => $index < (int) $scope));
				$name->setAttribute('localSheetId', (string) ((int) $scope - $before));
			}
		}
	}//end dropDefinedNames()

	/**
	 * Whether a formula refers to one of the given sheets.
	 *
	 * @param string             $formula The defined name's formula.
	 * @param array<int, string> $sheets  Sheet names.
	 *
	 * @return bool
	 */
	private function namesASheet(string $formula, array $sheets): bool {
		foreach ($sheets as $sheet) {
			$quoted = "'" . str_replace("'", "''", $sheet) . "'!";
			if (str_contains($formula, $quoted) === true || str_contains($formula, $sheet . '!') === true) {
				return true;
			}
		}

		return false;
	}//end namesASheet()

	/**
	 * Point the workbook view at the first sheet, since the active one may be gone.
	 *
	 * @param DOMDocument $workbook The workbook.
	 *
	 * @return void
	 */
	private function resetActiveTab(DOMDocument $workbook): void {
		foreach (iterator_to_array($workbook->getElementsByTagNameNS(self::NS_S, 'workbookView')) as $view) {
			assert($view instanceof DOMElement);
			$view->removeAttribute('activeTab');
			$view->removeAttribute('firstSheet');
		}
	}//end resetActiveTab()

	/**
	 * The worksheet parts the workbook still lists.
	 *
	 * @param OoxmlPackage $package The package.
	 *
	 * @return array<int, string>
	 */
	private function sheetParts(OoxmlPackage $package): array {
		$parts = [];
		foreach ($package->relationships(part: self::WORKBOOK) as $relationship) {
			$target = $relationship['target'];
			if (str_ends_with($relationship['type'], '/worksheet') === true && $target !== null && $package->has(name: $target) === true) {
				$parts[] = $relationship['target'];
			}
		}

		return $parts;
	}//end sheetParts()

	/**
	 * Remove a sheet's comments, threaded comments and the drawing that shows them.
	 *
	 * @param OoxmlPackage $package The package.
	 * @param string       $sheet   The worksheet part.
	 *
	 * @return int The number of comments removed.
	 */
	private function removeSheetComments(OoxmlPackage $package, string $sheet): int {
		$count = 0;
		$ids   = [];
		$parts = [];
		foreach ($package->relationships(part: $sheet) as $relationship) {
			$type = basename($relationship['type']);
			if (in_array($type, self::COMMENT_TYPES, true) === false && $type !== 'vmlDrawing') {
				continue;
			}

			$ids[] = $relationship['id'];
			if ($relationship['target'] !== null) {
				$parts[] = $relationship['target'];
			}

			if ($type === 'comments' && $relationship['target'] !== null) {
				$comments = $package->dom(name: $relationship['target']);
				$count   += ($comments?->getElementsByTagNameNS(self::NS_S, 'comment')->length ?? 0);
			}
		}

		if ($ids === []) {
			return 0;
		}

		$dom = $package->dom(name: $sheet);
		if ($dom !== null) {
			foreach (iterator_to_array($dom->getElementsByTagNameNS(self::NS_S, 'legacyDrawing')) as $legacy) {
				$legacy->parentNode?->removeChild($legacy);
			}

			$package->putDom(name: $sheet, dom: $dom);
		}

		$package->removeRelationships(part: $sheet, ids: $ids);
		foreach ($parts as $part) {
			if ($package->isReferenced(name: $part) === false) {
				$package->removeTree(name: $part);
			}
		}

		return $count;
	}//end removeSheetComments()

	/**
	 * Clear the cells of a sheet's hidden rows and hidden columns.
	 *
	 * @param OoxmlPackage $package The package.
	 * @param string       $sheet   The worksheet part.
	 *
	 * @return array{0: int, 1: int} The hidden rows and the hidden columns cleared.
	 */
	private function clearHiddenCells(OoxmlPackage $package, string $sheet): array {
		$dom = $package->dom(name: $sheet);
		if ($dom === null) {
			return [0, 0];
		}

		$ranges  = [];
		$columns = 0;
		foreach (iterator_to_array($dom->getElementsByTagNameNS(self::NS_S, 'col')) as $col) {
			assert($col instanceof DOMElement);
			if ($this->isOn(value: $col->getAttribute('hidden')) === true) {
				$ranges[] = [(int) $col->getAttribute('min'), (int) $col->getAttribute('max')];
				$columns += max(0, ((int) $col->getAttribute('max') - (int) $col->getAttribute('min') + 1));
			}
		}

		$rows = 0;
		foreach (iterator_to_array($dom->getElementsByTagNameNS(self::NS_S, 'row')) as $row) {
			assert($row instanceof DOMElement);
			$hiddenRow = $this->isOn(value: $row->getAttribute('hidden'));
			$rows     += (int) $hiddenRow;
			foreach (iterator_to_array($row->getElementsByTagNameNS(self::NS_S, 'c')) as $cell) {
				assert($cell instanceof DOMElement);
				if ($hiddenRow === true || $this->inRanges(column: $this->columnOf(reference: $cell->getAttribute('r')), ranges: $ranges) === true) {
					$row->removeChild($cell);
				}
			}
		}

		$package->putDom(name: $sheet, dom: $dom);

		return [$rows, $columns];
	}//end clearHiddenCells()

	/**
	 * Whether a boolean attribute is set.
	 *
	 * @param string $value The attribute value.
	 *
	 * @return bool
	 */
	private function isOn(string $value): bool {
		return in_array(strtolower($value), ['1', 'true'], true);
	}//end isOn()

	/**
	 * The 1-based column number of a cell reference (`C7` is 3), 0 without one.
	 *
	 * @param string $reference The cell reference.
	 *
	 * @return int
	 */
	private function columnOf(string $reference): int {
		$letters = strtoupper((string) preg_replace('/[^A-Za-z]/', '', $reference));
		$column  = 0;
		foreach (str_split($letters) as $letter) {
			if ($letter !== '') {
				$column = ($column * 26 + (ord($letter) - 64));
			}
		}

		return $column;
	}//end columnOf()

	/**
	 * Whether a column falls in one of the ranges.
	 *
	 * @param int                               $column The column number.
	 * @param array<int, array{0: int, 1: int}> $ranges Inclusive column ranges.
	 *
	 * @return bool
	 */
	private function inRanges(int $column, array $ranges): bool {
		foreach ($ranges as [$min, $max]) {
			if ($column >= $min && $column <= $max) {
				return true;
			}
		}

		return false;
	}//end inRanges()

	/**
	 * Remove the workbook's parts of the given relationship types.
	 *
	 * @param OoxmlPackage       $package The package.
	 * @param array<int, string> $types   Relationship types, by their last path segment.
	 *
	 * @return int The number of relationships removed.
	 */
	private function removeWorkbookParts(OoxmlPackage $package, array $types): int {
		$ids   = [];
		$parts = [];
		foreach ($package->relationships(part: self::WORKBOOK) as $relationship) {
			if (in_array(basename($relationship['type']), $types, true) === true) {
				$ids[] = $relationship['id'];
				if ($relationship['target'] !== null) {
					$parts[] = $relationship['target'];
				}
			}
		}

		$package->removeRelationships(part: self::WORKBOOK, ids: $ids);
		foreach ($parts as $part) {
			$package->removeTree(name: $part);
		}

		return count($ids);
	}//end removeWorkbookParts()

	/**
	 * The workbook's relationship targets, by id.
	 *
	 * @param OoxmlPackage $package The package.
	 * @param string       $part    The source part.
	 *
	 * @return array<string, string>
	 */
	private function relationshipTargets(OoxmlPackage $package, string $part): array {
		$targets = [];
		foreach ($package->relationships(part: $part) as $relationship) {
			if ($relationship['target'] !== null) {
				$targets[$relationship['id']] = $relationship['target'];
			}
		}

		return $targets;
	}//end relationshipTargets()

	/**
	 * Empty every shared string no remaining cell points to, keeping the indexes.
	 *
	 * @param OoxmlPackage       $package The package.
	 * @param array<int, string> $sheets  The remaining worksheet parts.
	 *
	 * @return void
	 */
	private function emptyUnreferencedStrings(OoxmlPackage $package, array $sheets): void {
		$name    = 'xl/sharedStrings.xml';
		$strings = $package->dom(name: $name);
		if ($strings === null) {
			return;
		}

		$used = [];
		foreach ($sheets as $sheet) {
			foreach (iterator_to_array($package->dom(name: $sheet)?->getElementsByTagNameNS(self::NS_S, 'c') ?? []) as $cell) {
				assert($cell instanceof DOMElement);
				$value = $cell->getElementsByTagNameNS(self::NS_S, 'v')->item(0);
				if ($cell->getAttribute('t') === 's' && $value !== null) {
					$used[(int) trim($value->textContent)] = true;
				}
			}
		}

		foreach (iterator_to_array($strings->getElementsByTagNameNS(self::NS_S, 'si')) as $index => $item) {
			assert($item instanceof DOMElement);
			if (isset($used[$index]) === true) {
				continue;
			}

			while ($item->firstChild !== null) {
				$item->removeChild($item->firstChild);
			}

			$item->appendChild($strings->createElementNS(self::NS_S, 't'));
		}

		$package->putDom(name: $name, dom: $strings);
	}//end emptyUnreferencedStrings()
}//end class
