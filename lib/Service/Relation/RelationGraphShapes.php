<?php

/**
 * OpenRegister RelationGraphShapes
 *
 * Shapes the nodes and edges of a relation graph; holds no state.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Relation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Relation;

use OCA\OpenRegister\Db\ObjectRelation;

/**
 * Shapes the nodes and edges of a relation graph; holds no state.
 *
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */
final class RelationGraphShapes {
	/**
	 * Whether an edge points at an external address rather than at an object.
	 *
	 * @param array<string, mixed> $edge The edge.
	 *
	 * @return bool True for an external address.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function isExternal(array $edge): bool {
		return ($edge['kind'] ?? null) === ObjectRelation::KIND_EXTERNAL;
	}//end isExternal()
	/**
	 * The direction, label, type and kind fields of an outgoing property edge.
	 *
	 * @param array<string, mixed> $row The relation type row of the edge's path.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function outgoingDetails(array $row): array {
		return [
			'direction' => RelationTypeResolver::DIRECTION_OUTGOING,
			'label' => ($row['displayLabel'] ?? null),
			'inverseLabel' => ($row['inverseLabel'] ?? null),
			'type' => ($row['type'] ?? null),
			'property' => ($row['property'] ?? null),
			'origin' => 'property',
			'kind' => ObjectRelation::KIND_OBJECT,
		];
	}//end outgoingDetails()

	/**
	 * One edge from a stored relation row, outgoing when its source is on this level.
	 *
	 * @param ObjectRelation     $stored The stored row.
	 * @param array<int, string> $uuids  The uuids of this level.
	 *
	 * @return array<string, mixed> The edge.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function storedEdge(ObjectRelation $stored, array $uuids): array {
		$source    = (string)$stored->getSourceUuid();
		$direction = RelationTypeResolver::DIRECTION_INCOMING;
		if (in_array($source, $uuids, true) === true) {
			$direction = RelationTypeResolver::DIRECTION_OUTGOING;
		}

		return ['from' => $source, 'to' => $this->storedTarget(stored: $stored), 'direction' => $direction] + $this->storedDetails(stored: $stored);
	}//end storedEdge()

	/**
	 * The other end of a stored row: its target uuid, or its address when it has none.
	 *
	 * @param ObjectRelation $stored The stored row.
	 *
	 * @return string
	 */
	private function storedTarget(ObjectRelation $stored): string {
		$target = (string)($stored->getTargetUuid() ?? '');
		if ($target === '') {
			return (string)$stored->getTargetUrl();
		}

		return $target;
	}//end storedTarget()

	/**
	 * The label, type, origin and target fields of an edge from a stored row.
	 *
	 * @param ObjectRelation $stored The stored row.
	 *
	 * @return array<string, mixed>
	 */
	private function storedDetails(ObjectRelation $stored): array {
		return [
			'label' => ($stored->getLabel() ?? $stored->getRelationType()),
			'inverseLabel' => $stored->getInverseLabel(),
			'type' => $stored->getRelationType(),
			'property' => null,
			'origin' => $stored->getOrigin(),
			'kind' => $stored->getKind(),
			'targetUrl' => $stored->getTargetUrl(),
			'targetTitle' => $stored->getTargetTitle(),
		];
	}//end storedDetails()

	/**
	 * The node of an external address: resolved, with the title the stored row carries.
	 *
	 * @param array<string, mixed> $edge The edge that reached it.
	 * @param array<string, mixed> $node The address as an unresolved node, with its uuid and distance.
	 *
	 * @return array<string, mixed> The node.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function externalNode(array $edge, array $node): array {
		return array_merge(
			$node,
			['title' => ($edge['targetTitle'] ?? $node['uuid']), 'resolved' => true, 'external' => true]
		);
	}//end externalNode()

	/**
	 * The node of an object that could not be loaded: it still appears, unresolved.
	 *
	 * @param string $uuid     The uuid.
	 * @param int    $distance Its distance from the root.
	 *
	 * @return array<string, mixed> The node.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function unresolvedNode(string $uuid, int $distance): array {
		return [
			'uuid' => $uuid,
			'title' => null,
			'register' => null,
			'schema' => null,
			'distance' => $distance,
			'resolved' => false,
			'external' => false,
		];
	}//end unresolvedNode()
}//end class
