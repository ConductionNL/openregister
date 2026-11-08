<?php

/**
 * OpenRegister Example Set Deck Cards
 *
 * The Deck cards an example set puts on its objects: a fragment's `deckCards`
 * part names the object, the board and list the card goes on, its title,
 * owner, assignees and labels. The board, list and labels are found by title
 * or written when absent, the card is written and linked to the object as a
 * linked card is, and remove takes away the card and whatever the set wrote
 * for it that nobody else uses.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-deck-cards-on-its-objects
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use DateTime;
use OCA\OpenRegister\Db\DeckLink;
use OCA\OpenRegister\Db\DeckLinkMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Party\PartyService;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Checks, writes, proves and deletes the Deck cards of an example set.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-deck-cards-on-its-objects
 */
class ExampleSetDeckCards extends ExampleSetEngineRows {
	/**
	 * The card fields a fragment may set.
	 */
	public const FIELDS = ['object', 'board', 'list', 'title', 'description', 'due', 'done', 'owner', 'assignees', 'labels', 'shareWith', 'color'];

	/**
	 * The fields a card needs, with what each holds.
	 */
	private const REQUIRED = ['object' => 'the object it is on', 'board' => 'a board', 'list' => 'a list', 'title' => 'a title', 'owner' => 'an owner'];

	/**
	 * The colour of a board or label the set writes without one.
	 */
	private const DEFAULT_COLOR = '0082c9';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Hands out the Deck store, the link mapper and the object finder on first use.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * The fragment and record part cards live in.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-deck-cards-on-its-objects
	 */
	public function part(): string {
		return 'deckCards';
	}//end part()

	/**
	 * The word for one card, in messages.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-deck-cards-on-its-objects
	 */
	public function noun(): string {
		return 'deck card';
	}//end noun()

	/**
	 * The owner and the assignees a card names, the owner first.
	 *
	 * @param array<string, mixed> $data The filled card fields.
	 *
	 * @return array<int, string>
	 */
	private function people(array $data): array {
		return array_merge([(string) $data['owner']], array_map('strval', (array) ($data['assignees'] ?? [])));
	}//end people()

	/**
	 * Why a filled card cannot be written, or ''.
	 *
	 * @param array<string, mixed> $data  The filled card fields.
	 * @param array<int, string>   $users The account ids the set creates or adopts.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-deck-cards-on-its-objects
	 */
	public function refusal(array $data, array $users): string {
		$rules = new ExampleSetFieldRules();
		return $rules->firstRefusal(
			checks: [
				fn (): string => $rules->unknownRefusal(data: $data, fields: self::FIELDS, row: 'a deck card'),
				fn (): string => $rules->missingRefusal(data: $data, required: self::REQUIRED, row: 'a deck card'),
				fn (): string => $rules->strangerRefusal(people: $this->people(data: $data), users: $users),
				fn (): string => $rules->datesRefusal(data: $data, fields: ['due', 'done']),
			]
		);
	}//end refusal()

	/**
	 * Write the card on its board and list, and link it to the object.
	 *
	 * @param string               $app  The app that ships the fragment, recorded as the linker.
	 * @param string               $key  The card's key in the fragment (unused).
	 * @param array<string, mixed> $data The filled card fields.
	 *
	 * @return array{
	 *     error: string,
	 *     entry: array{
	 *         board: int, createdBoard: bool, stack: int, createdStack: bool, card: int,
	 *         createdLabels: array<int, int>, labels: array<int, array{id: int, title: string, color: string}>,
	 *         object: string, link: int
	 *     }|null
	 * } The record entry, or why the write failed.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-deck-cards-on-its-objects
	 */
	public function write(string $app, string $key, array $data): array {
		$store = $this->store();
		if ($store->available() === false) {
			return ['error' => 'Deck is not installed', 'entry' => null];
		}

		$object = $this->container->get(PartyService::class)->findUnscoped(uuid: (string) $data['object']);
		if ($object === null) {
			return ['error' => 'no object "'.(string) $data['object'].'" to put the card on', 'entry' => null];
		}

		return $this->writeAndLink(store: $store, app: $app, object: $object, data: $data);
	}//end write()

	/**
	 * Write the card and link it to the object.
	 *
	 * @param ExampleSetDeckStore  $store  The Deck store.
	 * @param string               $app    The app that ships the fragment, recorded as the linker.
	 * @param ObjectEntity         $object The object.
	 * @param array<string, mixed> $data   The filled card fields.
	 *
	 * @return array{error: string, entry: array<string, mixed>|null} The record entry, or why the write failed.
	 */
	private function writeAndLink(ExampleSetDeckStore $store, string $app, ObjectEntity $object, array $data): array {
		try {
			$entry = $this->writeCard(store: $store, data: $data);
			$link  = $this->link(app: $app, object: $object, entry: $entry, data: $data);
		} catch (Throwable $e) {
			return ['error' => $e->getMessage(), 'entry' => null];
		}

		return ['error' => '', 'entry' => [...$entry, 'object' => (string) $data['object'], 'link' => $link]];
	}//end writeAndLink()

