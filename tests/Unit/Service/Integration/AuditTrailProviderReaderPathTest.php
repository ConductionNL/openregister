<?php

/**
 * The audit-trail integration leaf shows an object's entries as its history does.
 *
 * The leaf is listed under the object's own route after the object guard, so
 * any reader of the object reaches it. What it returns has to be what the
 * reader path gives that caller: no value of a property the caller may not
 * see, no session, request or IP address, and nothing at all when the object
 * read or the doelbinding guard refuses.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-history-of-one-object-is-readable-by-whoever-may-read-that-object
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

use OCA\OpenRegister\Service\Audit\ObjectHistoryForReaders;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Service\Integration\BuiltinProviders\AuditTrailProvider;
use OCA\OpenRegister\Tests\Support\BuildsReaderHistory;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * The integration leaf answers through the reader path.
 */
final class AuditTrailProviderReaderPathTest extends TestCase {
	use BuildsReaderHistory;

	/**
	 * The leaf over the given reader path.
	 *
	 * @param ObjectHistoryForReaders $history The reader path.
	 *
	 * @return AuditTrailProvider The provider.
	 */
	private function provider(ObjectHistoryForReaders $history): AuditTrailProvider {
		return new AuditTrailProvider($history, $this->createMock(IL10N::class));
	}//end provider()

	public function testAReaderGetsTheEntryWithoutTheHiddenPropertyOrTheSession(): void {
		$rows = $this->provider(
			$this->readerHistory(rows: [$this->readerEntry(objectUuid: self::$readableUuid)])
		)->list(new ObjectRef(register: '5', schema: '7', id: self::$readableUuid));

		self::assertCount(1, $rows);
		self::assertSame(['old' => 'Oud', 'new' => 'Nieuw'], $rows[0]['changed']['titel']);
		self::assertArrayNotHasKey('interneAantekening', $rows[0]['changed']);
		foreach (['session', 'request', 'ipAddress'] as $field) {
			self::assertArrayNotHasKey($field, $rows[0]);
		}

		$json = (string)json_encode($rows);
		self::assertStringNotContainsString('klokkenluider', $json);
		self::assertStringNotContainsString('sess-other', $json);
		self::assertStringNotContainsString('203.0.113.9', $json);
	}//end testAReaderGetsTheEntryWithoutTheHiddenPropertyOrTheSession()

	public function testAMemberOfTheReadGroupSeesTheProperty(): void {
		$rows = $this->provider(
			$this->readerHistory(
				rows: [$this->readerEntry(objectUuid: self::$readableUuid)],
				world: ['groups' => ['redactie']]
			)
		)->list(new ObjectRef(register: '5', schema: '7', id: self::$readableUuid));

		self::assertSame(['old' => 'klokkenluider', 'new' => 'geheim'], $rows[0]['changed']['interneAantekening']);
		self::assertArrayNotHasKey('session', $rows[0]);
	}//end testAMemberOfTheReadGroupSeesTheProperty()

	public function testEveryEntryOfTheObjectIsListedNewestFirstAsTheHistoryReadsThem(): void {
		$rows = $this->provider(
			$this->readerHistory(
				rows: array_map(
					fn (int $i) => $this->readerEntry(objectUuid: self::$readableUuid, action: 'update-' . $i),
					range(1, 30)
				)
			)
		)->list(new ObjectRef(register: '5', schema: '7', id: self::$readableUuid));

		self::assertCount(30, $rows);
		self::assertSame(range(0, 29), array_keys($rows));
	}//end testEveryEntryOfTheObjectIsListedNewestFirstAsTheHistoryReadsThem()

	public function testACallerWhoMayNotReadTheObjectGetsNoEntries(): void {
		$rows = $this->provider(
			$this->readerHistory(
				rows: [$this->readerEntry(objectUuid: self::$readableUuid)],
				world: ['readable' => false]
			)
		)->list(new ObjectRef(register: '5', schema: '7', id: self::$readableUuid));

		self::assertSame([], $rows);
	}//end testACallerWhoMayNotReadTheObjectGetsNoEntries()

	public function testARefusedPurposeGivesNoEntries(): void {
		$rows = $this->provider(
			$this->readerHistory(
				rows: [$this->readerEntry(objectUuid: self::$readableUuid)],
				world: ['purposeRefused' => true]
			)
		)->list(new ObjectRef(register: '5', schema: '7', id: self::$readableUuid));

		self::assertSame([], $rows);
	}//end testARefusedPurposeGivesNoEntries()

	public function testTheObjectIsAskedForByThePathItWasListedUnder(): void {
		$history = $this->createMock(ObjectHistoryForReaders::class);
		$history->expects($this->once())->method('page')
			->with('reg-slug', 'schema-slug', 'obj-1', $this->callback(static fn (array $params): bool => $params['limit'] === PHP_INT_MAX))
			->willReturn(['results' => [5 => ['uuid' => 'a']], 'total' => 1]);

		self::assertSame([['uuid' => 'a']], $this->provider($history)->list(new ObjectRef(register: 'reg-slug', schema: 'schema-slug', id: 'obj-1')));
	}//end testTheObjectIsAskedForByThePathItWasListedUnder()
}//end class
