<?php

/**
 * ObjectHistoryForReaders - one object's history, for whoever may read that object
 *
 * `GET /api/objects/{register}/{schema}/{id}/audit-trails` was admin-only, so
 * a case handler opening the history tab of a case they work on got a 403.
 * The rule (decision 14 Oct 2026) is that a user may see the history of an
 * object if and only if they may read that object. This class is that rule.
 *
 * It does not decide readability itself. It asks `ObjectReadAccess::readable()`,
 * which runs the same `ObjectService::find()` the object GET runs, with RBAC and
 * multitenancy on, so a grant, a schema rule, a register rule, a draft, an
 * archived or a deleted object mean here exactly what they mean on the object.
 * A caller who may not read the object gets null, which the controller answers
 * with the 404 the object GET gives, so the history route does not confirm that
 * an object exists to somebody who may not see it.
 *
 * Reading the object is not the same as reading every value it ever held. A
 * property hidden from this caller by a read rule, a `writeOnly` flag or the
 * object's lifecycle state is hidden on the object, and its old and new values
 * in the history would show it all the same. So every entry goes through
 * `HistoryEntryRedactor`, which applies the render path's property rules.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-history-of-one-object-is-readable-by-whoever-may-read-that-object
 * @spec openspec/changes/audit-read-events-visible-to/specs/audit-trail-immutable/spec.md#requirement-read-entries-are-shown-only-to-the-groups-named-for-them
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Audit;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\AuditTrailMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\RegisterMapperInterface;
use OCA\OpenRegister\Service\Object\ObjectReadAccess;
use Throwable;

/**
 * Reads one object's audit trail on behalf of a non-admin reader of that object.
 */
class ObjectHistoryForReaders {

	/**
	 * Filter and sort columns a reader may not use.
	 *
	 * `changed` holds the unfiltered payload, so filtering or sorting on it
	 * answers questions about values the reader may not see. `session`,
	 * `request` and `ip_address` are the fields the reader is not given.
	 *
	 * @var string[]
	 */
	private const READER_BLOCKED_COLUMNS = ['changed', 'session', 'request', 'ip_address'];

	/**
	 * Constructor.
	 *
	 * @param ObjectReadAccess    $access           Decides readability exactly as the object GET does.
	 * @param AuditTrailMapperInterface<AuditTrail, ObjectEntity>    $auditTrailMapper Reads the object's entries.
	 * @param HistoryEntryRedactor $redactor        Removes what the reader may not see from each entry.
	 * @param RegisterMapperInterface<Register, Schema>      $registerMapper   Resolves the register for the doelbinding guard.
	 * @param PurposeGuard        $purposeGuard     The doelbinding guard the object GET runs.
	 * @param ReadEventVisibility $readEvents       Whether this reader may see who read the object.
	 */
	public function __construct(
		private readonly ObjectReadAccess $access,
		private readonly AuditTrailMapperInterface $auditTrailMapper,
		private readonly HistoryEntryRedactor $redactor,
		private readonly RegisterMapperInterface $registerMapper,
		private readonly PurposeGuard $purposeGuard,
		private readonly ReadEventVisibility $readEvents,
	) {
	}//end __construct()