	/**
	 * Whether a written card is no longer there.
	 *
	 * @param array<string, mixed> $entry A record entry from write().
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-deck-cards-on-its-objects
	 */
	public function lost(array $entry): bool {
		try {
			return $this->store()->cardExists(card: (int) $entry['card']) === false;
		} catch (Throwable) {
			return true;
		}
	}//end lost()

	/**
	 * Delete the card, its link, and the labels, list and board the set wrote for it when nobody else uses them.
	 *
	 * @param array<string, mixed> $entry A record entry from write().
	 *
	 * @return string `removed` (also when it was already gone) or `failed`.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-deck-cards-on-its-objects
	 */
	public function revert(array $entry): string {
		try {
			$this->deleteCard(store: $this->store(), entry: $entry);
		} catch (Throwable) {
			return 'failed';
		}

		return 'removed';
	}//end revert()

	/**
	 * Delete the card, its link and the labels the set wrote for it, then the list and board the set wrote when
	 * nobody else uses them.
	 *
	 * @param ExampleSetDeckStore  $store The Deck store.
	 * @param array<string, mixed> $entry A record entry from write().
	 *
	 * @return void
	 */
	private function deleteCard(ExampleSetDeckStore $store, array $entry): void {
		$this->container->get(DeckLinkMapper::class)->deleteByObjectAndCard(objectUuid: (string) $entry['object'], cardId: (int) $entry['card']);
		$store->deleteCard(card: (int) $entry['card']);
		foreach ((array) ($entry['createdLabels'] ?? []) as $label) {
			$store->deleteLabel(label: (int) $label);
		}

		$store->deleteUnused(
			stack: $this->createdId(entry: $entry, flag: 'createdStack', id: 'stack'),
			board: $this->createdId(entry: $entry, flag: 'createdBoard', id: 'board')
		);
	}//end deleteCard()

	/**
	 * Find or write the board, list and labels, then write the card.
	 *
	 * @param ExampleSetDeckStore  $store The Deck store.
	 * @param array<string, mixed> $data  The filled card fields.
	 *
	 * @return array{
	 *     board: int, createdBoard: bool, stack: int, createdStack: bool, card: int,
	 *     createdLabels: array<int, int>, labels: array<int, array{id: int, title: string, color: string}>
	 * } The board, list and card ids, which of them the set wrote, and the labels.
	 */
	private function writeCard(ExampleSetDeckStore $store, array $data): array {
		$place  = $this->place(store: $store, data: $data);
		$labels = $this->labels(store: $store, board: $place['board'], data: $data);
		$card   = $store->insertCard(stack: $place['stack'], card: $this->card(data: $data, labels: array_column($labels['labels'], 'id')));

		return [...$place, 'card' => $card, 'createdLabels' => $labels['created'], 'labels' => $labels['labels']];
	}//end writeCard()

	/**
	 * The card as the Deck store writes it.
	 *
	 * @param array<string, mixed> $data   The filled card fields.
	 * @param array<int, int>      $labels The label ids.
	 *
	 * @return array{
	 *     owner: string, title: string, description: string, due: ?DateTime, done: ?DateTime,
	 *     labels: array<int, int>, assignees: array<int, string>
	 * }
	 */
	private function card(array $data, array $labels): array {
		return [
			'owner'       => (string) $data['owner'],
			'title'       => (string) $data['title'],
			'description' => (string) ($data['description'] ?? ''),
			'due'         => $this->dateOrNull(value: ($data['due'] ?? null)),
			'done'        => $this->dateOrNull(value: ($data['done'] ?? null)),
			'labels'      => $labels,
			'assignees'   => array_map('strval', (array) ($data['assignees'] ?? [])),
		];
	}//end card()

	/**
	 * Find or write the board and the list the card goes on.
	 *
	 * @param ExampleSetDeckStore  $store The Deck store.
	 * @param array<string, mixed> $data  The filled card fields.
	 *
	 * @return array{board: int, createdBoard: bool, stack: int, createdStack: bool}
	 */
	private function place(ExampleSetDeckStore $store, array $data): array {
		$board = $store->board(
			owner: (string) $data['owner'],
			title: (string) $data['board'],
			color: (string) ($data['color'] ?? self::DEFAULT_COLOR),
			groups: array_map('strval', (array) ($data['shareWith'] ?? []))
		);
		$stack = $store->stack(board: $board['id'], title: (string) $data['list']);

		return ['board' => $board['id'], 'createdBoard' => $board['created'], 'stack' => $stack['id'], 'createdStack' => $stack['created']];
	}//end place()

