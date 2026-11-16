<?php

/**
 * PdfPayloadStripper
 *
 * Removes the hidden payload of a PDF that is not page content: annotations
 * (form field widgets stay, without their actions), embedded files,
 * JavaScript, /OpenAction and /AA. Removal means the key or entry is gone,
 * never masked or emptied.
 *
 * One exception: in a document that declares PDF/A-3, an embedded file whose
 * /AFRelationship is /Source or /Data is the archival payload of the
 * container. It is kept together with its /AF association and listed in the
 * result, so a preserved attachment is never silent.
 *
 * Pure over the PDFDoc: it mutates objects through `add_object` and leaves
 * serialisation to {@see PdfReachableSerialiser}, which writes only what is
 * still referenced.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Files\Service\File\Pdf
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File\Pdf;

use ddn\sapp\PDFDoc;
use ddn\sapp\PDFObject;
use ddn\sapp\pdfvalue\PDFValueList;
use ddn\sapp\pdfvalue\PDFValueObject;
use ReflectionClass;

/**
 * Strips annotations, embedded files, JavaScript and automatic actions.
 */
class PdfPayloadStripper {

	/**
	 * The /AFRelationship values that mark a PDF/A-3 archival attachment.
	 */
	public const PRESERVED_RELATIONSHIPS = ['Source', 'Data'];

	/**
	 * Strip the payload from a parsed document.
	 *
	 * @param PDFDoc $doc       The document, mutated in place.
	 * @param bool   $isPdfA3   Whether the document declares PDF/A-3 (pdfaid:part 3).
	 *
	 * @return array{annotationsRemoved: int, embeddedFilesRemoved: int, javascriptRemoved: int,
	 *     openActionsRemoved: int, additionalActionsRemoved: int,
	 *     preservedAttachments: list<array{name: string, relationship: string}>} What was removed and kept.
	 */
	public function strip(PDFDoc $doc, bool $isPdfA3): array {
		$counts = [
			'annotationsRemoved' => 0,
			'embeddedFilesRemoved' => 0,
			'javascriptRemoved' => 0,
			'openActionsRemoved' => 0,
			'additionalActionsRemoved' => 0,
			'preservedAttachments' => [],
		];

		$root = $this->rootObject(doc: $doc);
		if ($root !== null) {
			$counts = $this->stripCatalog(doc: $doc, root: $root, isPdfA3: $isPdfA3, counts: $counts);
		}

		foreach ($this->pages(doc: $doc) as $page) {
			$counts = $this->stripPage(doc: $doc, page: $page, counts: $counts);
		}

		return $counts;

	}//end strip()

	/**
	 * Whether a document's XMP declares PDF/A part 3.
	 *
	 * @param string $bytes The PDF bytes.
	 *
	 * @return bool True when pdfaid:part is 3.
	 */
	public static function declaresPdfA3(string $bytes): bool {
		return preg_match('#pdfaid:part\s*(=\s*["\']\s*3\s*["\']|>\s*3\s*<)#', $bytes) === 1;

	}//end declaresPdfA3()

	/**
	 * Strip the catalog: its actions, the JavaScript tree, the embedded files and /AF.
	 *
	 * @param PDFDoc               $doc     The document.
	 * @param PDFObject            $root    The catalog.
	 * @param bool                 $isPdfA3 Whether the document declares PDF/A-3.
	 * @param array<string, mixed> $counts  The counts so far.
	 *
	 * @return array<string, mixed> The counts.
	 */
	private function stripCatalog(PDFDoc $doc, PDFObject $root, bool $isPdfA3, array $counts): array {
		$catalog = $root->get_value();
		$changed = false;
		if (isset($catalog['OpenAction']) === true) {
			unset($catalog['OpenAction']);
			$counts['openActionsRemoved']++;
			$changed = true;
		}

		if (isset($catalog['AA']) === true) {
			unset($catalog['AA']);
			$counts['additionalActionsRemoved']++;
			$changed = true;
		}

		$kept = [];
		if (isset($catalog['Names']) === true) {
			[$names, $namesObject] = $this->dictionary(doc: $doc, value: $catalog['Names']);
			if ($names !== null) {
				if (isset($names['JavaScript']) === true) {
					$counts['javascriptRemoved'] += max(1, count($this->namePairs(doc: $doc, node: $names['JavaScript'])));
					unset($names['JavaScript']);
					$changed = true;
				}

				if (isset($names['EmbeddedFiles']) === true) {
					[$kept, $removed] = $this->partitionEmbeddedFiles(doc: $doc, node: $names['EmbeddedFiles'], isPdfA3: $isPdfA3, af: $catalog['AF'] ?? null);
					$counts['embeddedFilesRemoved'] += $removed;
					if ($kept === []) {
						unset($names['EmbeddedFiles']);
					} else {
						$names['EmbeddedFiles'] = new PDFValueObject(['Names' => new PDFValueList($this->pairValues(kept: $kept))]);
					}

					$changed = true;
				}

				if ($namesObject !== null) {
					$doc->add_object(pdf_object: $namesObject);
				}
			}
		}//end if

		if (isset($catalog['AF']) === true) {
			$keptRefs = array_column($kept, 'reference');
			if ($keptRefs === []) {
				unset($catalog['AF']);
			} else {
				$catalog['AF'] = new PDFValueList($keptRefs);
			}

			$changed = true;
		}

		foreach ($kept as $attachment) {
			$counts['preservedAttachments'][] = ['name' => $attachment['name'], 'relationship' => $attachment['relationship']];
		}

		if ($changed === true) {
			$doc->add_object(pdf_object: $root);
		}

		return $counts;

	}//end stripCatalog()

