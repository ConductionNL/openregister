<?php

/**
 * OpenRegister BAG extract reader
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Bag
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Bag;

use Generator;
use InvalidArgumentException;

/**
 * Reads the address rows of a BAG extract file as nummeraanduiding data.
 *
 * Three layouts are read: a CSV with a header row (comma or semicolon), a
 * GeoJSON FeatureCollection or JSON list as the PDOK OGC API answers it, and
 * newline-delimited JSON. Column names are the schema's own
 * (`openbareRuimteNaam`) or the PDOK spelling (`openbare_ruimte_naam`,
 * `woonplaats_naam`, `toevoeging`, `adresseerbaar_object_identificatie`).
 * A row without an `identificatie` is skipped; every other value is passed on
 * as it is and normalised by the loader.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Bag
 *
 * @spec openspec/changes/bag-address-lookup-by-postcode/specs/bag-address-lookup/spec.md#requirement-the-bag-register-holds-the-national-address-set-req-bag-001
 */
final class BagExtractReader {

	/**
	 * The schema property each accepted column name fills.
	 *
	 * @var array<string, string>
	 */
	private const COLUMNS = [
		'identificatie' => 'identificatie',
		'nummeraanduiding' => 'identificatie',
		'nummeraanduiding_identificatie' => 'identificatie',
		'nummeraanduidingidentificatie' => 'identificatie',
		'huisnummer' => 'huisnummer',
		'huisletter' => 'huisletter',
		'huisnummertoevoeging' => 'huisnummertoevoeging',
		'toevoeging' => 'huisnummertoevoeging',
		'postcode' => 'postcode',
		'status' => 'status',
		'typeadresseerbaarobject' => 'typeAdresseerbaarObject',
		'type_adresseerbaar_object' => 'typeAdresseerbaarObject',
		'openbareruimtenaam' => 'openbareRuimteNaam',
		'openbare_ruimte_naam' => 'openbareRuimteNaam',
		'straat' => 'openbareRuimteNaam',
		'woonplaatsnaam' => 'woonplaatsNaam',
		'woonplaats_naam' => 'woonplaatsNaam',
		'woonplaats' => 'woonplaatsNaam',
		'adresseerbaarobject' => 'adresseerbaarObject',
		'adresseerbaar_object_identificatie' => 'adresseerbaarObject',
		'adresseerbaarobjectidentificatie' => 'adresseerbaarObject',
		'verblijfsobject' => 'adresseerbaarObject',
	];

	/**
	 * The rows of an extract file.
	 *
	 * @param string $path The extract file.
	 *
	 * @return Generator<int, array<string, mixed>> The rows, keyed by schema property.
	 *
	 * @throws InvalidArgumentException When the file cannot be read or is no extract.
	 *
	 * @spec openspec/changes/bag-address-lookup-by-postcode/specs/bag-address-lookup/spec.md#requirement-the-bag-register-holds-the-national-address-set-req-bag-001
	 */
	public function rows(string $path): Generator {
		$handle = false;
		if (is_file($path) === true && is_readable($path) === true) {
			$handle = fopen($path, 'rb');
		}

		if ($handle === false) {
			throw new InvalidArgumentException('The BAG extract cannot be read: ' . basename($path));
		}

		try {
			$first = self::firstCharacter(handle: $handle);
			rewind($handle);
			if ($first === '[' || $first === '{') {
				yield from self::jsonRows(handle: $handle, path: $path);
				return;
			}

			yield from self::csvRows(handle: $handle);
		} finally {
			fclose($handle);
		}
	}//end rows()

	/**
	 * One record of the extract as nummeraanduiding data, or null when it has no identificatie.
	 *
	 * @param array<array-key, mixed> $record The record, by column name.
	 *
	 * @return array<string, mixed>|null The data.
	 *
	 * @spec openspec/changes/bag-address-lookup-by-postcode/specs/bag-address-lookup/spec.md#requirement-the-bag-register-holds-the-national-address-set-req-bag-001
	 */
	public static function mapRecord(array $record): ?array {
		$data = [];
		foreach ($record as $column => $value) {
			$property = (self::COLUMNS[strtolower(trim((string) $column))] ?? null);
			if ($property === null || array_key_exists($property, $data) === true) {
				continue;
			}

			if (is_string($value) === true) {
				$value = trim($value);
			}

			if ($value === '' || $value === null) {
				continue;
			}

			$data[$property] = $value;
		}

		if (is_scalar($data['identificatie'] ?? null) === false || (string) $data['identificatie'] === '') {
			return null;
		}

		$data['identificatie'] = (string) $data['identificatie'];
		return $data;
	}//end mapRecord()