	/**
	 * Find or write the card's labels on the board.
	 *
	 * @param ExampleSetDeckStore  $store The Deck store.
	 * @param int                  $board The board id.
	 * @param array<string, mixed> $data  The filled card fields.
	 *
	 * @return array{labels: array<int, array{id: int, title: string, color: string}>, created: array<int, int>}
	 *         The labels, and the ids of those the set wrote.
	 */
	private function labels(ExampleSetDeckStore $store, int $board, array $data): array {
		$labels  = [];
		$created = [];
		foreach ((array) ($data['labels'] ?? []) as $label) {
			$label = $this->labelSpec(label: $label);
			$found = $store->label(board: $board, title: $label['title'], color: $label['color']);
			if ($found['created'] === true) {
				$created[] = $found['id'];
			}

			$labels[] = ['id' => $found['id'], 'title' => $label['title'], 'color' => $label['color']];
		}

		return ['labels' => $labels, 'created' => $created];
	}//end labels()

	/**
	 * Link the card to the object as a linked card, with its labels and assignees cached on the link.
	 *
	 * @param string               $app    The app that ships the fragment, recorded as the linker.
	 * @param ObjectEntity         $object The object.
	 * @param array<string, mixed> $entry  The written card (board, stack, card, labels).
	 * @param array<string, mixed> $data   The filled card fields.
	 *
	 * @return int The link id.
	 */
	private function link(string $app, ObjectEntity $object, array $entry, array $data): int {
		$link = new DeckLink();
		$link->setObjectUuid((string) $data['object']);
		$link->setRegisterId((int) $object->getRegister());
		$link->setSchemaId((int) $object->getSchema());
		$this->setCard(link: $link, entry: $entry, data: $data);
		$link->setLinkedBy($app);
		$link->setLinkedAt(new DateTime());

		return (int) $this->container->get(DeckLinkMapper::class)->insert($link)->getId();
	}//end link()

	/**
	 * Put the card, its title, due date, labels and assignees on the link.
	 *
	 * @param DeckLink             $link  The link.
	 * @param array<string, mixed> $entry The written card (board, stack, card, labels).
	 * @param array<string, mixed> $data  The filled card fields.
	 *
	 * @return void
	 */
	private function setCard(DeckLink $link, array $entry, array $data): void {
		$link->setBoardId((int) $entry['board']);
		$link->setStackId((int) $entry['stack']);
		$link->setCardId((int) $entry['card']);
		$link->setCardTitle((string) $data['title']);
		$link->setDueDate($this->dateOrNull(value: ($data['due'] ?? null)));
		$link->setLabels((string) json_encode($entry['labels']));
		$link->setAssignees((string) json_encode($this->assignees(data: $data)));
	}//end setCard()

	/**
	 * The card's assignees as the link caches them, with their display names.
	 *
	 * @param array<string, mixed> $data The filled card fields.
	 *
	 * @return array<int, array{uid: string, type: string, displayName: string}>
	 */
	private function assignees(array $data): array {
		$users     = $this->container->get(IUserManager::class);
		$assignees = [];
		foreach (array_map('strval', (array) ($data['assignees'] ?? [])) as $uid) {
			$assignees[] = ['uid' => $uid, 'type' => 'user', 'displayName' => ($users->get($uid)?->getDisplayName() ?? $uid)];
		}

		return $assignees;
	}//end assignees()

	/**
	 * A label row as its title and colour.
	 *
	 * @param mixed $label A title, or `{title, color?}`.
	 *
	 * @return array{title: string, color: string}
	 */
	private function labelSpec(mixed $label): array {
		if (is_array($label) === false) {
			return ['title' => (string) $label, 'color' => self::DEFAULT_COLOR];
		}

		return ['title' => (string) ($label['title'] ?? ''), 'color' => (string) ($label['color'] ?? self::DEFAULT_COLOR)];
	}//end labelSpec()

	/**
	 * A date from a filled field, or null when the field is empty.
	 *
	 * @param mixed $value The field.
	 *
	 * @return DateTime|null
	 */
	private function dateOrNull(mixed $value): ?DateTime {
		if ($value === null || $value === '') {
			return null;
		}

		return new DateTime((string) $value);
	}//end dateOrNull()

	/**
	 * The id the entry recorded when the set wrote that row, else null.
	 *
	 * @param array<string, mixed> $entry The record entry.
	 * @param string               $flag  The entry's created flag.
	 * @param string               $id    The entry's id field.
	 *
	 * @return int|null
	 */
	private function createdId(array $entry, string $flag, string $id): ?int {
		if (($entry[$flag] ?? false) !== true) {
			return null;
		}

		return (int) $entry[$id];
	}//end createdId()

	/**
	 * The Deck store.
	 *
	 * @return ExampleSetDeckStore
	 */
	private function store(): ExampleSetDeckStore {
		return $this->container->get(ExampleSetDeckStore::class);
	}//end store()
}//end class