	/**
	 * One page of an object's history, or null when the caller may not read the object.
	 *
	 * The whole history of the object is read and filtered before it is paged,
	 * because a free-text search has to match what the reader is shown, not
	 * the stored payload: matched in SQL, `?_search=<value>` would tell a
	 * reader whether a hidden property ever held that value.
	 *
	 * @param string $register The register slug or id from the path.
	 * @param string $schema   The schema slug or id from the path.
	 * @param string $id       The object id from the path.
	 * @param array  $params   limit, offset, page, filters, sort and search, as the controller extracts them.
	 *
	 * @return array{results: array<int, array>, total: int, page: int|null, pages: float, limit: int, offset: int|null, readEventsVisible: bool}|null
	 *     The page, with whether read entries are shown to this caller, or null when the object is not readable for this caller.
	 *
	 * @throws PurposeRefusedException When the schema asks for a purpose and none usable was named.
	 *
	 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-history-of-one-object-is-readable-by-whoever-may-read-that-object
	 */
	public function page(string $register, string $schema, string $id, array $params): ?array {
		$object = $this->access->readable(register: $register, schema: $schema, id: $id);
		if ($object === null) {
			return null;
		}

		$objectUuid = $object->getUuid();
		$schemaEntity = $this->access->schemaOf(object: $object);
		if ($objectUuid === null || $objectUuid === '' || $schemaEntity === null) {
			// Every unknown hides: without the schema the property rules cannot
			// be applied, and an unfiltered history is the leak this prevents.
			return null;
		}

		$registerEntity = $this->registerOf(object: $object);
		if ($registerEntity === null) {
			return null;
		}

		// The same doelbinding guard the object GET runs, after the read check
		// so a refusal never confirms an object the caller may not see.
		$this->purposeGuard->enforce(register: $registerEntity, schema: $schemaEntity);

		$filters = $this->readerColumns(columns: ($params['filters'] ?? []));
		$filters['object_uuid'] = $objectUuid;

		$sort = $this->readerColumns(columns: ($params['sort'] ?? []));
		if ($sort === []) {
			$sort = ['created' => 'DESC'];
		}

		$rows = $this->auditTrailMapper->findAll(filters: $filters, sort: $sort);

		$entries = $this->redactor->redact(rows: $rows, schema: $schemaEntity, object: $object);

		// Who read the object is shown only to the groups named for it, and
		// dropped before paging so the totals count what the reader is given.
		$entries = $this->readEvents->filter(entries: $entries);

		return array_merge(
			$this->paged(entries: $entries, params: $params),
			['readEventsVisible' => $this->readEvents->maySeeReadEvents()]
		);
	}//end page()

	/**
	 * Search and page the redacted entries.
	 *
	 * @param array<int, array> $entries The redacted entries.
	 * @param array             $params  limit, offset, page and search.
	 *
	 * @return array{results: array<int, array>, total: int, page: int|null, pages: float, limit: int, offset: int|null}
	 *     The page.
	 */
	private function paged(array $entries, array $params): array {
		$search = trim((string)($params['search'] ?? ''));
		if ($search !== '') {
			$entries = array_values(
				array_filter($entries, fn (array $entry): bool => $this->matches(entry: $entry, search: $search))
			);
		}

		$limit = max(1, (int)($params['limit'] ?? 20));
		$offset = max(0, (int)($params['offset'] ?? 0));
		$total = count($entries);

		return [
			'results' => array_slice($entries, $offset, $limit),
			'total' => $total,
			'page' => $params['page'] ?? null,
			'pages' => ceil($total / $limit),
			'limit' => $limit,
			'offset' => $params['offset'] ?? null,
		];
	}//end paged()

	/**
	 * The register an object lives in, for the doelbinding guard.
	 *
	 * Resolved without RBAC: the read was already decided, and this lookup
	 * only reads the register's doelbinding annotation. A register that will
	 * not resolve answers null, which the caller turns into a refusal: a guard
	 * handed no register would skip a register-level annotation.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return Register|null The register.
	 */
	private function registerOf(ObjectEntity $object): ?Register {
		$registerId = $object->getRegister();
		if ($registerId === null || $registerId === '') {
			return null;
		}

		try {
			return $this->registerMapper->find($registerId, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			return null;
		}
	}//end registerOf()

	/**
	 * Drop the filter or sort columns a reader may not use.
	 *
	 * @param array<string, mixed> $columns Column map from the request.
	 *
	 * @return array<string, mixed> The columns without the blocked ones.
	 */
	private function readerColumns(array $columns): array {
		foreach (self::READER_BLOCKED_COLUMNS as $column) {
			unset($columns[$column]);
		}

		return $columns;
	}//end readerColumns()

	/**
	 * Whether a filtered entry matches a free-text search.
	 *
	 * Matched against the change set the reader is given, case-insensitively,
	 * so a search can only find what the reader can see.
	 *
	 * @param array  $entry  The filtered entry.
	 * @param string $search The search term.
	 *
	 * @return bool True when the term occurs in the entry's change set.
	 */
	private function matches(array $entry, string $search): bool {
		$encoded = json_encode(($entry['changed'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($encoded === false) {
			return false;
		}

		return mb_stripos($encoded, $search) !== false;
	}//end matches()
}//end class