	/**
	 * The first non-blank character of a file, past a byte order mark.
	 *
	 * @param resource $handle The open file.
	 *
	 * @return string The character, or '' for an empty file.
	 */
	private static function firstCharacter($handle): string {
		$start = (string) fread($handle, 1024);
		$start = ltrim(str_replace("\xEF\xBB\xBF", '', $start));

		return substr($start, 0, 1);
	}//end firstCharacter()

	/**
	 * The rows of a CSV extract with a header row.
	 *
	 * @param resource $handle The open file.
	 *
	 * @return Generator<int, array<string, mixed>> The rows.
	 */
	private static function csvRows($handle): Generator {
		$header = fgets($handle);
		if ($header === false) {
			return;
		}

		$header    = str_replace("\xEF\xBB\xBF", '', rtrim($header, "\r\n"));
		$delimiter = ',';
		if (substr_count($header, ';') > substr_count($header, ',')) {
			$delimiter = ';';
		}

		$columns = str_getcsv($header, $delimiter, '"', '');
		$count   = count($columns);
		while (($values = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
			if ($values === [null] || count($values) !== $count) {
				continue;
			}

			$row = self::mapRecord(record: array_combine($columns, $values));
			if ($row !== null) {
				yield $row;
			}
		}
	}//end csvRows()

	/**
	 * The rows of a JSON extract: a FeatureCollection, a list, or one object per line.
	 *
	 * @param resource $handle The open file.
	 * @param string   $path   The file, for the error message.
	 *
	 * @return Generator<int, array<string, mixed>> The rows.
	 *
	 * @throws InvalidArgumentException When it is not JSON.
	 */
	private static function jsonRows($handle, string $path): Generator {
		$first = fgets($handle);
		$next  = fgets($handle);
		rewind($handle);
		$line = json_decode(trim((string) $first), true);
		if ($next !== false && is_array($line) === true && array_is_list($line) === false && self::isFeatureCollection(document: $line) === false) {
			while (($text = fgets($handle)) !== false) {
				$record = json_decode(trim($text), true);
				if (is_array($record) === true) {
					yield from self::recordRows(records: [$record]);
				}
			}

			return;
		}

		$document = json_decode((string) stream_get_contents($handle), true);
		if (is_array($document) === false) {
			throw new InvalidArgumentException('The BAG extract is not valid JSON: ' . basename($path));
		}

		if (self::isFeatureCollection(document: $document) === true) {
			$document = $document['features'];
		}

		if (array_is_list($document) === false) {
			$document = [$document];
		}

		yield from self::recordRows(records: $document);
	}//end jsonRows()

	/**
	 * Whether a decoded document is a GeoJSON FeatureCollection.
	 *
	 * @param array<array-key, mixed> $document The document.
	 *
	 * @return bool True when it carries a feature list.
	 */
	private static function isFeatureCollection(array $document): bool {
		return is_array($document['features'] ?? null) === true;
	}//end isFeatureCollection()

	/**
	 * The rows of decoded JSON records; a GeoJSON feature contributes its properties.
	 *
	 * @param array<array-key, mixed> $records The records.
	 *
	 * @return Generator<int, array<string, mixed>> The rows.
	 */
	private static function recordRows(array $records): Generator {
		foreach ($records as $record) {
			if (is_array($record) === false) {
				continue;
			}

			if (is_array($record['properties'] ?? null) === true) {
				$record = $record['properties'];
			}

			$row = self::mapRecord(record: $record);
			if ($row !== null) {
				yield $row;
			}
		}
	}//end recordRows()
}//end class
