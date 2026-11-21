<?php

/**
 * Reads one object's audit trail rows as the plain fields Core asks for.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Audit;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrail\ActionLookupOptions;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\ObjectTrailReaderInterface;

/**
 * Looks the object's rows up by uuid through the mapper, every action, and hands back their fields.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class ObjectTrailReader implements ObjectTrailReaderInterface {

	/**
	 * Constructor.
	 *
	 * @param AuditTrailMapperInterface $mapper The trail.
	 */
	public function __construct(
		private readonly AuditTrailMapperInterface $mapper,
	) {
	}//end __construct()

	/**
	 * One object's entries, every action, newest first; a database failure is thrown.
	 *
	 * @param string $objectUuid The object's uuid.
	 * @param int    $limit      The most entries to read.
	 *
	 * @return list<array{action: string|null, user: string|null, userName: string|null, changed: mixed, created: \DateTimeInterface|null}> The entries.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function objectTrail(string $objectUuid, int $limit): array {
		$rows = $this->mapper->findObjectActions(
			objectUuid: $objectUuid,
			options: new ActionLookupOptions(actions: [], limit: $limit)
		);

		return array_values(array_map(self::fieldsOf(...), $rows));
	}//end objectTrail()

	/**
	 * The fields of one row.
	 *
	 * @param AuditTrail $row The row.
	 *
	 * @return array{action: string|null, user: string|null, userName: string|null, changed: mixed, created: \DateTimeInterface|null} The fields.
	 */
	private static function fieldsOf(AuditTrail $row): array {
		return [
			'action' => $row->getAction(),
			'user' => $row->getUser(),
			'userName' => $row->getUserName(),
			'changed' => $row->getChanged(),
			'created' => $row->getCreated(),
		];
	}//end fieldsOf()
}//end class
