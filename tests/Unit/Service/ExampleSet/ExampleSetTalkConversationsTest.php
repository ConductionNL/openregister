<?php

/**
 * Tests for the example set's Talk conversations.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-talk-conversations-on-its-objects
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

/**
 * A fragment puts a conversation with a held call on a case, links it, and remove takes it away.
 */
class ExampleSetTalkConversationsTest extends ExampleSetTestCase {

	/**
	 * Set up a case schema and the cast.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store->offer(register: 'cases', schema: 'case');
		$this->app(app: 'openregister', fragment: $this->castFile());
	}//end setUp()

	/**
	 * A case with the given conversations.
	 *
	 * @param array<int, array<string, mixed>> $rows The conversation rows.
	 *
	 * @return void
	 */
	private function caseWithConversations(array $rows): void {
		$this->app(app: 'alpha', fragment: [
			'objects'           => [['key' => 'case-1', 'register' => 'cases', 'schema' => 'case', 'data' => ['title' => 'Zaak']]],
			'talkConversations' => $rows,
		]);
	}//end caseWithConversations()

	/**
	 * The call with the requester, held and ended.
	 *
	 * @param array<string, mixed> $extra Fields to add or replace.
	 *
	 * @return array<string, mixed>
	 */
	private static function conversation(array $extra = []): array {
		return [...[
			'object'       => '{{object:case-1.id}}',
			'name'         => 'Gesprek met Sanne de Vries over de stukken',
			'owner'        => '{{cast:esra.uid}}',
			'participants' => ['{{cast:sanne.uid}}'],
			'call'         => ['start' => '2026-10-04T10:30:00+02:00', 'end' => '2026-10-04T10:42:00+02:00'],
		], ...$extra];
	}//end conversation()

	/**
	 * The room is created with its participants and held call, linked to the case, kept on a second install, and
	 * taken away on remove.
	 *
	 * @return void
	 */
	public function testAConversationWithAHeldCallIsLinkedOnceAndTakenAway(): void {
		$this->caseWithConversations(rows: [['key' => 'gesprek-sanne', 'data' => self::conversation()]]);
		$plan = $this->catalogue->gather(set: 'demo');

		$this->assertSame([], $this->installer->check(plan: $plan, today: $this->today())['refusals']);
		$this->assertSame([], $this->talk['rooms'], 'the check writes no room');

		$report = $this->installer->install(plan: $plan, today: $this->today());
		$this->assertTrue($report['ok'], (string) json_encode($report));
		$room = array_values($this->talk['rooms'])[0];
		$this->assertSame(['esra.demir', 'sanne.devries'], $room['participants']);
		$this->assertSame(['call_started', 'call_ended'], array_column($this->talk['messages'], 'message'));
		$this->assertSame(720, $this->talk['messages'][1]['duration']);

		$caseId = $this->store->ids(register: 'cases', schema: 'case')[0];
		$link   = array_values($this->talkLinks)[0];
		$this->assertSame($caseId, $link->getObjectUuid());
		$this->assertSame('alpha', $link->getLinkedBy());

		$this->installer->install(plan: $plan, today: $this->today());
		$this->assertCount(1, $this->talk['rooms'], 'a kept conversation is written once');
		$this->assertCount(2, $this->talk['messages'], 'its call is written once');

		$removed = $this->remover->remove(set: 'demo');
		$this->assertSame([], $removed['failed']);
		$this->assertSame([], $this->talk['rooms']);
		$this->assertSame([], $this->talkLinks);
	}//end testAConversationWithAHeldCallIsLinkedOnceAndTakenAway()

	/**
	 * Without Talk the install reports the conversation as not written.
	 *
	 * @return void
	 */
	public function testWithoutTalkTheConversationIsNotWritten(): void {
		$this->talkInstalled = false;
		$this->caseWithConversations(rows: [['key' => 'gesprek-sanne', 'data' => self::conversation()]]);

		$report = $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$this->assertFalse($report['ok']);
		$this->assertStringContainsString('Talk is not installed', (string) json_encode($report['dropped']));
	}//end testWithoutTalkTheConversationIsNotWritten()

	/**
	 * A faulty conversation is refused at check time and nothing is written.
	 *
	 * @param array<string, mixed> $data   The row's fields.
	 * @param string               $reason A part of the refusal.
	 *
	 * @return void
	 *
	 * @dataProvider refusedConversations
	 */
	public function testACheckRefusesAFaultyConversation(array $data, string $reason): void {
		$this->caseWithConversations(rows: [['key' => 'bad', 'data' => $data]]);

		$refusals = $this->installer->check(plan: $this->catalogue->gather(set: 'demo'), today: $this->today())['refusals'];

		$this->assertCount(1, $refusals);
		$this->assertStringContainsString($reason, $refusals[0]);
		$this->assertSame([], $this->talk['rooms']);
	}//end testACheckRefusesAFaultyConversation()

	/**
	 * The faulty conversations and a part of their refusal.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function refusedConversations(): array {
		return [
			'no name'          => [self::conversation(['name' => '']), 'needs a name'],
			'unknown field'    => [self::conversation(['topic' => 'x']), 'not a talk conversation field'],
			'foreign person'   => [self::conversation(['participants' => ['nobody']]), 'not an account the set creates or adopts'],
			'call without end' => [self::conversation(['call' => ['start' => '2026-10-04T10:30:00+02:00']]), 'needs a start and an end'],
			'call backwards'   => [self::conversation(['call' => ['start' => '2026-10-04T10:42:00+02:00', 'end' => '2026-10-04T10:30:00+02:00']]), 'ends after it starts'],
		];
	}//end refusedConversations()
}//end class
