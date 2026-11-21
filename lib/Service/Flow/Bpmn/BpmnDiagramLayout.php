<?php

/**
 * Where a BPMN file says its shapes are, and where to put them when it does not.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Bpmn
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-bpmn-interchange/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Bpmn;

use DOMElement;
use DOMXPath;

/**
 * Reads BPMN diagram interchange, and lays a graph out when there is none.
 *
 * Kept apart from the importer because it is about the PICTURE, not about
 * the process: everything the importer decides is a mapping question with a
 * verdict attached, and none of it changes if the file carries no
 * coordinates at all.
 *
 * @spec openspec/specs/flow-bpmn-interchange/spec.md
 */
class BpmnDiagramLayout {

	/**
	 * The diagram positions the file carries, keyed by element id.
	 *
	 * @param DOMXPath $xpath The xpath.
	 *
	 * @return array<string, array{x: int, y: int}> The positions.
	 *
	 * @spec openspec/specs/flow-bpmn-interchange/spec.md
	 */
	public function positions(DOMXPath $xpath): array {
		$positions = [];
		foreach ($this->shapesIn(xpath: $xpath) as $shape) {
			if (($shape instanceof DOMElement) === false) {
				continue;
			}

			$bound = $this->boundsOf(xpath: $xpath, shape: $shape);
			if ($bound === null) {
				continue;
			}

			$positions[trim($shape->getAttribute('bpmnElement'))] = self::pointOf(bound: $bound);
		}

		return $positions;
	}//end positions()

	/**
	 * The x and y a shape's bounds place it at.
	 *
	 * @param DOMElement $bound The shape's `dc:Bounds`.
	 *
	 * @return array{x: int, y: int} The position.
	 */
	private static function pointOf(DOMElement $bound): array {
		return [
			'x' => (int)$bound->getAttribute('x'),
			'y' => (int)$bound->getAttribute('y'),
		];
	}//end pointOf()

	/**
	 * The document's BPMN shapes, or none when the query fails.
	 *
	 * @param DOMXPath $xpath The document's XPath.
	 *
	 * @return iterable The shapes.
	 */
	private function shapesIn(DOMXPath $xpath): iterable {
		$shapes = $xpath->query('//bpmndi:BPMNShape');
		if ($shapes === false) {
			return [];
		}

		return $shapes;
	}//end shapesIn()

	/**
	 * A shape's first `dc:Bounds` element, or null when it has none.
	 *
	 * @param DOMXPath   $xpath The xpath.
	 * @param DOMElement $shape The diagram shape.
	 *
	 * @return DOMElement|null The bounds.
	 *
	 * @spec openspec/specs/flow-bpmn-interchange/spec.md
	 */
	private function boundsOf(DOMXPath $xpath, DOMElement $shape): ?DOMElement {
		$bounds = $xpath->query('./dc:Bounds', $shape);
		if ($bounds === false || $bounds->length === 0) {
			return null;
		}

		$bound = $bounds->item(0);
		if (($bound instanceof DOMElement) === false) {
			return null;
		}

		return $bound;
	}//end boundsOf()

	/**
	 * The nodes with their positions, laid out when the file carried none.
	 *
	 * 🔴 NOT A PILE AT THE ORIGIN. A file with no diagram interchange is the
	 * common case for a hand-written or generated BPMN, and importing one into
	 * a heap of overlapping boxes reads as "the import is broken" rather than
	 * as "this file had no layout".
	 *
	 * @param array<int, array<string, mixed>>      $nodes     The nodes.
	 * @param array<string, array{x: int, y: int}>  $positions The file's positions.
	 *
	 * @return array<int, array<string, mixed>> The nodes.
	 *
	 * @spec openspec/specs/flow-bpmn-interchange/spec.md
	 */
	public function laidOut(array $nodes, array $positions): array {
		foreach ($nodes as $index => $node) {
			$id = (string)($node['id'] ?? '');
			if (array_key_exists($id, $positions) === true) {
				$nodes[$index]['position'] = $positions[$id];
				continue;
			}

			$nodes[$index]['position'] = [
				'x' => (($index % FlowBpmnImporter::LAYOUT_COLUMNS) * FlowBpmnImporter::LAYOUT_X),
				'y' => ((int)floor($index / FlowBpmnImporter::LAYOUT_COLUMNS) * FlowBpmnImporter::LAYOUT_Y),
			];
		}

		return $nodes;
	}//end laidOut()

}//end class
