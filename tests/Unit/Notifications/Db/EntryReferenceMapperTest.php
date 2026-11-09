<?php

/**
 * Unit tests for EntryReferenceMapper and the EntryReference entity.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Db;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

use DateTime;
use OCA\OpenRegister\Notifications\Db\EntryReference;
use OCA\OpenRegister\Notifications\Db\EntryReferenceMapper;
use OCA\OpenRegister\Tests\Unit\Db\RecordingQueryBuilderTrait;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Notifications\Db\EntryReferenceMapper
 * @covers \OCA\OpenRegister\Notifications\Db\EntryReference
 */
final class EntryReferenceMapperTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private function database(): IDBConnection {
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());
		return $db;
	}

	private function log(): string {
		return implode("\n", $this->queryLog);
	}

	public function testFindForEntryReturnsReferencesInIdOrder(): void {
		$this->queuedRows = [[['id' => 1, 'entry_uuid' => 'e', 'code' => 'Z-1'], ['id' => 2, 'entry_uuid' => 'e', 'code' => 'Z-2']]];
		$mapper = new EntryReferenceMapper($this->database());

		$rows = $mapper->findForEntry('e');

		$this->assertSame(['Z-1', 'Z-2'], array_map(fn (EntryReference $r) => $r->getCode(), $rows));
		$log = $this->log();
		$this->assertStringContainsString('where(eq(entry_uuid, :p1, NULL))', $log);
		$this->assertStringContainsString('orderBy(id, ASC)', $log);
	}

	public function testDeleteForEntryDeletesOnTheEntryAndReturnsTheCount(): void {
		$mapper = new EntryReferenceMapper($this->database());

		$this->assertSame(1, $mapper->deleteForEntry('e'));
		$log = $this->log();
		$this->assertStringContainsString('delete(openregister_', $log);
		$this->assertStringContainsString('where(eq(entry_uuid, :p1, NULL))', $log);
	}

	public function testEntitySerialisesAllFieldsWithAtomCreated(): void {
		$r = new EntryReference();
		$r->setEntryUuid('e');
		$r->setSourceUuid('s');
		$r->setTargetUuid('t');
		$r->setCode('Z-1');
		$r->setPatternSlug('zaak');
		$r->setUrl('/x');
		$this->assertNull($r->jsonSerialize()['created']);
		$r->setCreated(new DateTime('2026-01-02T03:04:05+00:00'));

		$this->assertSame(
			['id' => null, 'entryUuid' => 'e', 'sourceUuid' => 's', 'targetUuid' => 't', 'code' => 'Z-1', 'patternSlug' => 'zaak', 'url' => '/x', 'created' => '2026-01-02T03:04:05+00:00'],
			$r->jsonSerialize()
		);
	}
}
