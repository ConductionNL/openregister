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
use DateTimeImmutable;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\AuditEntry;
use OCA\OpenRegister\Kernel\Contracts\Core\AuditEntryWriterInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\PlacedAuditEntry;
use OCA\OpenRegister\Kernel\Contracts\SystemAuditLineInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Turns each entry into an audit trail row and inserts them through the mapper, which seals them.
 *
 * @spec openspec/specs/audit-hash-chain/spec.md
 */
final class AuditEntryWriter implements AuditEntryWriterInterface, SystemAuditLineInterface {

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
	 * @param list<AuditEntry|PlacedAuditEntry> $entries The entries.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	public function writeAuditEntries(array $entries): void {
		$this->mapper->insertAuditTrails(entries: array_map(self::rowFor(...), $entries));
	}//end writeAuditEntries()

	/**
	 * Write one audit line by the system, dated now.
	 *
	 * @param string               $action   What happened.
	 * @param string               $userName The display name the line carries.
	 * @param array<string, mixed> $changed  What changed.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) Uuid::v4 is the standard symfony/uid factory.
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	public function recordSystemLine(string $action, string $userName, array $changed): void {
		$this->writeAuditEntries(
			entries: [
				new AuditEntry(
					uuid: Uuid::v4()->toRfc4122(),
					action: $action,
					user: 'system',
					userName: $userName,
					changed: $changed,
					created: new DateTimeImmutable(),
				),
			]
		);
	}//end recordSystemLine()

	/**
	 * The unsealed row for one entry.
	 *
	 * @param AuditEntry|PlacedAuditEntry $entry The entry.
	 *
	 * @return AuditTrail The row.
	 */
	private static function rowFor(AuditEntry|PlacedAuditEntry $entry): AuditTrail {
		if ($entry instanceof PlacedAuditEntry) {
			return self::placedRowFor(placed: $entry);
		}

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

		if ($entry->organisationId !== null) {
			$row->setOrganisationId($entry->organisationId);
		}

		return $row;
	}//end rowFor()

	/**
	 * The unsealed row for one placed entry, filed under its register and schema where known.
	 *
	 * @param PlacedAuditEntry $placed The placed entry.
	 *
	 * @return AuditTrail The row.
	 */
	private static function placedRowFor(PlacedAuditEntry $placed): AuditTrail {
		$row = self::rowFor(entry: $placed->entry);
		if ($placed->register !== null) {
			$row->setRegister($placed->register);
		}

		if ($placed->schema !== null) {
			$row->setSchema($placed->schema);
		}

		return $row;
	}//end placedRowFor()
}//end class
