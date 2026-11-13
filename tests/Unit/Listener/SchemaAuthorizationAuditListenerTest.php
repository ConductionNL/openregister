<?php

/**
 * A changed authorisation block goes on the audit trail, with its actor and both versions.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/capability-rows-verified-c/specs/enhanced-audit-trail/spec.md#requirement-changes-to-authorisation-rules-api-tokens-and-record-types-are-on-the-audit-trail
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\SchemaDeletedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Listener\SchemaAuthorizationAuditListener;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Listener\SchemaAuthorizationAuditListener
 * @uses   \OCA\OpenRegister\Service\Rbac\AuditRowWriter
 */
final class SchemaAuthorizationAuditListenerTest extends TestCase {

	/**
	 * The rows the audit trail received.
	 *
	 * @var array<int, AuditTrail>
	 */
	private array $written = [];

	/**
	 * A schema `meldingen` with the given rules.
	 *
	 * @param array<string, mixed>|null $authorization The rules.
	 *
	 * @return Schema
	 */
	private function schema(?array $authorization): Schema {
		$schema = new Schema();
		$schema->setId(12);
		$schema->setUuid('00000000-0000-4000-8000-00000000000c');
		$schema->setSlug('meldingen');
		$schema->setTitle('Meldingen');
		$schema->setAuthorization($authorization);

		return $schema;
	}//end schema()

	/**
	 * The listener over a recording audit trail, acting as `beheerder`.
	 *
	 * @param bool $failing Whether the audit trail refuses the write.
	 *
	 * @return SchemaAuthorizationAuditListener
	 */
	private function listener(bool $failing = false): SchemaAuthorizationAuditListener {
		$mapper = $this->createMock(AuditTrailMapperInterface::class);
		$mapper->method('insertAuditTrails')->willReturnCallback(
			function (array $entries) use ($failing): int {
				if ($failing === true) {
					throw new RuntimeException('database gone');
				}

				array_push($this->written, ...$entries);
				return count($entries);
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('beheerder');
		$user->method('getDisplayName')->willReturn('Beheerder Gemeente');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new SchemaAuthorizationAuditListener($mapper, $session, $this->createMock(LoggerInterface::class));
	}//end listener()

	/**
	 * Adding a group to the read rule writes one entry naming the actor, the schema and both rules.
	 *
	 * @return void
	 */
	public function testAWidenedReadRuleIsTraceable(): void {
		$before = ['read' => ['behandelaars']];
		$after = ['read' => ['behandelaars', 'stagiairs']];

		$this->listener()->handle(new SchemaUpdatedEvent($this->schema($after), $this->schema($before)));

		$this->assertCount(1, $this->written);
		$row = $this->written[0];
		$this->assertSame('schema.authorization.updated', $row->getAction());
		$this->assertSame('beheerder', $row->getUser());
		$this->assertSame('Beheerder Gemeente', $row->getUserName());
		$this->assertSame(12, $row->getSchema());
		$this->assertSame('00000000-0000-4000-8000-00000000000c', $row->getSchemaUuid());
		$this->assertSame(
			['schema' => 'meldingen', 'authorization' => ['old' => $before, 'new' => $after]],
			$row->getChanged()
		);
		$this->assertNotNull($row->getCreated());
	}//end testAWidenedReadRuleIsTraceable()

	/**
	 * Rules set for the first time, or removed, are a change too.
	 *
	 * @return void
	 */
	public function testRulesAddedOrRemovedAreTraceable(): void {
		$listener = $this->listener();

		$listener->handle(new SchemaUpdatedEvent($this->schema(['read' => ['public']]), $this->schema(null)));
		$listener->handle(new SchemaUpdatedEvent($this->schema(null), $this->schema(['read' => ['public']])));

		$this->assertSame(
			[['old' => [], 'new' => ['read' => ['public']]], ['old' => ['read' => ['public']], 'new' => []]],
			array_map(static fn (AuditTrail $row): array => $row->getChanged()['authorization'], $this->written)
		);
	}//end testRulesAddedOrRemovedAreTraceable()

	/**
	 * A save that keeps the rules, even with their keys in another order, writes nothing.
	 *
	 * @return void
	 */
	public function testUnchangedRulesWriteNothing(): void {
		$listener = $this->listener();

		$listener->handle(
			new SchemaUpdatedEvent(
				$this->schema(['update' => ['editors'], 'read' => ['behandelaars']]),
				$this->schema(['read' => ['behandelaars'], 'update' => ['editors']])
			)
		);
		$listener->handle(new SchemaUpdatedEvent($this->schema(null), $this->schema([])));

		$this->assertSame([], $this->written);
	}//end testUnchangedRulesWriteNothing()

	/**
	 * The order of a rule's groups is kept, so reordering them is recorded.
	 *
	 * @return void
	 */
	public function testReorderedGroupsAreAChange(): void {
		$this->listener()->handle(
			new SchemaUpdatedEvent($this->schema(['read' => ['b', 'a']]), $this->schema(['read' => ['a', 'b']]))
		);

		$this->assertCount(1, $this->written);
	}//end testReorderedGroupsAreAChange()

	/**
	 * A failing audit write never fails the schema save.
	 *
	 * @return void
	 */
	public function testAFailingWriteDoesNotEscape(): void {
		$this->listener(failing: true)->handle(
			new SchemaUpdatedEvent($this->schema(['read' => ['x']]), $this->schema(['read' => ['y']]))
		);

		$this->assertSame([], $this->written);
	}//end testAFailingWriteDoesNotEscape()

	/**
	 * Other events are ignored.
	 *
	 * @return void
	 */
	public function testOtherEventsAreIgnored(): void {
		$this->listener()->handle(new SchemaDeletedEvent($this->schema(['read' => ['x']])));

		$this->assertSame([], $this->written);
	}//end testOtherEventsAreIgnored()
}//end class
