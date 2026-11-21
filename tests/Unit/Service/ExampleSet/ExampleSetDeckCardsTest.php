<?php

/**
 * A fragment may put Deck cards on its objects: checked before anything is
 * written, the board, list and labels found or written, the card written and
 * linked, proved, and taken away on remove with what the set wrote for it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetDeckCards
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineKinds
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstaller
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetRemover
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetCatalogue
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetRecord
 * @uses \OCA\OpenRegister\Db\DeckLink
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAppConfig
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAppointments
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetArchival
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAuditEntries
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetCasePlans
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetChanges
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetCollectivesPages
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetEmailLinks
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineRows
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetExportRuns
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFiles
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFlows
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstance
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetLock
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetNotes
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetOrder
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetParties
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPaths
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPeople
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPrerequisites
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetProof
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetReadStates
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRewriter
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetTalkConversations
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetTasks
 * @uses \OCA\OpenRegister\Notifications\Service\ExampleSet\ExampleSetTimelineEntries
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetUserPreferences
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetValues
 * @uses \OCA\OpenRegister\Search\Service\ExampleSet\ExampleSetViews
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetWatchers
 * @uses \OCA\OpenRegister\Service\WriteCause
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAccessLinks
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFlowRuns
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetGrants
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetCastShape
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetConfigShape
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetFragmentFile
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetFragmentShape
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetProofPlace
 * @uses \OCA\OpenRegister\Kernel\Contracts\ObjectRef
 * @uses \OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef
 * @uses \OCA\OpenRegister\Service\CauseFrame
 * @uses \OCA\OpenRegister\Service\ExampleSet\CastOrganisationsRun
 * @uses \OCA\OpenRegister\Service\ExampleSet\DeckBoardRequest
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAccountTerms
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAccountsInstall
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAccountsRemoval
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAppAndHistoryRowKinds
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAppRowKinds
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetCastOrganisations
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetChangeFields
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetChangeFollower
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetChangeReadBack
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetCheckRun
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetConfigStore
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFieldRules
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFillRun
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFillScope
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFragmentCheck
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFragmentRows
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFragmentWrite
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetHistoryRowKinds
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstallRequest
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstallRun
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstallerObjects
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstallerSetup
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstallerWriters
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetKeptObject
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetLinkRowKinds
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetNewAccount
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetOwnRowKinds
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPeopleRun
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRecordPart
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRecordedObject
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRemovalRun
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRemoverObjects
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRemoverRows
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRemoverSettings
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetResolveScope
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetResolvedObject
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRestorePart
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRow
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRowInstall
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRowShape
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetVouchedAccounts
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetWorkRowKinds
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetWrittenValue
 * @uses \OCA\OpenRegister\Service\ExampleSet\FilledRow
 * @uses \OCA\OpenRegister\Service\ExampleSet\FragmentDeclarations
 * @uses \OCA\OpenRegister\Service\ExampleSet\FragmentOrdering
 * @uses \OCA\OpenRegister\Service\ExampleSet\FragmentPart
 * @uses \OCA\OpenRegister\Service\ExampleSet\ObjectCheck
 * @uses \OCA\OpenRegister\Service\ExampleSet\RecordLedger
 * @uses \OCA\OpenRegister\Service\ExampleSet\RowInstallRun
 * @uses \OCA\OpenRegister\Service\ExampleSet\RowInstallStep
 */
class ExampleSetDeckCardsTest extends ExampleSetTestCase {
	/**
	 * A case register and the cast.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store->offer(register: 'cases', schema: 'case');
		$this->app(app: 'openregister', fragment: $this->castFile());
	}

	/**
	 * One case and the cards given.
	 *
	 * @param array<int, array<string, mixed>> $cards The fragment's card rows.
	 *
	 * @return void
	 */
	private function caseWithCards(array $cards): void {
		$this->app(app: 'alpha', fragment: [
			'objects'   => [['key' => 'case-1', 'register' => 'cases', 'schema' => 'case', 'data' => ['title' => 'Zaak']]],
			'deckCards' => $cards,
		]);
	}//end caseWithCards()

	/**
	 * A card on the team board.
	 *
	 * @param array<string, mixed> $extra Fields to add or replace.
	 *
	 * @return array<string, mixed>
	 */
	private static function card(array $extra = []): array {
		return [...[
			'object'    => '{{object:case-1.id}}',
			'board'     => 'Woo en informatie',
			'list'      => 'Deze week',
			'title'     => 'Lakken offerte lantaarnpalen',
			'owner'     => 'esra.demir',
			'assignees' => ['esra.demir'],
			'labels'    => [['title' => 'Bezig', 'color' => 'e9a23b']],
			'shareWith' => ['demo-staff'],
			'due'       => '2026-10-24',
		], ...$extra];
	}//end card()

