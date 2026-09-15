<?php

/**
 * OpenRegister MDTO Bestand Generator
 *
 * Generates the MDTO document that describes one file.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Edepot
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-generated-mdto-documents-must-validate-against-the-vendored-mdto-xml-1-0-1-xsd
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot;

use DOMElement;

/**
 * One MDTO `bestand` document per file.
 *
 * MDTO-XML 1.0.1 lets an `MDTO` document hold one `informatieobject` or one
 * `bestand`, never both, and the MDTO SIP specification says "Elk
 * informatieobject en elk bestand heeft zijn eigen MDTO metagegevensbestand",
 * placed next to the file and named `<bestandsnaam>.bestand.MDTO.xml`.
 *
 * ## Where each required value comes from
 *
 * - `identificatie`: the file's SHA-256, with `identificatieBron` "SHA-256".
 *   MDTO lets a kenmerk be assigned "met behulp van een bepaalde
 *   systematiek", and a content hash is one: always available, stable for the
 *   bytes, and not invented. The file references reaching this class carry no
 *   other stable identifier.
 * - `bestandsformaat`: the IANA media type, shaped as the standard's own
 *   example shows it (code `application/msword`, label `msword`, list
 *   "IANA Media types"). MDTO prefers a PRONOM id; openregister does not
 *   identify PRONOM formats, and the standard names media types as the
 *   alternative.
 * - `checksumDatum`: the moment the checksum was computed, which
 *   {@see EdepotTransferService} records as it hashes the bytes it packages.
 * - `isRepresentatieVan`: the informatieobject this file belongs to, by the
 *   same naam and identificatie that document carries for itself.
 *
 * @psalm-suppress UnusedClass
 */
class MdtoBestandGenerator {

	/**
	 * `identificatieBron` for a content-hash identification.
	 */
	public const IDENTIFICATION_SOURCE = 'SHA-256';

	/**
	 * `checksumAlgoritme` label, as the ChecksumAlgoritme begrippenlijst spells it.
	 */
	public const CHECKSUM_ALGORITHM = 'SHA-256';

	/**
	 * The begrippenlijst the checksum algorithm label comes from.
	 *
	 * Named as the Nationaal Archief's own `Bestand` example document names it.
	 */
	public const CHECKSUM_ALGORITHM_LIST = 'Begrippenlijst ChecksumAlgoritme MDTO';

	/**
	 * The begrippenlijst a media type comes from, as the standard's example names it.
	 */
	public const MEDIA_TYPE_LIST = 'IANA Media types';

	/**
	 * Suffix the MDTO SIP specification prescribes for a file's sidecar.
	 */
	public const SIDECAR_SUFFIX = '.bestand.MDTO.xml';

	/**
	 * Constructor.
	 *
	 * @param MdtoDocumentWriter $writer The XML primitives.
	 */
	public function __construct(
		private readonly MdtoDocumentWriter $writer,
	) {
	}//end __construct()

	/**
	 * Generate the `bestand` document for one file.
	 *
	 * @param array{name: string, size: int|string, format: string, checksum: string, checksumDate: string} $file The file.
	 * @param array{naam: string, kenmerk: string, bron: string} $represents The informatieobject it represents.
	 *
	 * @return string The MDTO XML.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-generated-mdto-documents-must-validate-against-the-vendored-mdto-xml-1-0-1-xsd
	 */
	public function generate(array $file, array $represents): string {
		[$dom, $bestand] = $this->writer->createDocument(kind: 'bestand');

		$identification = $this->identification(file: $file);
		$this->writer->identificatie(
			parent: $bestand,
			name: 'identificatie',
			kenmerk: $identification['kenmerk'],
			bron: $identification['bron']
		);
		$this->writer->text(parent: $bestand, name: 'naam', content: (string)$file['name']);
		$this->writer->text(parent: $bestand, name: 'omvang', content: (string)(int)$file['size']);
		$this->addFormat(parent: $bestand, mediaType: (string)$file['format']);
		$this->addChecksum(parent: $bestand, file: $file);
		$this->writer->verwijzing(
			parent: $bestand,
			name: 'isRepresentatieVan',
			verwijzingNaam: $represents['naam'],
			kenmerk: $represents['kenmerk'],
			bron: $represents['bron']
		);

		return $this->writer->serialise(dom: $dom);
	}//end generate()

