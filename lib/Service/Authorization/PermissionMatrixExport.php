<?php

/**
 * OpenRegister PermissionMatrixExport
 *
 * A sealed permission matrix: its provenance, row count and hash, and the rows
 * as a stream of CSV or JSON chunks.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Authorization
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-an-administrator-exports-the-full-authorisation-matrix-with-provenance-req-rah-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Authorization;

use Closure;
use Generator;

/**
 * The export as the response sends it.
 *
 * The hash is over the CSV lines of the rows in both formats, so a CSV and a
 * JSON export of the same matrix carry the same hash, and so does the audit
 * row written when it was produced.
 *
 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-an-administrator-exports-the-full-authorisation-matrix-with-provenance-req-rah-001
 */
final class PermissionMatrixExport {

	public const FORMAT_CSV = 'csv';

	public const FORMAT_JSON = 'json';

	/**
	 * Constructor.
	 *
	 * @param PermissionMatrixProvenance                                     $provenance Who, when, where and how.
	 * @param array{rowCount: int, sha256: string, objectGrantsListed: bool} $seal       The count and hash of the rows.
	 * @param Closure(): Generator<int, array<string, string>>               $rows       Generates the rows afresh.
	 *
	 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-an-administrator-exports-the-full-authorisation-matrix-with-provenance-req-rah-001
	 */
	public function __construct(
		public readonly PermissionMatrixProvenance $provenance,
		private readonly array $seal,
		private readonly Closure $rows,
	) {
	}//end __construct()

	/**
	 * The number of rows.
	 *
	 * @return int The count.
	 *
	 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-an-administrator-exports-the-full-authorisation-matrix-with-provenance-req-rah-001
	 */
	public function rowCount(): int {
		return $this->seal['rowCount'];
	}//end rowCount()

	/**
	 * The SHA-256 of the rows' CSV lines.
	 *
	 * @return string The hex digest.
	 *
	 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-an-administrator-exports-the-full-authorisation-matrix-with-provenance-req-rah-001
	 */
	public function sha256(): string {
		return $this->seal['sha256'];
	}//end sha256()

	/**
	 * The header fields, in the order they are printed.
	 *
	 * @return array<string, string|int|bool> Field to value.
	 *
	 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-an-administrator-exports-the-full-authorisation-matrix-with-provenance-req-rah-001
	 */
	public function header(): array {
		return [
			'generatedAt' => $this->provenance->generatedAt,
			'generatedBy' => $this->provenance->generatedBy,
			'instanceId' => $this->provenance->instanceId,
			'level' => $this->provenance->level,
			'denyEnforcement' => $this->provenance->denyEnforcement,
			'objectGrantsListed' => $this->seal['objectGrantsListed'],
			'rowCount' => $this->seal['rowCount'],
			'sha256' => $this->seal['sha256'],
		];
	}//end header()

	/**
	 * The export as chunks of text, generated row by row.
	 *
	 * @param string $format `csv` or `json`.
	 *
	 * @return Generator<int, string> The chunks.
	 *
	 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-an-administrator-exports-the-full-authorisation-matrix-with-provenance-req-rah-001
	 */
	public function chunks(string $format): Generator {
		if ($format === self::FORMAT_JSON) {
			yield from $this->jsonChunks();
			return;
		}

		yield from $this->csvChunks();
	}//end chunks()

	/**
	 * The CSV: the header as `#` comment lines, the column line, then the rows.
	 *
	 * @return Generator<int, string> The chunks.
	 */
	private function csvChunks(): Generator {
		$lines = '';
		foreach ($this->header() as $field => $value) {
			$lines .= '# '.$field.': '.$this->scalar(value: $value)."\n";
		}

		yield $lines.implode(',', PermissionMatrixExporter::COLUMNS)."\n";

		foreach (($this->rows)() as $row) {
			yield PermissionMatrixExporter::csvLine(row: $row);
		}
	}//end csvChunks()

	/**
	 * The JSON: the header fields, then `rows` as an array streamed one row at a time.
	 *
	 * @return Generator<int, string> The chunks.
	 */
	private function jsonChunks(): Generator {
		$head = json_encode($this->header(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		yield substr($head, 0, -1).',"rows":[';

		$separator = '';
		foreach (($this->rows)() as $row) {
			yield $separator.json_encode($row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
			$separator = ',';
		}

		yield ']}';
	}//end jsonChunks()

	/**
	 * A header value as text.
	 *
	 * @param string|int|bool $value The value.
	 *
	 * @return string The text.
	 */
	private function scalar(string|int|bool $value): string {
		if (is_bool($value) === true) {
			return json_encode($value);
		}

		return (string)$value;
	}//end scalar()
}//end class
