<?php

/**
 * Writes the audit entries Core hands over as sealed audit trail rows.
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
 * @spec openspec/specs/audit-hash-chain/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Audit;

use DateTime;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\AuditEntry;
use OCA\OpenRegister\Kernel\Contracts\Core\AuditEntryWriterInterface;

/**
 * Turns each entry into an audit trail row and inserts them through the mapper, which seals them.
 */
final class AuditEntryWriter implements AuditEntryWriterInterface {

	/**
	 * Constructor.
	 *
	 * @param AuditTrailMapperInterface $mapper The trail, which owns the hash chain.
	 */
	public function __construct(
		private readonly AuditTrailMapperInterface $mapper,
	) {
	}//end __construct()

	/**
	 * Write the entries, in order; a database failure is thrown.
	 *
	 * @param list<AuditEntry> $entries The entries.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	public function writeAuditEntries(array $entries): void {
		$this->mapper->insertAuditTrails(entries: array_map(self::rowFor(...), $entries));
	}//end writeAuditEntries()

	/**
	 * The unsealed row for one entry.
	 *
	 * @param AuditEntry $entry The entry.
	 *
	 * @return AuditTrail The row.
	 */
	private static function rowFor(AuditEntry $entry): AuditTrail {
		$row = new AuditTrail();
		$row->setUuid($entry->uuid);
		$row->setAction($entry->action);
		$row->setUser($entry->user);
		$row->setUserName($entry->userName);
		$row->setChanged($entry->changed);
		$row->setCreated(DateTime::createFromInterface($entry->created));
		if ($entry->objectUuid !== null) {
			$row->setObjectUuid($entry->objectUuid);
		}

		return $row;
	}//end rowFor()
}//end class