	/**
	 * The identification a file carries, here and in `heeftRepresentatie`.
	 *
	 * One method, used by both documents, so the reference from the
	 * informatieobject and the identification on the bestand cannot drift.
	 *
	 * @param array{checksum: string} $file The file.
	 *
	 * @return array{kenmerk: string, bron: string} The identification.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-generated-mdto-documents-must-validate-against-the-vendored-mdto-xml-1-0-1-xsd
	 */
	public function identification(array $file): array {
		return ['kenmerk' => strtolower((string)$file['checksum']), 'bron' => self::IDENTIFICATION_SOURCE];
	}//end identification()

	/**
	 * Name the inputs a `bestand` document cannot be built without.
	 *
	 * Each maps to an element the XSD's `bestandType` marks `minOccurs="1"`,
	 * checked for the lexical form its XSD type demands, so a bad value is
	 * refused here rather than written into a document the schema rejects:
	 *
	 * - `name` for `naam`; `size` for `omvang`, an `xsd:integer`;
	 * - `format` for `bestandsformaat`;
	 * - `checksum` for `checksumWaarde`, which must be a SHA-256 because the
	 *   document declares that algorithm, and a value that is not one would
	 *   make the declaration false;
	 * - `checksumDate` for `checksumDatum`, an `xsd:dateTime`.
	 *
	 * @param array $files The files.
	 *
	 * @return list<string> One entry per missing or malformed input.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-mdto-generator-must-report-the-limits-of-its-own-validation
	 */
	public function missingInputs(array $files): array {
		$missing = [];

		foreach ($files as $index => $file) {
			$prefix = 'file[' . $index . '].';
			foreach (['name', 'format'] as $key) {
				if (($file[$key] ?? '') === '') {
					$missing[] = $prefix . $key;
				}
			}

			if (preg_match('/^\d+$/', (string)($file['size'] ?? '')) !== 1) {
				$missing[] = $prefix . 'size (a non-negative integer)';
			}

			if (preg_match('/^[0-9a-fA-F]{64}$/', (string)($file['checksum'] ?? '')) !== 1) {
				$missing[] = $prefix . 'checksum (a SHA-256)';
			}

			if (self::isXsdDateTime(value: ($file['checksumDate'] ?? null)) === false) {
				$missing[] = $prefix . 'checksumDate (an xsd:dateTime)';
			}
		}

		return $missing;
	}//end missingInputs()

	/**
	 * Add `bestandsformaat` for a media type.
	 *
	 * @param DOMElement $parent The bestand element.
	 * @param string $mediaType The IANA media type, possibly with parameters.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-generated-mdto-documents-must-validate-against-the-vendored-mdto-xml-1-0-1-xsd
	 */
	private function addFormat(DOMElement $parent, string $mediaType): void {
		$type = strtolower(trim(explode(';', $mediaType)[0]));
		$slash = strpos($type, '/');

		$label = $type;
		if ($slash !== false) {
			$label = substr($type, ($slash + 1));
		}

		$this->writer->begrip(
			parent: $parent,
			name: 'bestandsformaat',
			label: $label,
			code: $type,
			list: self::MEDIA_TYPE_LIST
		);
	}//end addFormat()

	/**
	 * Add the `checksum` group.
	 *
	 * @param DOMElement $parent The bestand element.
	 * @param array{checksum: string, checksumDate: string} $file The file.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-generated-mdto-documents-must-validate-against-the-vendored-mdto-xml-1-0-1-xsd
	 */
	private function addChecksum(DOMElement $parent, array $file): void {
		$checksum = $this->writer->element(parent: $parent, name: 'checksum');

		$this->writer->begrip(
			parent: $checksum,
			name: 'checksumAlgoritme',
			label: self::CHECKSUM_ALGORITHM,
			code: null,
			list: self::CHECKSUM_ALGORITHM_LIST
		);
		$this->writer->text(parent: $checksum, name: 'checksumWaarde', content: strtolower((string)$file['checksum']));
		$this->writer->text(parent: $checksum, name: 'checksumDatum', content: (string)$file['checksumDate']);
	}//end addChecksum()

	/**
	 * Whether a value is in the lexical space of `xsd:dateTime`.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True when it is.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-generated-mdto-documents-must-validate-against-the-vendored-mdto-xml-1-0-1-xsd
	 */
	private static function isXsdDateTime(mixed $value): bool {
		if (is_string($value) === false) {
			return false;
		}

		return preg_match('/^-?\d{4,}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})?$/', $value) === 1;
	}//end isXsdDateTime()
}//end class
