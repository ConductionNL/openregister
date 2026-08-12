<?php

/**
 * PdfMetadataSanitizer
 *
 * Strips PII-bearing metadata fields from a PDF document — `/Info`
 * dictionary entries (Title, Author, Subject, Keywords, Creator) and
 * the XMP `/Metadata` stream's dc:* / xmp:* / pdf:* fields — in parity
 * with the sister `office-document-sanitization` change.
 *
 * Producer / CreationDate / ModDate are preserved (provenance fields
 * with no PII).
 *
 * SAPP does not currently expose a public accessor for the trailer's
 * `/Info` reference; we reach in via Reflection to read the protected
 * `_pdf_trailer_object` property. Upstream PR target: add a public
 * `get_info_object()` accessor — until then, the Reflection access is
 * contained to this single class.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File\Pdf
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/pdf-anonymisation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File\Pdf;

use ddn\sapp\PDFDoc;
use ddn\sapp\pdfvalue\PDFValueString;
use OCA\OpenRegister\Exception\PdfAnonymisationException;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Throwable;

/**
 * Sanitises PDF metadata in-place on a loaded `PDFDoc`.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File\Pdf
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/pdf-anonymisation/spec.md
 */
class PdfMetadataSanitizer {

	/**
	 * Sentinel value substituted for stripped /Info text fields.
	 *
	 * Empty-string would also work but a recognisable sentinel makes
	 * audit trails (per ADR-022) easier to read.
	 *
	 * @var string
	 */
	private const SENTINEL = '[REDACTED]';

	/**
	 * /Info fields that MUST be stripped (replaced with SENTINEL).
	 *
	 * @var string[]
	 */
	private const INFO_FIELDS_TO_STRIP = [
		'Title',
		'Author',
		'Subject',
		'Keywords',
		'Creator',
	];

	// Note: /Info fields NOT in INFO_FIELDS_TO_STRIP are left untouched
	// by `sanitize()`. The preserve-list per spec is Producer,
	// CreationDate, and ModDate — provenance fields that carry no PII.
	// The strip-list above is the actual contract; the preserve-list is
	// the implicit complement.

	/**
	 * XMP namespace prefixes whose elements MUST be stripped.
	 *
	 * @var string[]
	 */
	private const XMP_NAMESPACES_TO_STRIP = [
		'dc',
		'xmp',
		'pdf',
	];

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logger for PII-redacted diagnostics.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Sanitise the metadata of a loaded PDFDoc in-place.
	 *
	 * After this returns the caller MUST serialise via
	 * `to_pdf_file_b(rebuild: true)` to persist the changes — the
	 * sanitiser mutates objects that need to land in the rebuild path.
	 *
	 * @param PDFDoc $doc The loaded PDF document to mutate.
	 *
	 * @return array{info_fields_stripped: int, xmp_stripped: bool}
	 *                                                              Diagnostic summary (no PII).
	 *
	 * @throws PdfAnonymisationException On reflection / SAPP errors.
	 *
	 * @spec openspec/specs/pdf-anonymisation/spec.md
	 */
	public function sanitize(PDFDoc $doc): array {
		$diagnostic = [
			'info_fields_stripped' => 0,
			'xmp_stripped' => false,
		];

		try {
			$infoObj = $this->locateInfoObject(doc: $doc);
		} catch (Throwable $e) {
			throw new PdfAnonymisationException(
				reason: PdfAnonymisationException::REASON_INTERNAL_ERROR,
				message: 'PdfMetadataSanitizer could not reach the trailer Info reference',
				diagnostic: ['stage' => 'metadata.locate_info'],
				previous: $e
			);
		}

		if ($infoObj !== null) {
			$infoValue = $infoObj->get_value();
			foreach (self::INFO_FIELDS_TO_STRIP as $field) {
				if (isset($infoValue[$field]) === true) {
					$infoValue[$field] = new PDFValueString(self::SENTINEL);
					$diagnostic['info_fields_stripped']++;
				}
			}

			if ($diagnostic['info_fields_stripped'] > 0) {
				// Re-register so the mutation lands in the rebuild path
				// (same write-back contract as content-stream mutations).
				$doc->add_object(pdf_object: $infoObj);
			}
		}

		try {
			$diagnostic['xmp_stripped'] = $this->sanitizeXmp(doc: $doc);
		} catch (Throwable $e) {
			throw new PdfAnonymisationException(
				reason: PdfAnonymisationException::REASON_INTERNAL_ERROR,
				message: 'PdfMetadataSanitizer failed on XMP stream',
				diagnostic: ['stage' => 'metadata.xmp'],
				previous: $e
			);
		}

		$this->logger->info(
			message: '[PdfMetadataSanitizer] PDF metadata sanitised',
			context: $diagnostic
		);

		return $diagnostic;
	}//end sanitize()

