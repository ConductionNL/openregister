<?php

/**
 * OpenRegister EntityChangesPage
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/history-schema-and-settings-edits-audited/specs/audit-trail-immutable/spec.md#requirement-administrators-read-an-entitys-change-history
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;

/**
 * One page of a schema's or register's edit rows, newest first.
 *
 * Reads through the audit mapper's existing `schema` / `register` and
 * `action` filters, so no new query path is added. One row more than the
 * page is fetched to tell whether another page follows.
 *
 * @spec openspec/changes/history-schema-and-settings-edits-audited/specs/audit-trail-immutable/spec.md#requirement-administrators-read-an-entitys-change-history
 */
final class EntityChangesPage {
	/**
	 * Rows per page when the request names none.
	 *
	 * @var int
	 */
	public const DEFAULT_LIMIT = 20;

	/**
	 * The most rows one page returns.
	 *
	 * @var int
	 */
	public const MAX_LIMIT = 100;

	/**
	 * The entity kinds with an edit history, each the audit column it is filtered on.
	 *
	 * @var list<string>
	 */
	public const KINDS = ['schema', 'register'];

	/**
	 * Build the reader.
	 *
	 * @param AuditTrailMapperInterface $auditTrails The audit trail.
	 */
	public function __construct(
		private readonly AuditTrailMapperInterface $auditTrails,
	) {
	}//end __construct()

	/**
	 * The page of edit rows for one entity.
	 *
	 * @param string               $kind   `schema` or `register`.
	 * @param int                  $id     The entity id.
	 * @param array<string, mixed> $params The request parameters; `_page` and `_limit` are read.
	 *
	 * @return array{results: list<array<string, mixed>>, page: int, limit: int, hasMore: bool}
	 *
	 * @throws \InvalidArgumentException When the kind has no edit history.
	 *
	 * @spec openspec/changes/history-schema-and-settings-edits-audited/specs/audit-trail-immutable/spec.md#requirement-administrators-read-an-entitys-change-history
	 */
	public function read(string $kind, int $id, array $params): array {
		if (in_array($kind, self::KINDS, true) === false) {
			throw new \InvalidArgumentException('No edit history for ' . $kind);
		}

		$limit = min(self::MAX_LIMIT, max(1, (int) ($params['_limit'] ?? self::DEFAULT_LIMIT)));
		$page  = max(1, (int) ($params['_page'] ?? 1));

		$rows = $this->auditTrails->findTrail(
			query: new ListQuery(
				limit: ($limit + 1),
				offset: (($page - 1) * $limit),
				filters: [
					$kind    => $id,
					'action' => [$kind . '.created', $kind . '.updated', $kind . '.deleted'],
				],
				sort: ['created' => 'DESC', 'id' => 'DESC']
			)
		);

		$hasMore = count($rows) > $limit;
		$results = [];
		foreach (array_slice($rows, 0, $limit) as $row) {
			$results[] = $row->jsonSerialize();
		}

		return ['results' => $results, 'page' => $page, 'limit' => $limit, 'hasMore' => $hasMore];
	}//end read()
}//end class
