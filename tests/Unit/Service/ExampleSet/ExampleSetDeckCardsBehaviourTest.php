<?php

/**
 * Behaviour tests for ExampleSetDeckCards: which card rows a set may write, and
 * how a card is placed, linked, checked and reverted.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;


use OCA\OpenRegister\Db\DeckLink;
use OCA\OpenRegister\Db\DeckLinkMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetDeckCards;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetDeckStore;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRow;
use OCA\OpenRegister\Service\Party\PartyService;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use OCA\OpenRegister\Service\ExampleSet\DeckBoardRequest;

/**
 * Behaviour of the Deck cards an example set puts on its objects.
 */
class ExampleSetDeckCardsBehaviourTest extends TestCase {

	/** @var ExampleSetDeckStore&MockObject */
	private ExampleSetDeckStore $store;

	/** @var PartyService&MockObject */
	private PartyService $parties;

	/** @var DeckLinkMapperInterface&MockObject */
	private DeckLinkMapperInterface $links;

	/** @var IUserManager&MockObject */
	private IUserManager $users;

	protected function setUp(): void {
		$this->store   = $this->createMock(ExampleSetDeckStore::class);
		$this->parties = $this->createMock(PartyService::class);
		$this->links   = $this->createMock(DeckLinkMapperInterface::class);
		$this->users   = $this->createMock(IUserManager::class);
	}//end setUp()

	private function cards(): ExampleSetDeckCards {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap(
			[
				[ExampleSetDeckStore::class, $this->store],
				[PartyService::class, $this->parties],
				[DeckLinkMapperInterface::class, $this->links],
				[IUserManager::class, $this->users],
			]
		);
		return new ExampleSetDeckCards($container);
	}//end cards()

	private static function valid(array $override=[]): array {
		return array_merge(['object' => 'obj-1', 'board' => 'Zaken', 'list' => 'Nieuw', 'title' => 'Bellen', 'owner' => 'alice'], $override);
	}//end valid()

	private function objectFound(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setRegister('4');
		$object->setSchema('7');
		$this->parties->method('findUnscoped')->with('obj-1')->willReturn($object);
		return $object;
	}//end objectFound()

	public function testNamesThePartAndNoun(): void {
		$this->assertSame('deckCards', $this->cards()->part());
		$this->assertSame('deck card', $this->cards()->noun());
	}

	public function testAValidCardHasNoRefusal(): void {
		$this->assertSame('', $this->cards()->refusal(self::valid(['assignees' => ['bob'], 'due' => '2026-02-01']), ['alice', 'bob']));
	}

	public function testRefusesAnUnknownField(): void {
		$this->assertStringContainsString('"secret"', $this->cards()->refusal(self::valid(['secret' => 1]), ['alice']));
	}

	public function testRefusesAMissingRequiredField(): void {
		$data = self::valid();
		unset($data['list']);

		$this->assertStringContainsString('a list', $this->cards()->refusal($data, ['alice']));
	}

	public function testRefusesAnAssigneeTheSetDoesNotCreate(): void {
		$this->assertStringContainsString('mallory', $this->cards()->refusal(self::valid(['assignees' => ['mallory']]), ['alice']));
	}

	public function testRefusesAnUnreadableDueDate(): void {
		$this->assertStringContainsString('due', $this->cards()->refusal(self::valid(['due' => 'someday maybe']), ['alice']));
	}

	public function testWriteIsRefusedWhenDeckIsNotInstalled(): void {
		$this->store->method('available')->willReturn(false);
		$this->parties->expects($this->never())->method('findUnscoped');

		$this->assertSame(['error' => 'Deck is not installed', 'entry' => null], $this->cards()->write(new ExampleSetRow('app', 'k', self::valid())));
	}

	public function testWriteIsRefusedWhenTheObjectDoesNotExist(): void {
		$this->store->method('available')->willReturn(true);
		$this->parties->method('findUnscoped')->willReturn(null);
		$this->store->expects($this->never())->method('board');

		$this->assertSame(
			['error' => 'no object "obj-1" to put the card on', 'entry' => null],
			$this->cards()->write(new ExampleSetRow('app', 'k', self::valid()))
		);
	}

