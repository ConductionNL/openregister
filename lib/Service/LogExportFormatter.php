<?php

/**
 * OpenRegister LogExportFormatter
 *
 * Shapes audit trail entries into export rows and renders them as a file.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

// No strict_types: the export rendered values with PHP's scalar coercion
// (an integer id through htmlspecialchars()) when it lived in LogService, and
// still does, so no exported file changes.

namespace OCA\OpenRegister\Service;

use InvalidArgumentException;
use JsonSerializable;
use SimpleXMLElement;

/**
 * The audit export's rows and file formats, extracted from LogService.
 *
 * The entries it receives are already masked for explicit-reveal values:
 * masking stays LogService's concern and happens before any row is shaped.
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-be-exportable-for-compliance-audits
 */
final class LogExportFormatter {

	/**
	 * Fields every exported row carries, in column order. The chain fields
	 * travel on every row, so an exported list can be checked against the
	 * chain without the database.
	 */
	private const BASIC_FIELDS = [
		'id', 'uuid', 'action', 'object', 'register', 'schema', 'user', 'userName', 'created', 'size',
		'hash', 'previousHash',
	];

	/**
	 * Fields a row carries when metadata is requested, after the changes.
	 */
	private const METADATA_FIELDS = ['session', 'request', 'ipAddress', 'version'];

	/**
	 * The MIME type of each export file extension.
	 */
	private const CONTENT_TYPES = [
		'csv' => 'text/csv',
		'json' => 'application/json',
		'xml' => 'application/xml',
		'txt' => 'text/plain',
	];

	/**
	 * Export rows of (masked) audit entries.
	 *
	 * @param array<int, mixed>    $entries Audit entries (AuditTrail entities or arrays).
	 * @param array<string, mixed> $config  includeChanges (default true), includeMetadata (default false).
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-be-exportable-for-compliance-audits
	 */
	public function rows(array $entries, array $config): array {
		$flags = [
			'changes' => (($config['includeChanges'] ?? true) === true),
			'metadata' => (($config['includeMetadata'] ?? false) === true),
		];

		$rows = [];
		foreach ($entries as $entry) {
			$rows[] = self::row(logData: self::dataOf(entry: $entry), flags: $flags);
		}

		return $rows;
	}//end rows()

	/**
	 * Render rows as a file in one of csv, json, xml, txt (case-insensitive).
	 *
	 * @param string                           $format The format.
	 * @param array<int, array<string, mixed>> $rows   The rows.
	 *
	 * @return array{content: bool|string, filename: string, contentType: string} The file.
	 *
	 * @throws InvalidArgumentException When the format is not supported.
	 *
	 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-be-exportable-for-compliance-audits
	 */
	public function render(string $format, array $rows): array {
		return match (strtolower($format)) {
			'csv' => self::toCsv(data: $rows),
			'json' => self::file(content: json_encode($rows, JSON_PRETTY_PRINT), extension: 'json'),
			'xml' => self::toXml(data: $rows),
			'txt' => self::toTxt(data: $rows),
			default => throw new InvalidArgumentException("Unsupported export format: {$format}"),
		};
	}//end render()

	/**
	 * An entry's data: its serialization when it has one, else the entry itself.
	 *
	 * @param mixed $entry The entry.
	 *
	 * @return mixed The data.
	 */
	private static function dataOf(mixed $entry): mixed {
		if ($entry instanceof JsonSerializable) {
			return $entry->jsonSerialize();
		}

		return $entry;
	}//end dataOf()

	/**
	 * One export row.
	 *
	 * @param mixed                $logData The entry's data.
	 * @param array<string, bool>  $flags   Whether changes and metadata are included.
	 *
	 * @return array<string, mixed> The row.
	 */
	private static function row(mixed $logData, array $flags): array {
		$row = self::pick(data: $logData, fields: self::BASIC_FIELDS);
		if ($flags['changes'] === true && empty($logData['changed']) === false) {
			$row['changes'] = self::formatChanges(changed: $logData['changed']);
		}

		if ($flags['metadata'] === true) {
			$row = array_merge($row, self::pick(data: $logData, fields: self::METADATA_FIELDS));
		}

		return $row;
	}//end row()

