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

namespace OCA\OpenRegister\Files\Service\File\Pdf;

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

		if ($this->resolveDict(doc: $doc, value: $catalog['StructTreeRoot'] ?? null) !== null) {
			return true;
		}

		return $this->isMarked(markInfo: $this->resolveDict(doc: $doc, value: $catalog['MarkInfo'] ?? null));
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
		foreach ($this->dictionaries(doc: $doc) as $dictionary) {
			if ($this->typeOf(dictionary: $dictionary) === 'StructElem') {
				$count++;
			}
		}

		return $count;
	}//end countStructElements()

	/**
	 * Whether a resolved `/MarkInfo` dictionary says `/Marked true`.
	 *
	 * @param PDFValueObject|null $markInfo The resolved dictionary, or null.
	 *
	 * @return bool True when the document is marked.
	 */
	private function isMarked(?PDFValueObject $markInfo): bool {
		if ($markInfo === null || isset($markInfo['Marked']) === false) {
			return false;
		}

		return (trim((string) $markInfo['Marked']->val()) === 'true');
	}//end isMarked()

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
		foreach ($this->dictionaries(doc: $doc) as $dictionary) {
			if ($this->typeOf(dictionary: $dictionary) === 'Catalog') {
				return $dictionary;
			}
		}

		return null;
	}//end findCatalog()

	/**
	 * Yield the dictionary value of every parsed object that carries one.
	 *
	 * SAPP's own docblock for `get_object_iterator()` uses an informal
	 * "oid=>obj" pseudo-type that PHPStan misreads as a real class
	 * `ddn\sapp\oid` (tracked in phpstan-baseline.neon — a vendor
	 * docblock quirk, not a real type error).
	 *
	 * @param PDFDoc $doc The already-parsed SAPP document.
	 *
	 * @return \Generator<int, PDFValueObject> The object dictionaries.
	 */
	private function dictionaries(PDFDoc $doc): \Generator {
		foreach ($doc->get_object_iterator() as $object) {
			$value = $this->dictionaryOf(object: $object);
			if ($value !== null) {
				yield $value;
			}
		}
	}//end dictionaries()

	/**
	 * The dictionary value of a parsed object, or null when it has none.
	 *
	 * @param mixed $object An entry from the object iterator or `get_object()`.
	 *
	 * @return PDFValueObject|null The dictionary, or null.
	 */
	private function dictionaryOf(mixed $object): ?PDFValueObject {
		if (($object instanceof PDFObject) === false) {
			return null;
		}

		$value = $object->get_value();
		if (($value instanceof PDFValueObject) === false) {
			return null;
		}

		return $value;
	}//end dictionaryOf()

	/**
	 * The `/Type` name of a dictionary, or null when it has no `/Type`.
	 *
	 * @param PDFValueObject $dictionary The dictionary.
	 *
	 * @return mixed The `/Type` value, or null.
	 */
	private function typeOf(PDFValueObject $dictionary): mixed {
		if (isset($dictionary['Type']) === false) {
			return null;
		}

		return $dictionary['Type']->val();
	}//end typeOf()

	/**
	 * Resolve a PDF value that may be a direct dictionary or an indirect
	 * reference to one (both forms are legal for `/StructTreeRoot` and
	 * `/MarkInfo`).
	 *
	 * @param PDFDoc $doc   The already-parsed SAPP document (for indirect resolution).
	 * @param mixed  $value The catalog field's raw value, or null when absent.
	 *
	 * @return PDFValueObject|null The resolved dictionary, or null when unresolvable.
	 */
	private function resolveDict(PDFDoc $doc, $value): ?PDFValueObject {
		if ($value instanceof PDFValueObject) {
			return $value;
		}

		$ref = $this->referencedId(value: $value);
		if ($ref === null) {
			return null;
		}

		return $this->dictionaryOf(object: $doc->get_object($ref));
	}//end resolveDict()

	/**
	 * The object id an indirect reference points at, or null.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return int|null The referenced object id, or null when not a reference.
	 */
	private function referencedId($value): ?int {
		if (is_object($value) === false || method_exists($value, 'get_object_referenced') === false) {
			return null;
		}

		$ref = $value->get_object_referenced();
		if (is_int($ref) === false) {
			return null;
		}

		return $ref;
	}//end referencedId()
}//end class