	public function testWritePlacesTheCardWithLabelsAndLinksIt(): void {
		$this->store->method('available')->willReturn(true);
		$this->objectFound();
		$this->store->expects($this->once())->method('board')->with(new DeckBoardRequest(owner: 'alice', title: 'Zaken', color: 'ff0000', groups: ['g1']))->willReturn(['id' => 10, 'created' => true]);
		$this->store->expects($this->once())->method('stack')->with(10, 'Nieuw')->willReturn(['id' => 20, 'created' => false]);
		$this->store->method('label')->willReturnMap(
			[
				[10, 'Urgent', '0082c9', ['id' => 31, 'created' => true]],
				[10, 'Wacht', 'cccccc', ['id' => 32, 'created' => false]],
			]
		);
		$this->store->expects($this->once())->method('insertCard')->with(
			20,
			$this->callback(
				static fn (array $card): bool => $card['owner'] === 'alice'
					&& $card['title'] === 'Bellen'
					&& $card['description'] === 'Terugbellen'
					&& $card['due']->format('Y-m-d') === '2026-02-01'
					&& $card['done'] === null
					&& $card['labels'] === [31, 32]
					&& $card['assignees'] === ['bob', 'carol']
			)
		)->willReturn(40);
		$bob = $this->createMock(IUser::class);
		$bob->method('getDisplayName')->willReturn('Bob B.');
		$this->users->method('get')->willReturnMap([['bob', $bob], ['carol', null]]);
		$inserted = null;
		$this->links->expects($this->once())->method('insert')->willReturnCallback(
			static function (DeckLink $link) use (&$inserted): DeckLink {
				$inserted = $link;
				$saved    = new DeckLink();
				$saved->setId(55);
				return $saved;
			}
		);

		$result = $this->cards()->write(
			new ExampleSetRow(
				'myapp',
				'k',
				self::valid(
					[
						'description' => 'Terugbellen',
						'due' => '2026-02-01',
						'done' => '',
						'color' => 'ff0000',
						'shareWith' => ['g1'],
						'labels' => ['Urgent', ['title' => 'Wacht', 'color' => 'cccccc']],
						'assignees' => ['bob', 'carol'],
					]
				)
			)
		);

		$this->assertSame('', $result['error']);
		$this->assertSame(
			[
				'board' => 10,
				'createdBoard' => true,
				'stack' => 20,
				'createdStack' => false,
				'card' => 40,
				'createdLabels' => [31],
				'labels' => [['id' => 31, 'title' => 'Urgent', 'color' => '0082c9'], ['id' => 32, 'title' => 'Wacht', 'color' => 'cccccc']],
				'object' => 'obj-1',
				'link' => 55,
			],
			$result['entry']
		);
		$this->assertSame('obj-1', $inserted->getObjectUuid());
		$this->assertSame(4, $inserted->getRegisterId());
		$this->assertSame(7, $inserted->getSchemaId());
		$this->assertSame(40, $inserted->getCardId());
		$this->assertSame('Bellen', $inserted->getCardTitle());
		$this->assertSame('myapp', $inserted->getLinkedBy());
		$this->assertSame(
			[['uid' => 'bob', 'type' => 'user', 'displayName' => 'Bob B.'], ['uid' => 'carol', 'type' => 'user', 'displayName' => 'carol']],
			json_decode($inserted->getAssignees(), true)
		);
	}

	public function testWriteUsesTheDefaultColourAndNoLabels(): void {
		$this->store->method('available')->willReturn(true);
		$this->objectFound();
		$this->store->expects($this->once())->method('board')->with(new DeckBoardRequest(owner: 'alice', title: 'Zaken', color: '0082c9', groups: []))->willReturn(['id' => 1, 'created' => false]);
		$this->store->method('stack')->willReturn(['id' => 2, 'created' => false]);
		$this->store->expects($this->never())->method('label');
		$this->store->method('insertCard')->willReturn(3);
		$saved = new DeckLink();
		$saved->setId(4);
		$this->links->method('insert')->willReturn($saved);

		$result = $this->cards()->write(new ExampleSetRow('app', 'k', self::valid()));

		$this->assertSame([], $result['entry']['createdLabels']);
	}

	public function testWriteReportsAStoreFailure(): void {
		$this->store->method('available')->willReturn(true);
		$this->objectFound();
		$this->store->method('board')->willThrowException(new \RuntimeException('deck tables missing'));
		$this->links->expects($this->never())->method('insert');

		$this->assertSame(['error' => 'deck tables missing', 'entry' => null], $this->cards()->write(new ExampleSetRow('app', 'k', self::valid())));
	}

	public function testLostFollowsWhetherTheCardStillExists(): void {
		$this->store->method('cardExists')->willReturnMap([[40, true], [41, false]]);

		$this->assertFalse($this->cards()->lost(['card' => 40]));
		$this->assertTrue($this->cards()->lost(['card' => 41]));
	}

	public function testLostIsTrueWhenTheStoreFails(): void {
		$this->store->method('cardExists')->willThrowException(new \RuntimeException('down'));

		$this->assertTrue($this->cards()->lost(['card' => 40]));
	}

	public function testRevertRemovesLinkCardCreatedLabelsAndCreatedPlaces(): void {
		$this->links->expects($this->once())->method('deleteByObjectAndCard')->with('obj-1', 40);
		$this->store->expects($this->once())->method('deleteCard')->with(40);
		$deleted = [];
		$this->store->method('deleteLabel')->willReturnCallback(
			static function (int $label) use (&$deleted): void {
				$deleted[] = $label;
			}
		);
		$this->store->expects($this->once())->method('deleteUnused')->with(20, null);

		$outcome = $this->cards()->revert(
			['object' => 'obj-1', 'card' => 40, 'createdLabels' => [31, 33], 'stack' => 20, 'createdStack' => true, 'board' => 10, 'createdBoard' => 'yes']
		);

		$this->assertSame('removed', $outcome);
		$this->assertSame([31, 33], $deleted);
	}

	public function testRevertReportsAFailure(): void {
		$this->links->method('deleteByObjectAndCard')->willThrowException(new \RuntimeException('db'));
		$this->store->expects($this->never())->method('deleteCard');

		$this->assertSame('failed', $this->cards()->revert(['object' => 'obj-1', 'card' => 40]));
	}
}//end class