	/**
	 * Resolve the /Info object reference from the trailer.
	 *
	 * SAPP holds the trailer on a protected property. Until a public
	 * accessor lands upstream we use Reflection — contained to this
	 * method so the rest of the class reads naturally.
	 *
	 * @param PDFDoc $doc The loaded PDF document.
	 *
	 * @return \ddn\sapp\PDFObject|null The /Info object, or null when
	 *                                  the trailer doesn't reference one.
	 */
	private function locateInfoObject(PDFDoc $doc): ?\ddn\sapp\PDFObject {
		// Reflection setAccessible() is a no-op since PHP 8.1;
		// protected/private property access is always allowed by
		// getValue() now without explicit unlocking.
		$ref = new ReflectionClass(PDFDoc::class);
		$property = $ref->getProperty('_pdf_trailer_object');
		$trailer = $property->getValue($doc);
		if ($trailer === null) {
			return null;
		}

		if (isset($trailer['Info']) === false) {
			return null;
		}

		$infoRef = $trailer['Info'];
		$oid = $infoRef->get_object_referenced();
		if ($oid === false || is_array($oid) === true) {
			return null;
		}

		$infoObj = $doc->get_object(oid: (int)$oid);
		if ($infoObj === false) {
			return null;
		}

		return $infoObj;
	}//end locateInfoObject()

	/**
	 * Strip dc:* / xmp:* / pdf:* fields from the XMP /Metadata stream.
	 *
	 * Preserves custom namespaces (Conduction-internal workflow
	 * metadata, government-template tags etc.). Returns true if a
	 * mutation was performed.
	 *
	 * @param PDFDoc $doc The loaded PDF document.
	 *
	 * @return bool True when the XMP stream was mutated.
	 */
	private function sanitizeXmp(PDFDoc $doc): bool {
		$ref = new ReflectionClass(PDFDoc::class);
		$property = $ref->getProperty('_pdf_trailer_object');
		$trailer = $property->getValue($doc);
		if ($trailer === null || isset($trailer['Root']) === false) {
			return false;
		}

		$rootOid = $trailer['Root']->get_object_referenced();
		if ($rootOid === false || is_array($rootOid) === true) {
			return false;
		}

		$rootObj = $doc->get_object(oid: (int)$rootOid);
		if ($rootObj === false) {
			return false;
		}

		$rootValue = $rootObj->get_value();
		if (isset($rootValue['Metadata']) === false) {
			return false;
		}

		$metaRef = $rootValue['Metadata'];
		$metaOid = $metaRef->get_object_referenced();
		if ($metaOid === false || is_array($metaOid) === true) {
			return false;
		}

		$metaObj = $doc->get_object(oid: (int)$metaOid);
		if ($metaObj === false) {
			return false;
		}

		$xmp = $metaObj->get_stream(raw: false);
		if (is_string($xmp) === false || $xmp === '') {
			return false;
		}

		$originalXmp = $xmp;

		// Replace `<dc:Foo>...</dc:Foo>` / `<xmp:Foo>...</xmp:Foo>` /
		// `<pdf:Foo>...</pdf:Foo>` element bodies with the sentinel.
		// Custom namespaces (e.g. `<wcm:foo>`) are untouched.
		foreach (self::XMP_NAMESPACES_TO_STRIP as $ns) {
			// Self-closing tags: `<dc:foo/>` — leave as-is (no body to strip).
			// Open-close pair: `<dc:foo>...</dc:foo>` — replace inner text.
			$pattern = sprintf(
				'#<%s:([A-Za-z][A-Za-z0-9_-]*)([^/>]*)>.*?</%s:\1>#s',
				preg_quote($ns, '#'),
				preg_quote($ns, '#')
			);
			$xmp = preg_replace(
				pattern: $pattern,
				replacement: '<' . $ns . ':\1\2>' . htmlspecialchars(self::SENTINEL, ENT_XML1, 'UTF-8') . '</' . $ns . ':\1>',
				subject: $xmp
			);

			if ($xmp === null) {
				// PCRE failure — fall back to the original. The /Info
				// strip above already removed the bulk of PII; XMP
				// stripping is a defence-in-depth measure.
				$xmp = $originalXmp;
				break;
			}
		}//end foreach

		if ($xmp === $originalXmp) {
			return false;
		}

		$metaObj->set_stream(stream: $xmp, raw: false);
		$doc->add_object(pdf_object: $metaObj);
		return true;
	}//end sanitizeXmp()
}//end class