	/**
	 * Strip one page: non-widget annotations go, widgets lose their actions, /AA goes.
	 *
	 * @param PDFDoc               $doc    The document.
	 * @param PDFObject            $page   The page.
	 * @param array<string, mixed> $counts The counts so far.
	 *
	 * @return array<string, mixed> The counts.
	 */
	private function stripPage(PDFDoc $doc, PDFObject $page, array $counts): array {
		$value = $page->get_value();
		$changed = false;
		if (isset($value['AA']) === true) {
			unset($value['AA']);
			$counts['additionalActionsRemoved']++;
			$changed = true;
		}

		if (isset($value['Annots']) === true) {
			$widgets = [];
			foreach ($this->references(value: $value['Annots']) as $oid) {
				$annotation = $doc->get_object($oid);
				if ($annotation instanceof PDFObject && $this->name(value: $annotation->get_value()['Subtype'] ?? null) === 'Widget') {
					$counts = $this->disarmWidget(doc: $doc, widget: $annotation, counts: $counts);
					$widgets[] = $oid;
					continue;
				}

				$counts['annotationsRemoved']++;
			}

			if ($widgets === []) {
				unset($value['Annots']);
			} else {
				$value['Annots'] = new PDFValueList(array_map(static fn (int $oid): string => $oid . ' 0 R', $widgets));
			}

			$changed = true;
		}//end if

		if ($changed === true) {
			$doc->add_object(pdf_object: $page);
		}

		return $counts;

	}//end stripPage()

	/**
	 * A form field widget keeps its appearance but loses /AA and a JavaScript /A.
	 *
	 * @param PDFDoc               $doc    The document.
	 * @param PDFObject            $widget The widget annotation.
	 * @param array<string, mixed> $counts The counts so far.
	 *
	 * @return array<string, mixed> The counts.
	 */
	private function disarmWidget(PDFDoc $doc, PDFObject $widget, array $counts): array {
		$value = $widget->get_value();
		$changed = false;
		if (isset($value['AA']) === true) {
			unset($value['AA']);
			$counts['additionalActionsRemoved']++;
			$changed = true;
		}

		if (isset($value['A']) === true) {
			[$action] = $this->dictionary(doc: $doc, value: $value['A']);
			if ($action !== null && $this->name(value: $action['S'] ?? null) === 'JavaScript') {
				unset($value['A']);
				$counts['javascriptRemoved']++;
				$changed = true;
			}
		}

		if ($changed === true) {
			$doc->add_object(pdf_object: $widget);
		}

		return $counts;

	}//end disarmWidget()

	/**
	 * Split the embedded files into the archival ones kept and a count of the rest.
	 *
	 * @param PDFDoc $doc     The document.
	 * @param mixed  $node    The /EmbeddedFiles name tree.
	 * @param bool   $isPdfA3 Whether the document declares PDF/A-3.
	 * @param mixed  $af      The catalog /AF value, or null.
	 *
	 * @return array{0: list<array{name: string, key: string, reference: string, relationship: string}>, 1: int} Kept, and how many were removed.
	 */
	private function partitionEmbeddedFiles(PDFDoc $doc, mixed $node, bool $isPdfA3, mixed $af): array {
		$associated = [];
		if ($af !== null) {
			$associated = $this->references(value: $af);
		}

		$kept = [];
		$removed = 0;
		foreach ($this->namePairs(doc: $doc, node: $node) as [$key, $spec]) {
			$specOid = $this->references(value: $spec)[0] ?? null;
			[$fileSpec] = $this->dictionary(doc: $doc, value: $spec);
			$relationship = (string) $this->name(value: $fileSpec['AFRelationship'] ?? null);
			if ($isPdfA3 === true
				&& $specOid !== null
				&& in_array($relationship, self::PRESERVED_RELATIONSHIPS, true) === true
				&& in_array($specOid, $associated, true) === true
			) {
				$kept[] = [
					'name' => $this->text(value: $fileSpec['UF'] ?? ($fileSpec['F'] ?? $key)),
					'key' => (string) $key,
					'reference' => $specOid . ' 0 R',
					'relationship' => $relationship,
				];
				continue;
			}

			$removed++;
		}

		return [$kept, $removed];

	}//end partitionEmbeddedFiles()

