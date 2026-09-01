<?php

/**
 * An in-memory CaseItemAuditMapper that records what was appended.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Case
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Case;

use OCA\OpenRegister\Db\CaseItemAudit;
use OCA\OpenRegister\Db\CaseItemAuditMapper;
use OCP\AppFramework\Db\Entity;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Append-only audit storage in memory.
 */
class RecordingAuditMapper extends CaseItemAuditMapper {

	/**
	 * Everything appended.
	 *
	 * @var array<int, CaseItemAudit>
	 */
	public array $entries = [];

	/**
	 * When true, the next insert throws (to test rollback).
	 *
	 * @var boolean
	 */
	public bool $failNext = false;

	/**
	 * Constructor over a mocked connection.
	 *
	 * @param TestCase $test The test.
	 */
	public function __construct(TestCase $test) {
		parent::__construct(db: $test->getMockBuilder(IDBConnection::class)->getMock());

	}//end __construct()

	/**
	 * Append.
	 *
	 * @param Entity $entity The entry.
	 *
	 * @return CaseItemAudit The entry.
	 */
	public function insert(Entity $entity): CaseItemAudit {
		if ($this->failNext === true) {
			$this->failNext = false;
			throw new RuntimeException('audit table unavailable');
		}

		/*
		 * @var CaseItemAudit $entity
		 */
		$entity->setId(count($this->entries) + 1);
		$this->entries[] = $entity;

		return $entity;
	}//end insert()

	/**
	 * For one item.
	 *
	 * @param int $caseItemId The item id.
	 *
	 * @return array<int, CaseItemAudit> The entries.
	 */
	public function findForItem(int $caseItemId): array {
		return array_values(array_filter($this->entries, static fn (CaseItemAudit $entry): bool => $entry->getCaseItemId() === $caseItemId));
	}//end findForItem()

	/**
	 * For several items.
	 *
	 * @param array<int, int> $caseItemIds The item ids.
	 *
	 * @return array<int, CaseItemAudit> The entries.
	 */
	public function findForItems(array $caseItemIds): array {
		return array_values(array_filter($this->entries, static fn (CaseItemAudit $entry): bool => in_array($entry->getCaseItemId(), $caseItemIds, true)));
	}//end findForItems()

	/**
	 * Entries for an item as `from->to (cause)` strings, for compact assertions.
	 *
	 * @param int $caseItemId The item id.
	 *
	 * @return array<int, string> The trail.
	 */
	public function trail(int $caseItemId): array {
		return array_map(
			static fn (CaseItemAudit $entry): string => sprintf('%s->%s (%s)', (string)$entry->getFromState(), (string)$entry->getToState(), (string)$entry->getCause()),
			$this->findForItem($caseItemId)
		);
	}//end trail()
}//end class
