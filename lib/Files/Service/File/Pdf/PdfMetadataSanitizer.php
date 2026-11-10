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

namespace OCA\OpenRegister\Files\Service\File\Pdf;

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
			$diagnostic['info_fields_stripped'] = $this->stripInfoFields(doc: $doc, infoObj: $infoObj);
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
	 * Replace the strip-listed /Info fields with the sentinel.
	 *
	 * @param PDFDoc $doc The loaded PDF document.
	 * @param \ddn\sapp\PDFObject $infoObj The /Info object.
	 *
	 * @return int The number of fields stripped.
	 */
	private function stripInfoFields(PDFDoc $doc, \ddn\sapp\PDFObject $infoObj): int {
		$stripped = 0;
		$infoValue = $infoObj->get_value();
		foreach (self::INFO_FIELDS_TO_STRIP as $field) {
			if (isset($infoValue[$field]) === true) {
				$infoValue[$field] = new PDFValueString(self::SENTINEL);
				$stripped++;
			}
		}

		if ($stripped > 0) {
			// Re-register so the mutation lands in the rebuild path
			// (same write-back contract as content-stream mutations).
			$doc->add_object(pdf_object: $infoObj);
		}

		return $stripped;
	}//end stripInfoFields()

	/**
	 * Read the trailer dictionary.
	 *
	 * SAPP holds the trailer on a protected property. Until a public
	 * accessor lands upstream we use Reflection — contained to this
	 * method so the rest of the class reads naturally. Reflection
	 * setAccessible() is a no-op since PHP 8.1; protected/private property
	 * access is always allowed by getValue() now without explicit unlocking.
	 *
	 * @param PDFDoc $doc The loaded PDF document.
	 *
	 * @return mixed The trailer object, or null when there is none.
	 */
	private function readTrailer(PDFDoc $doc): mixed {
		$ref = new ReflectionClass(PDFDoc::class);
		$property = $ref->getProperty('_pdf_trailer_object');
		return $property->getValue($doc);
	}//end readTrailer()

	/**
	 * Resolve an indirect reference to its object.
	 *
	 * @param PDFDoc $doc The loaded PDF document.
	 * @param mixed $reference The reference value (e.g. trailer /Info).
	 *
	 * @return \ddn\sapp\PDFObject|null The object, or null when the
	 *                                  value references none or it is missing.
	 */
	private function referencedObject(PDFDoc $doc, mixed $reference): ?\ddn\sapp\PDFObject {
		$oid = $reference->get_object_referenced();
		if ($oid === false || is_array($oid) === true) {
			return null;
		}

		$object = $doc->get_object(oid: (int)$oid);
		if ($object === false) {
			return null;
		}

		return $object;
	}//end referencedObject()

	/**
	 * Resolve the /Info object reference from the trailer.
	 *
	 * @param PDFDoc $doc The loaded PDF document.
	 *
	 * @return \ddn\sapp\PDFObject|null The /Info object, or null when
	 *                                  the trailer doesn't reference one.
	 */
	private function locateInfoObject(PDFDoc $doc): ?\ddn\sapp\PDFObject {
		$trailer = $this->readTrailer(doc: $doc);
		if ($trailer === null || isset($trailer['Info']) === false) {
			return null;
		}

		return $this->referencedObject(doc: $doc, reference: $trailer['Info']);
	}//end locateInfoObject()

	/**
	 * Resolve the catalog (/Root) object from the trailer.
	 *
	 * @param PDFDoc $doc The loaded PDF document.
	 *
	 * @return \ddn\sapp\PDFObject|null The catalog object, or null when absent.
	 */
	private function locateRootObject(PDFDoc $doc): ?\ddn\sapp\PDFObject {
		$trailer = $this->readTrailer(doc: $doc);
		if ($trailer === null || isset($trailer['Root']) === false) {
			return null;
		}

		return $this->referencedObject(doc: $doc, reference: $trailer['Root']);
	}//end locateRootObject()

	/**
	 * Resolve the catalog's /Metadata stream object.
	 *
	 * @param PDFDoc $doc The loaded PDF document.
	 *
	 * @return \ddn\sapp\PDFObject|null The metadata object, or null when absent.
	 */
	private function locateMetadataObject(PDFDoc $doc): ?\ddn\sapp\PDFObject {
		$rootObj = $this->locateRootObject(doc: $doc);
		if ($rootObj === null) {
			return null;
		}

		$rootValue = $rootObj->get_value();
		if (isset($rootValue['Metadata']) === false) {
			return null;
		}

		return $this->referencedObject(doc: $doc, reference: $rootValue['Metadata']);
	}//end locateMetadataObject()

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
		$metaObj = $this->locateMetadataObject(doc: $doc);
		if ($metaObj === null) {
			return false;
		}

		$xmp = $metaObj->get_stream(raw: false);
		if (is_string($xmp) === false || $xmp === '') {
			return false;
		}

		$strippedXmp = $this->stripXmpNamespaces(xmp: $xmp);
		if ($strippedXmp === $xmp) {
			return false;
		}

		$metaObj->set_stream(stream: $strippedXmp, raw: false);
		$doc->add_object(pdf_object: $metaObj);
		return true;
	}//end sanitizeXmp()

	/**
	 * Replace the element bodies of the stripped XMP namespaces with the sentinel.
	 *
	 * `<dc:Foo>...</dc:Foo>` / `<xmp:Foo>...</xmp:Foo>` / `<pdf:Foo>...</pdf:Foo>`
	 * bodies become the sentinel; custom namespaces (e.g. `<wcm:foo>`) are
	 * untouched.
	 *
	 * @param string $xmp The XMP packet.
	 *
	 * @return string The stripped packet, or the original on a PCRE failure.
	 */
	private function stripXmpNamespaces(string $xmp): string {
		$originalXmp = $xmp;
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
				return $originalXmp;
			}
		}//end foreach

		return $xmp;
	}//end stripXmpNamespaces()
}//end class