	/**
	 * The flat [key, value] pairs of a name tree, following /Kids.
	 *
	 * @param PDFDoc $doc   The document.
	 * @param mixed  $node  The tree node (inline or referenced).
	 * @param int    $depth The recursion depth.
	 *
	 * @return list<array{0: string, 1: mixed}> The pairs.
	 */
	private function namePairs(PDFDoc $doc, mixed $node, int $depth = 0): array {
		[$dict] = $this->dictionary(doc: $doc, value: $node);
		if ($dict === null || $depth > 16) {
			return [];
		}

		$pairs = [];
		if (isset($dict['Names']) === true && $dict['Names'] instanceof PDFValueList) {
			$items = array_values((array) $dict['Names']->val());
			for ($i = 0; $i + 1 < count($items); $i += 2) {
				$pairs[] = [$this->text(value: $items[$i]), $items[$i + 1]];
			}
		}

		if (isset($dict['Kids']) === true) {
			foreach ($this->references(value: $dict['Kids']) as $kid) {
				$pairs = array_merge($pairs, $this->namePairs(doc: $doc, node: $kid . ' 0 R', depth: $depth + 1));
			}
		}

		return $pairs;

	}//end namePairs()

	/**
	 * The name-tree values of the kept attachments, as a flat list.
	 *
	 * @param list<array{key: string, reference: string}> $kept The kept attachments.
	 *
	 * @return list<string> Key string and reference, alternating.
	 */
	private function pairValues(array $kept): array {
		$values = [];
		foreach ($kept as $attachment) {
			$values[] = '(' . addcslashes($attachment['key'], '()\\') . ')';
			$values[] = $attachment['reference'];
		}

		return $values;

	}//end pairValues()

	/**
	 * A dictionary value, inline or behind a reference, and the object that holds it.
	 *
	 * @param PDFDoc $doc   The document.
	 * @param mixed  $value The value or a reference (object, or "n 0 R" string).
	 *
	 * @return array{0: mixed, 1: PDFObject|null} The dictionary (null when none) and its indirect object.
	 */
	private function dictionary(PDFDoc $doc, mixed $value): array {
		$oid = $this->references(value: $value)[0] ?? null;
		if ($oid !== null && (is_object($value) === false || ($value instanceof PDFValueObject) === false)) {
			$object = $doc->get_object($oid);
			if (($object instanceof PDFObject) === false) {
				return [null, null];
			}

			$inner = $object->get_value();
			if (($inner instanceof PDFValueObject) === false) {
				return [null, $object];
			}

			return [$inner, $object];
		}

		if ($value instanceof PDFValueObject) {
			return [$value, null];
		}

		return [null, null];

	}//end dictionary()

	/**
	 * The object ids a value references directly.
	 *
	 * @param mixed $value A reference, a list of references or a "n 0 R" string.
	 *
	 * @return list<int> The ids.
	 */
	private function references(mixed $value): array {
		if (is_string($value) === true) {
			preg_match_all('/(\d+)\s+\d+\s+R\b/', $value, $matches);
			return array_map('intval', $matches[1]);
		}

		if (is_object($value) === false || method_exists($value, 'get_object_referenced') === false) {
			return [];
		}

		$ids = $value->get_object_referenced();
		if ($ids === false) {
			return [];
		}

		return array_values(array_map('intval', (array) $ids));

	}//end references()

	/**
	 * A name value without its slash.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null The name, or null.
	 */
	private function name(mixed $value): ?string {
		if ($value === null || $value === false) {
			return null;
		}

		return ltrim(trim((string) $value), '/');

	}//end name()

	/**
	 * A string value as text, without its delimiters.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The text.
	 */
	private function text(mixed $value): string {
		if (is_object($value) === true && method_exists($value, 'val') === true) {
			$raw = $value->val();
			if (is_string($raw) === true) {
				return $raw;
			}
		}

		return trim((string) $value, '()');

	}//end text()

	/**
	 * The catalog object.
	 *
	 * @param PDFDoc $doc The document.
	 *
	 * @return PDFObject|null The catalog.
	 */
	private function rootObject(PDFDoc $doc): ?PDFObject {
		$trailer = PdfReachableSerialiser::trailerOf(doc: $doc);
		if ($trailer === null || isset($trailer['Root']) === false) {
			return null;
		}

		$oid = $this->references(value: $trailer['Root'])[0] ?? null;
		if ($oid === null) {
			return null;
		}

		$object = $doc->get_object($oid);
		if (($object instanceof PDFObject) === false) {
			return null;
		}

		return $object;

	}//end rootObject()

	/**
	 * Every page object of the document.
	 *
	 * @param PDFDoc $doc The document.
	 *
	 * @return list<PDFObject> The pages.
	 */
	private function pages(PDFDoc $doc): array {
		$pages = [];
		$max = (int) (new ReflectionClass(PDFDoc::class))->getProperty('_max_oid')->getValue($doc);
		for ($oid = 1; $oid <= $max; $oid++) {
			$object = $doc->get_object($oid);
			if (($object instanceof PDFObject) === false) {
				continue;
			}

			$value = $object->get_value();
			if ($value instanceof PDFValueObject && $this->name(value: $value['Type'] ?? null) === 'Page') {
				$pages[] = $object;
			}
		}

		return $pages;

	}//end pages()
}//end class
