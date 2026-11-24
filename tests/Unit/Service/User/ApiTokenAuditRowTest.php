<?php

/**
 * ApiTokenAuditRow builds the audit entry of a minted or revoked API token.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/capability-rows-verified-c/specs/enhanced-audit-trail/spec.md#requirement-changes-to-authorisation-rules-api-tokens-and-record-types-are-on-the-audit-trail
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\User;

use DateTimeImmutable;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\AuditEntry;
use OCA\OpenRegister\Service\Audit\AuditEntryWriter;
use OCA\OpenRegister\Service\User\ApiTokenAuditRow;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\User\ApiTokenAuditRow
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Core\AuditEntry
 * @uses   \OCA\OpenRegister\Service\Audit\AuditEntryWriter
 * @uses   \OCA\OpenRegister\Db\AuditTrail
 */
final class ApiTokenAuditRowTest extends TestCase {

	/**
	 * A user with this id and display name.
	 *
	 * @param string $uid  The id.
	 * @param string $name The display name.
	 *
	 * @return IUser
	 */
	private function user(string $uid, string $name): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn($name);

		return $user;
	}//end user()

	/**
	 * The entry names the actor, the token's id, name and lifetime and the owner, nothing else.
	 *
	 * @return void
	 */
	public function testTheEntryCopiesOnlyTheTokensIdNameAndLifetime(): void {
		$before = new DateTimeImmutable();
		$entry  = (new ApiTokenAuditRow())->row(
			action: ApiTokenAuditRow::CREATED,
			token: ['id' => 'tok1', 'name' => 'gis', 'expires' => '2027-01-01T00:00:00+00:00', 'token' => 'secret', 'hash' => 'h', 'preview' => 'cret'],
			ownerId: 'jan',
			actor: $this->user(uid: 'beheerder', name: 'Beheerder')
		);

		$this->assertInstanceOf(expected: AuditEntry::class, actual: $entry);
		$this->assertSame(expected: 'api-token.created', actual: $entry->action);
		$this->assertSame(expected: 'beheerder', actual: $entry->user);
		$this->assertSame(expected: 'Beheerder', actual: $entry->userName);
		$this->assertSame(
			expected: ['token' => ['id' => 'tok1', 'name' => 'gis', 'expires' => '2027-01-01T00:00:00+00:00'], 'owner' => 'jan'],
			actual: $entry->changed
		);
		$this->assertMatchesRegularExpression(pattern: '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', string: $entry->uuid);
		$this->assertGreaterThanOrEqual(expected: $before, actual: $entry->created);
		$this->assertNull(actual: $entry->objectUuid);
	}//end testTheEntryCopiesOnlyTheTokensIdNameAndLifetime()

	/**
	 * Without an actor the entry reads as the system, and missing token fields stay empty.
	 *
	 * @return void
	 */
	public function testWithoutAnActorTheEntryReadsAsTheSystem(): void {
		$entry = (new ApiTokenAuditRow())->row(action: ApiTokenAuditRow::REVOKED, token: [], ownerId: 'jan', actor: null);

		$this->assertSame(expected: 'api-token.revoked', actual: $entry->action);
		$this->assertSame(expected: 'system', actual: $entry->user);
		$this->assertSame(expected: 'System', actual: $entry->userName);
		$this->assertSame(expected: ['token' => ['id' => '', 'name' => '', 'expires' => null], 'owner' => 'jan'], actual: $entry->changed);
	}//end testWithoutAnActorTheEntryReadsAsTheSystem()

	/**
	 * The trail's writer stores the same row the class built before it handed over an entry.
	 *
	 * @return void
	 */
	public function testTheTrailStoresTheSameRowAsBefore(): void {
		$stored = [];
		$mapper = $this->createMock(AuditTrailMapperInterface::class);
		$mapper->expects($this->once())->method('insertAuditTrails')->willReturnCallback(
			function (array $entries) use (&$stored): array {
				$stored = $entries;
				return $entries;
			}
		);
		$entry = (new ApiTokenAuditRow())->row(action: ApiTokenAuditRow::CREATED, token: ['id' => 'tok1', 'name' => 'gis'], ownerId: 'jan', actor: $this->user(uid: 'beheerder', name: 'Beheerder'));

		(new AuditEntryWriter(mapper: $mapper))->writeAuditEntries(entries: [$entry]);

		$this->assertCount(expectedCount: 1, haystack: $stored);
		$row = $stored[0];
		$this->assertInstanceOf(expected: AuditTrail::class, actual: $row);
		$this->assertSame(expected: $entry->uuid, actual: $row->getUuid());
		$this->assertSame(expected: 'api-token.created', actual: $row->getAction());
		$this->assertSame(expected: 'beheerder', actual: $row->getUser());
		$this->assertSame(expected: 'Beheerder', actual: $row->getUserName());
		$this->assertSame(expected: $entry->changed, actual: $row->getChanged());
		$this->assertSame(expected: $entry->created->format('U.u'), actual: $row->getCreated()?->format('U.u'));
		$this->assertNull(actual: $row->getObjectUuid());
		$this->assertNull(actual: $row->getRegister());
		$this->assertNull(actual: $row->getSchema());
	}//end testTheTrailStoresTheSameRowAsBefore()
}//end class
