<?php

/**
 * VocabularyCsvReader — turns a CSV value-list export into SKOS nodes.
 *
 * Produces the same (scheme node, concept nodes) shape the JSON-LD path
 * parses, so {@see VocabularyImportService} runs one upsert pipeline for both.
 * Expected columns: `uri` (required), `notation`, `prefLabel_<lang>`,
 * `definition`, `broaderUri` (pipe-`|`-separated broader concept uris).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/skos-concept-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use InvalidArgumentException;

/**
 * Reads CSV value lists into SKOS nodes.
 */
class VocabularyCsvReader {
	/**
	 * Build the scheme node from the caller's scheme metadata.
	 *
	 * @param array<string,string> $schemeMeta `uri` (required), `title`, `publisher`, `version`, `source`.
	 *
	 * @return array<string,mixed> The scheme node.
	 *
	 * @throws InvalidArgumentException When the scheme uri is absent or blank.
	 */
	public function schemeNode(array $schemeMeta): array {
		$schemeUri = $schemeMeta['uri'] ?? null;
		if (is_string($schemeUri) === false || trim($schemeUri) === '') {
			throw new InvalidArgumentException('CSV import requires a non-empty schemeMeta["uri"].');
		}

		return $this->describeScheme(schemeUri: $schemeUri, schemeMeta: $schemeMeta);
	}//end schemeNode()

	/**
	 * The scheme node for a validated scheme uri; absent metadata falls back to the uri or a default.
	 *
	 * @param string               $schemeUri  The scheme uri, as given.
	 * @param array<string,string> $schemeMeta `title`, `publisher`, `version`, `source`.
	 *
	 * @return array<string,mixed> The scheme node.
	 */
	private function describeScheme(string $schemeUri, array $schemeMeta): array {
		return [
			'@id' => $schemeUri,
			'dct:title' => ($schemeMeta['title'] ?? $schemeUri),
			'dct:publisher' => ($schemeMeta['publisher'] ?? ''),
			'owl:versionInfo' => ($schemeMeta['version'] ?? '1.0'),
			'dct:source' => ($schemeMeta['source'] ?? $schemeUri),
		];
	}//end describeScheme()

	/**
	 * Read every concept row of the CSV file into a concept node.
	 *
	 * @param string $csvPath Path to the CSV file.
	 *
	 * @return array<int,array<string,mixed>> The concept nodes.
	 *
	 * @throws InvalidArgumentException When the file is missing, unreadable or empty.
	 */
	public function conceptNodes(string $csvPath): array {
		$handle = $this->open(csvPath: $csvPath);
		$header = $this->readRow(handle: $handle);
		if ($header === false) {
			fclose($handle);
			throw new InvalidArgumentException(sprintf('CSV value-list file is empty: %s', $csvPath));
		}

		$header = array_map(static fn (string $col): string => trim($col), $header);
		$nodes = $this->readConceptRows(handle: $handle, header: $header);
		fclose($handle);
		return $nodes;
	}//end conceptNodes()

	/**
	 * Read the remaining rows into concept nodes, skipping blank lines and rows without uri.
	 *
	 * @param resource          $handle The open file handle, past the header.
	 * @param array<int,string> $header The trimmed header columns.
	 *
	 * @return array<int,array<string,mixed>> The concept nodes.
	 */
	private function readConceptRows($handle, array $header): array {
		$nodes = [];
		while (($row = $this->readRow(handle: $handle)) !== false) {
			$nodes[] = $this->rowNode(header: $header, row: $row);
		}

		return array_values(array_filter($nodes, static fn (?array $node): bool => $node !== null));
	}//end readConceptRows()

	/**
	 * Open the CSV file for reading.
	 *
	 * @param string $csvPath Path to the CSV file.
	 *
	 * @return resource
	 *
	 * @throws InvalidArgumentException When the file is missing or cannot be opened.
	 */
	private function open(string $csvPath) {
		if (is_file($csvPath) === false) {
			throw new InvalidArgumentException(sprintf('CSV value-list file not found: %s', $csvPath));
		}

		$handle = fopen($csvPath, 'rb');
		if ($handle === false) {
			throw new InvalidArgumentException(sprintf('Unable to open CSV value-list file: %s', $csvPath));
		}

		return $handle;
	}//end open()

	/**
	 * Read the next CSV row.
	 *
	 * @param resource $handle The open file handle.
	 *
	 * @return array<int,string|null>|false The row, or false at end of file.
	 */
	private function readRow($handle): array|false {
		return fgetcsv(stream: $handle, length: null, separator: ',', enclosure: '"', escape: '');
	}//end readRow()

	/**
	 * One CSV row as a concept node; null for a blank line or a row without uri.
	 *
	 * @param array<int,string> $header The trimmed header columns.
	 * @param array<int,string|null> $row The raw row.
	 *
	 * @return array<string,mixed>|null
	 */
	private function rowNode(array $header, array $row): ?array {
		if (count($row) === 1 && trim((string)$row[0]) === '') {
			return null;
		}

		return $this->conceptNode(rowAssoc: array_combine($header, array_pad($row, count($header), null)));
	}//end rowNode()

	/**
	 * A row keyed by header as a concept node; null when it has no uri.
	 *
	 * @param array<string,string|null> $rowAssoc The row keyed by header.
	 *
	 * @return array<string,mixed>|null
	 */
	private function conceptNode(array $rowAssoc): ?array {
		$uri = trim((string)($rowAssoc['uri'] ?? ''));
		if ($uri === '') {
			return null;
		}

		return [
			'@id' => $uri,
			'skos:notation' => ($rowAssoc['notation'] ?? null),
			'skos:definition' => ($rowAssoc['definition'] ?? null),
			'skos:prefLabel' => $this->prefLabels(rowAssoc: $rowAssoc),
			'skos:broader' => $this->broaderRefs(rowAssoc: $rowAssoc),
		];
	}//end conceptNode()

	/**
	 * The non-blank `prefLabel_<lang>` columns as a language map.
	 *
	 * @param array<string,string|null> $rowAssoc The row keyed by header.
	 *
	 * @return array<string,string>
	 */
	private function prefLabels(array $rowAssoc): array {
		$prefLabel = [];
		foreach ($rowAssoc as $column => $value) {
			if (str_starts_with($column, 'prefLabel_') === true && trim((string)$value) !== '') {
				$prefLabel[substr($column, strlen('prefLabel_'))] = trim((string)$value);
			}
		}

		return $prefLabel;
	}//end prefLabels()

	/**
	 * The `broaderUri` column as a list of reference nodes.
	 *
	 * @param array<string,string|null> $rowAssoc The row keyed by header.
	 *
	 * @return array<int,array{'@id': string}>
	 */
	private function broaderRefs(array $rowAssoc): array {
		if (trim((string)($rowAssoc['broaderUri'] ?? '')) === '') {
			return [];
		}

		return array_map(
			static fn (string $broaderUri): array => ['@id' => trim($broaderUri)],
			explode('|', (string)$rowAssoc['broaderUri'])
		);
	}//end broaderRefs()
}//end class
