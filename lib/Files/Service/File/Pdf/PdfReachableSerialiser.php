<?php

/**
 * PdfReachableSerialiser
 *
 * Writes a parsed PDF out as one full save holding only the objects still
 * reachable from the trailer's /Root and /Info. Earlier revisions, their
 * cross-reference sections and every object nothing refers to any more (an
 * embedded file whose name-tree entry was removed, an old object stream) are
 * absent from the output, not just unreferenced. The output has a classic
 * cross-reference table and a fresh trailer.
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
use ReflectionClass;
use RuntimeException;

/**
 * Full save of the reachable objects only.
 */
class PdfReachableSerialiser {

	/**
	 * The trailer dictionary of a parsed document, or null.
	 *
	 * @param PDFDoc $doc The document.
	 *
	 * @return mixed The trailer value.
	 */
	public static function trailerOf(PDFDoc $doc): mixed {
		return (new ReflectionClass(PDFDoc::class))->getProperty('_pdf_trailer_object')->getValue($doc);

	}//end trailerOf()

	/**
	 * Serialise the document.
	 *
	 * @param PDFDoc $doc The document.
	 *
	 * @return string The PDF bytes.
	 *
	 * @throws RuntimeException When the document has no catalog.
	 */
	public function serialise(PDFDoc $doc): string {
		$trailer = self::trailerOf(doc: $doc);
		$rootRef = $this->firstReference(value: $trailer['Root'] ?? null);
		if ($trailer === null || $rootRef === null) {
			throw new RuntimeException(message: 'The PDF has no catalog to serialise from');
		}

		$infoRef = $this->firstReference(value: $trailer['Info'] ?? null);
		$reachable = $this->reachable(doc: $doc, start: array_values(array_filter([$rootRef, $infoRef])));

		$version = (string) (new ReflectionClass(PDFDoc::class))->getProperty('_pdf_version_string')->getValue($doc);
		if (preg_match('/^PDF-\d\.\d$/', $version) !== 1) {
			$version = 'PDF-1.7';
		}

		$out = '%' . $version . "\n%\xE2\xE3\xCF\xD3\n";
		$offsets = [];
		foreach ($reachable as $oid) {
			$object = $doc->get_object($oid);
			$offsets[$oid] = strlen($out);
			$out .= $object->to_pdf_entry();
		}

		$size = max(array_keys($offsets)) + 1;
		$xref = strlen($out);
		$out .= "xref\n0 " . $size . "\n" . "0000000000 65535 f \n";
		for ($oid = 1; $oid < $size; $oid++) {
			if (isset($offsets[$oid]) === true) {
				$out .= sprintf("%010d 00000 n \n", $offsets[$oid]);
				continue;
			}

			$out .= "0000000000 65535 f \n";
		}

		$entries = ['/Size ' . $size, '/Root ' . $rootRef . ' 0 R'];
		if ($infoRef !== null && isset($offsets[$infoRef]) === true) {
			$entries[] = '/Info ' . $infoRef . ' 0 R';
		}

		if (isset($trailer['ID']) === true) {
			$entries[] = '/ID ' . trim((string) $trailer['ID']);
		}

		$out .= "trailer\n<< " . implode(' ', $entries) . " >>\nstartxref\n" . $xref . "\n%%EOF\n";

		return $out;

	}//end serialise()

	/**
	 * The object ids reachable from the start ids, ascending.
	 *
	 * @param PDFDoc    $doc   The document.
	 * @param list<int> $start The ids to start from.
	 *
	 * @return list<int> The reachable ids.
	 */
	private function reachable(PDFDoc $doc, array $start): array {
		$seen = [];
		$queue = $start;
		while ($queue !== []) {
			$oid = array_shift($queue);
			if (isset($seen[$oid]) === true) {
				continue;
			}

			$object = $doc->get_object($oid);
			if ($object === false) {
				continue;
			}

			$seen[$oid] = true;
			preg_match_all('/(\d+)\s+(\d+)\s+R\b/', (string) $object->get_value(), $matches);
			foreach ($matches[1] as $next) {
				if (isset($seen[(int) $next]) === false) {
					$queue[] = (int) $next;
				}
			}
		}

		$ids = array_keys($seen);
		sort($ids);

		return $ids;

	}//end reachable()

	/**
	 * The object id a reference value points at.
	 *
	 * @param mixed $value The value.
	 *
	 * @return int|null The id.
	 */
	private function firstReference(mixed $value): ?int {
		if (is_object($value) === false || method_exists($value, 'get_object_referenced') === false) {
			return null;
		}

		$oid = $value->get_object_referenced();
		if ($oid === false || is_array($oid) === true) {
			return null;
		}

		return (int) $oid;

	}//end firstReference()
}//end class