	/**
	 * The named fields of the data, an absent one as ''.
	 *
	 * @param mixed              $data   The entry's data.
	 * @param array<int, string> $fields The fields.
	 *
	 * @return array<string, mixed> The fields' values.
	 */
	private static function pick(mixed $data, array $fields): array {
		$picked = [];
		foreach ($fields as $field) {
			$picked[$field] = ($data[$field] ?? '');
		}

		return $picked;
	}//end pick()

	/**
	 * Changes as JSON when they are an array, else as text.
	 *
	 * @param mixed $changed Changed data.
	 *
	 * @return string Formatted changes.
	 */
	private static function formatChanges(mixed $changed): string {
		if (is_array($changed) === true) {
			return json_encode($changed);
		}

		return (string)$changed;
	}//end formatChanges()

	/**
	 * A rendered file, named by the time of export.
	 *
	 * @param bool|string $content   The content.
	 * @param string      $extension The file extension, which names the MIME type.
	 *
	 * @return array{content: bool|string, filename: string, contentType: string} The file.
	 */
	private static function file(bool|string $content, string $extension): array {
		return [
			'content' => $content,
			'filename' => 'audit_trails_' . date('Y-m-d_H-i-s') . '.' . $extension,
			'contentType' => self::CONTENT_TYPES[$extension],
		];
	}//end file()

	/**
	 * Rows as CSV with a header line; no rows is an empty file.
	 *
	 * @param array<int, array<string, mixed>> $data The rows.
	 *
	 * @return array{content: bool|string, filename: string, contentType: string} The file.
	 */
	private static function toCsv(array $data): array {
		$content = '';
		if (empty($data) === false) {
			$content = self::csvOf(data: $data);
		}

		return self::file(content: $content, extension: 'csv');
	}//end toCsv()

	/**
	 * CSV text of non-empty rows.
	 *
	 * @param array<int, array<string, mixed>> $data The rows.
	 *
	 * @return false|string The CSV.
	 */
	private static function csvOf(array $data): false|string {
		$output = fopen('php://temp', 'r+');
		fputcsv($output, array_keys($data[0]));
		foreach ($data as $row) {
			fputcsv($output, $row);
		}

		rewind($output);
		$content = stream_get_contents($output);
		fclose($output);

		return $content;
	}//end csvOf()

	/**
	 * Rows as XML, one auditTrail element each.
	 *
	 * @param array<int, array<string, mixed>> $data The rows.
	 *
	 * @return array{content: bool|string, filename: string, contentType: string} The file.
	 */
	private static function toXml(array $data): array {
		$xml = new SimpleXMLElement('<auditTrails/>');
		foreach ($data as $logData) {
			self::xmlEntry(element: $xml->addChild('auditTrail'), logData: $logData);
		}

		return self::file(content: $xml->asXML(), extension: 'xml');
	}//end toXml()

	/**
	 * One row's fields as children of its element, with names made valid XML.
	 *
	 * @param SimpleXMLElement     $element The row's element.
	 * @param array<string, mixed> $logData The row.
	 *
	 * @return void
	 */
	private static function xmlEntry(SimpleXMLElement $element, array $logData): void {
		foreach ($logData as $key => $value) {
			$element->addChild(
				qualifiedName: preg_replace('/[^a-zA-Z0-9_]/', '_', $key),
				value: htmlspecialchars($value ?? '')
			);
		}
	}//end xmlEntry()

	/**
	 * Rows as plain text under a generation header.
	 *
	 * @param array<int, array<string, mixed>> $data The rows.
	 *
	 * @return array{content: bool|string, filename: string, contentType: string} The file.
	 */
	private static function toTxt(array $data): array {
		$content = 'Audit Trail Export - Generated on ' . date('Y-m-d H:i:s') . "\n";
		$content .= str_repeat('=', 60) . "\n\n";
		foreach ($data as $index => $logData) {
			$content .= self::txtEntry(index: (int)$index, logData: $logData);
		}

		return self::file(content: $content, extension: 'txt');
	}//end toTxt()

	/**
	 * One row as a numbered text block.
	 *
	 * @param int                  $index   The row's index.
	 * @param array<string, mixed> $logData The row.
	 *
	 * @return string The block.
	 */
	private static function txtEntry(int $index, array $logData): string {
		$content = 'Entry #' . ($index + 1) . "\n";
		$content .= str_repeat('-', 20) . "\n";
		foreach ($logData as $key => $value) {
			$content .= ucfirst($key) . ': ' . ($value ?? 'N/A') . "\n";
		}

		return $content . "\n";
	}//end txtEntry()
}//end class
