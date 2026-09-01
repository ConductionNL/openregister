<?php

/**
 * PdfStructureInspector
 *
 * Reads a SAPP-parsed `PDFDoc` and reports whether the input is a tagged PDF
 * (PDF/UA, or any PDF carrying a logical structure tree) and how many
 * `/Type /StructElem` objects it carries. Pure function over the already-
 * parsed object model — never a raw byte scan, which would miss objects
 * packed into compressed `/ObjStm` streams (PDF 1.5+).
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
 * @spec openspec/changes/tag-preserving-redaction/specs/tag-preserving-redaction/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File\Pdf;

use ddn\sapp\PDFDoc;
use ddn\sapp\PDFObject;
use ddn\sapp\pdfvalue\PDFValueObject;

/**
 * Detects taggedness and counts structure elements over the SAPP object model.
 *
 * A PDF is "tagged" when its Catalog references a `/StructTreeRoot` and/or
 * carries `/MarkInfo` with `/Marked true` (design.md D1). The structure-
 * element count is the number of parsed objects whose dictionary has
 * `/Type /StructElem`. Both operations iterate `PDFDoc::get_object_iterator()`
 * — the same lazily-resolving walk SAPP itself uses elsewhere in this
 * codebase (see `PDFDoc::buildFontContext()`), which transparently resolves
 * objects packed into compressed object streams. No live Nextcloud instance
 * is required; this class is a pure function over an already-loaded `PDFDoc`.
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
 * @spec openspec/changes/tag-preserving-redaction/specs/tag-preserving-redaction/spec.md
 */
class PdfStructureInspector {
	/**
	 * Inspect a loaded PDF document for taggedness and structure-element count.
	 *
	 * @param PDFDoc $doc The already-parsed SAPP document (no second load).
	 *
	 * @return array{tagged: bool, structElementCount: int}
	 *
	 * @spec openspec/changes/tag-preserving-redaction/specs/tag-preserving-redaction/spec.md#REQ-ORTPR-001
	 */
	public function inspect(PDFDoc $doc): array {
		return [
			'tagged' => $this->isTagged(doc: $doc),
			'structElementCount' => $this->countStructElements(doc: $doc),
		];
	}//end inspect()

	/**
	 * Whether the document's Catalog carries `/StructTreeRoot` and/or
	 * `/MarkInfo << /Marked true >>`.
	 *
	 * @param PDFDoc $doc The already-parsed SAPP document.
	 *
	 * @return bool True when the input is a tagged PDF.
	 *
	 * @spec openspec/changes/tag-preserving-redaction/specs/tag-preserving-redaction/spec.md#REQ-ORTPR-001
	 */
	public function isTagged(PDFDoc $doc): bool {
		$catalog = $this->findCatalog(doc: $doc);
		if ($catalog === null) {
			return false;
		}

		if (isset($catalog['StructTreeRoot']) === true
			&& $this->resolveDict(doc: $doc, value: $catalog['StructTreeRoot']) !== null
		) {
			return true;
		}

		$markInfo = null;
		if (isset($catalog['MarkInfo']) === true) {
			$markInfo = $this->resolveDict(doc: $doc, value: $catalog['MarkInfo']);
		}

		if ($markInfo !== null && isset($markInfo['Marked']) === true) {
			return (trim((string)$markInfo['Marked']->val()) === 'true');
		}

		return false;
	}//end isTagged()

	/**
	 * Count the parsed objects whose dictionary has `/Type /StructElem`.
	 *
	 * Iterates every object reachable from the document's xref table
	 * (`PDFDoc::get_object_iterator()`, which resolves objects packed into
	 * compressed `/ObjStm` streams transparently) — never a raw byte scan.
	 * The count is a before/after comparison signal, not an absolute PDF/UA
	 * audit (design.md "Risks / Trade-offs").
	 *
	 * @param PDFDoc $doc The already-parsed SAPP document.
	 *
	 * @return int The number of `/Type /StructElem` objects.
	 *
	 * @spec openspec/changes/tag-preserving-redaction/specs/tag-preserving-redaction/spec.md#REQ-ORTPR-001
	 */
	public function countStructElements(PDFDoc $doc): int {
		$count = 0;

		// SAPP's own docblock for `get_object_iterator()` uses an informal
		// "oid=>obj" pseudo-type that PHPStan misreads as a real class
		// `ddn\sapp\oid` (tracked in phpstan-baseline.neon — a vendor
		// docblock quirk, not a real type error).
		foreach ($doc->get_object_iterator() as $object) {
			if (($object instanceof PDFObject) === false) {
				continue;
			}

			$value = $object->get_value();
			if (($value instanceof PDFValueObject) === false || isset($value['Type']) === false) {
				continue;
			}

			if ($value['Type']->val() === 'StructElem') {
				$count++;
			}
		}//end foreach

		return $count;
	}//end countStructElements()

	/**
	 * Find the document's Catalog object (`/Type /Catalog`) by walking the
	 * object model — avoids depending on `PDFDoc`'s unexposed trailer/root
	 * internals and stays a pure object-model operation.
	 *
	 * @param PDFDoc $doc The already-parsed SAPP document.
	 *
	 * @return PDFValueObject|null The Catalog dictionary, or null when absent.
	 */
	private function findCatalog(PDFDoc $doc): ?PDFValueObject {
		foreach ($doc->get_object_iterator() as $object) {
			if (($object instanceof PDFObject) === false) {
				continue;
			}

			$value = $object->get_value();
			if (($value instanceof PDFValueObject) === true
				&& isset($value['Type']) === true
				&& $value['Type']->val() === 'Catalog'
			) {
				return $value;
			}
		}

		return null;
	}//end findCatalog()

	/**
	 * Resolve a PDF value that may be a direct dictionary or an indirect
	 * reference to one (both forms are legal for `/StructTreeRoot` and
	 * `/MarkInfo`).
	 *
	 * @param PDFDoc $doc The already-parsed SAPP document (for indirect resolution).
	 * @param mixed $value The catalog field's raw value.
	 *
	 * @return PDFValueObject|null The resolved dictionary, or null when unresolvable.
	 */
	private function resolveDict(PDFDoc $doc, $value): ?PDFValueObject {
		if ($value instanceof PDFValueObject) {
			return $value;
		}

		if (is_object($value) === true && method_exists($value, 'get_object_referenced') === true) {
			$ref = $value->get_object_referenced();
			if (is_int($ref) === true) {
				$refObject = $doc->get_object($ref);
				if ($refObject instanceof PDFObject) {
					$refValue = $refObject->get_value();
					if ($refValue instanceof PDFValueObject) {
						return $refValue;
					}
				}
			}
		}

		return null;
	}//end resolveDict()
}//end class