	/**
	 * The card goes on a board and list the set writes, is linked to the case,
	 * kept on a second install, and goes with its board on remove.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-deck-cards-on-its-objects
	 */
	public function testACardIsWrittenLinkedOnceAndTakenAwayWithItsBoard(): void {
		$this->caseWithCards(cards: [['key' => 'offerte', 'data' => self::card()]]);
		$plan = $this->catalogue->gather(set: 'demo');

		$this->assertSame(expected: [], actual: $this->installer->check(plan: $plan, today: $this->today())['refusals']);
		$this->assertSame(expected: [], actual: $this->deck['cards'], message: 'the check writes no card');

		$report = $this->installer->install(plan: $plan, today: $this->today());
		$this->assertTrue(condition: $report['ok'], message: (string) json_encode($report));

		$board = array_values($this->deck['boards'])[0];
		$this->assertSame(expected: ['owner' => 'esra.demir', 'title' => 'Woo en informatie', 'groups' => ['demo-staff']], actual: $board);
		$card = array_values($this->deck['cards'])[0];
		$this->assertSame(expected: 'Lakken offerte lantaarnpalen', actual: $card['title']);
		$this->assertSame(expected: ['esra.demir'], actual: $card['assignees']);
		$this->assertSame(expected: '2026-10-24', actual: $card['due']);
		$this->assertSame(expected: array_keys($this->deck['labels']), actual: $card['labels']);

		$caseId = $this->store->ids(register: 'cases', schema: 'case')[0];
		$link   = array_values($this->deckLinks)[0];
		$this->assertSame(expected: $caseId, actual: $link->getObjectUuid());
		$this->assertSame(expected: 'alpha', actual: $link->getLinkedBy());
		$this->assertSame(expected: 'Bezig', actual: json_decode((string) $link->getLabels(), true)[0]['title']);

		$this->installer->install(plan: $plan, today: $this->today());
		$this->assertCount(expectedCount: 1, haystack: $this->deck['cards'], message: 'a kept card is written once');

		$removed = $this->remover->remove(set: 'demo');
		$this->assertSame(expected: [], actual: $removed['failed']);
		$this->assertSame(expected: [], actual: $this->deck['cards']);
		$this->assertSame(expected: [], actual: $this->deck['labels']);
		$this->assertSame(expected: [], actual: $this->deck['stacks']);
		$this->assertSame(expected: [], actual: $this->deck['boards']);
		$this->assertSame(expected: [], actual: $this->deckLinks);
	}//end testACardIsWrittenLinkedOnceAndTakenAwayWithItsBoard()

	/**
	 * A board that is already there is used and stays on remove.
	 *
	 * @return void
	 */
	public function testAnExistingBoardIsUsedAndKept(): void {
		$this->deck['boards'][50] = ['owner' => 'esra.demir', 'title' => 'Woo en informatie', 'groups' => []];
		$this->deck['stacks'][51] = ['board' => 50, 'title' => 'Deze week'];
		$this->caseWithCards(cards: [['key' => 'offerte', 'data' => self::card()]]);
		$plan = $this->catalogue->gather(set: 'demo');
		$this->installer->install(plan: $plan, today: $this->today());

		$this->assertCount(expectedCount: 1, haystack: $this->deck['boards']);
		$this->assertSame(expected: 51, actual: array_values($this->deck['cards'])[0]['stack']);

		$this->remover->remove(set: 'demo');
		$this->assertArrayHasKey(key: 50, array: $this->deck['boards']);
		$this->assertArrayHasKey(key: 51, array: $this->deck['stacks']);
	}//end testAnExistingBoardIsUsedAndKept()

	/**
	 * Without Deck the install reports the card as not written.
	 *
	 * @return void
	 */
	public function testWithoutDeckTheCardIsNotWritten(): void {
		$this->deckInstalled = false;
		$this->caseWithCards(cards: [['key' => 'offerte', 'data' => self::card()]]);
		$report = $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$this->assertSame(expected: [], actual: $this->deck['cards']);
		$this->assertStringContainsString(needle: 'Deck is not installed', haystack: (string) json_encode($report));
	}//end testWithoutDeckTheCardIsNotWritten()

	/**
	 * A faulty card is refused at check time and nothing is written.
	 *
	 * @param array<string, mixed> $data   The row's fields.
	 * @param string               $reason A part of the refusal.
	 *
	 * @return void
	 *
	 * @dataProvider refusedCards
	 */
	public function testACheckRefusesAFaultyCard(array $data, string $reason): void {
		$this->caseWithCards(cards: [['key' => 'bad', 'data' => $data]]);
		$refusals = $this->installer->check(plan: $this->catalogue->gather(set: 'demo'), today: $this->today())['refusals'];

		$this->assertCount(expectedCount: 1, haystack: $refusals);
		$this->assertStringContainsString(needle: $reason, haystack: $refusals[0]);
		$this->assertSame(expected: [], actual: $this->deck['cards']);
	}//end testACheckRefusesAFaultyCard()

	/**
	 * The faulty cards and a part of their refusal.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function refusedCards(): array {
		return [
			'no board'           => [self::card(extra: ['board' => '']), 'needs a board'],
			'no owner'           => [self::card(extra: ['owner' => '']), 'needs an owner'],
			'a stranger assigned' => [self::card(extra: ['assignees' => ['nobody']]), '"nobody" is not an account'],
			'a bad due date'     => [self::card(extra: ['due' => 'soon']), 'is not a date'],
			'an unknown field'   => [self::card(extra: ['archived' => true]), 'field "archived"'],
		];
	}//end refusedCards()
}//end class
