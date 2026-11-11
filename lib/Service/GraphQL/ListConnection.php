<?php

/**
 * The connection answer of a GraphQL list query.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\GraphQL
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 *
 * @spec openspec/specs/graphql-api/spec.md#requirement-graphql-must-support-dual-pagination-modes
 */

namespace OCA\OpenRegister\Service\GraphQL;

/**
 * Builds edges, cursors and page info from a search answer.
 *
 * @spec openspec/specs/graphql-api/spec.md#requirement-graphql-must-support-dual-pagination-modes
 */
final class ListConnection {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $result  The search answer.
	 * @param ListRequest          $request The list query.
	 */
	public function __construct(
		private readonly array $result,
		private readonly ListRequest $request,
	) {
	}//end __construct()

	/**
	 * The connection over the rows the caller may see.
	 *
	 * @param array<int, array<string, mixed>> $rows The filtered rows.
	 *
	 * @return array<string, mixed> The connection.
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-graphql-must-support-dual-pagination-modes
	 */
	public function build(array $rows): array {
		$edges = $this->edges(rows: $rows);

		return [
			'edges' => $edges,
			'pageInfo' => $this->pageInfo(edges: $edges),
			'totalCount' => $this->totalCount(),
			'facets' => ($this->result['facets'] ?? null),
			'facetable' => ($this->result['facetable'] ?? null),
			'groups' => null,
		];
	}//end build()

	/**
	 * One edge per row, with its cursor.
	 *
	 * @param array<int, array<string, mixed>> $rows The filtered rows.
	 *
	 * @return array<int, array<string, mixed>> The edges.
	 */
	private function edges(array $rows): array {
		$edges = [];
		foreach ($rows as $index => $row) {
			$position = ($this->offset() + $index);
			$uuid = ($row['_uuid'] ?? $row['@self']['uuid'] ?? $position);
			$edges[] = [
				'cursor' => $this->encodeCursor(uuid: $uuid, offset: $position),
				'node' => $row,
				'_relevance' => ($row['_relevance'] ?? null),
			];
		}

		return $edges;
	}//end edges()

	/**
	 * The page info of these edges.
	 *
	 * @param array<int, array<string, mixed>> $edges The edges.
	 *
	 * @return array<string, mixed> The page info.
	 */
	private function pageInfo(array $edges): array {
		$limit = ($this->result['limit'] ?? $this->request->limit());

		return [
			'hasNextPage' => (($this->offset() + $limit) < $this->totalCount()),
			'hasPreviousPage' => ($this->offset() > 0),
			'startCursor' => ($edges[0]['cursor'] ?? null),
			'endCursor' => ($edges[(count($edges) - 1)]['cursor'] ?? null),
		];
	}//end pageInfo()

	/**
	 * The offset of the page answered.
	 *
	 * @return mixed The offset.
	 */
	private function offset(): mixed {
		return ($this->result['offset'] ?? $this->request->offset());
	}//end offset()

	/**
	 * The number of rows across all pages.
	 *
	 * @return mixed The total.
	 */
	private function totalCount(): mixed {
		return ($this->result['total'] ?? 0);
	}//end totalCount()

	/**
	 * Encode a pagination cursor.
	 *
	 * @param string     $uuid   The object UUID.
	 * @param int|string $offset The offset position.
	 *
	 * @return string The encoded cursor.
	 */
	private function encodeCursor(string $uuid, int|string $offset): string {
		return base64_encode(string: json_encode(value: ['uuid' => $uuid, 'offset' => $offset]));
	}//end encodeCursor()
}//end class
