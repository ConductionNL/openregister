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
 * @spec openspec/changes/example-set-deck-cards/specs/example-sets/spec.md#requirement-a-fragment-may-put-deck-cards-on-its-objects
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
 * @spec openspec/changes/example-set-deck-cards/specs/example-sets/spec.md#requirement-a-fragment-may-put-deck-cards-on-its-objects
 */
class ExampleSetDeckCards extends ExampleSetEngineRows {
	/**
	 * The card fields a fragment may set.
	 */
	public const FIELDS = ['object', 'board', 'list', 'title', 'description', 'due', 'done', 'owner', 'assignees', 'labels', 'shareWith', 'color'];

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
	 * @spec openspec/changes/example-set-deck-cards/specs/example-sets/spec.md#requirement-a-fragment-may-put-deck-cards-on-its-objects
	 */
	public function part(): string {
		return 'deckCards';
	}//end part()

	/**
	 * The word for one card, in messages.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/example-set-deck-cards/specs/example-sets/spec.md#requirement-a-fragment-may-put-deck-cards-on-its-objects
	 */
	public function noun(): string {
		return 'deck card';
	}//end noun()

	/**
	 * Why a filled card cannot be written, or ''.
	 *
	 * @param array<string, mixed> $data  The filled card fields.
	 * @param array<int, string>   $users The account ids the set creates or adopts.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/example-set-deck-cards/specs/example-sets/spec.md#requirement-a-fragment-may-put-deck-cards-on-its-objects
	 */
	public function refusal(array $data, array $users): string {
		$unknown = array_diff(array_keys($data), self::FIELDS);
		if ($unknown !== []) {
			return 'field "'.implode('", "', $unknown).'" is not a deck card field a set may write';
		}

		$required = ['object' => 'the object it is on', 'board' => 'a board', 'list' => 'a list', 'title' => 'a title', 'owner' => 'an owner'];
		foreach ($required as $field => $what) {
			if (is_string($data[$field] ?? null) === false || trim($data[$field]) === '') {
				return 'a deck card needs '.$what.' ('.$field.')';
			}
		}

		$people = array_merge([(string) $data['owner']], array_map('strval', (array) ($data['assignees'] ?? [])));
		foreach ($people as $uid) {
			if (in_array($uid, $users, true) === false) {
				return '"'.$uid.'" is not an account the set creates or adopts';
			}
		}

		return $this->datesRefusal(data: $data);
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
	 * @spec openspec/changes/example-set-deck-cards/specs/example-sets/spec.md#requirement-a-fragment-may-put-deck-cards-on-its-objects
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

		try {
			$entry = $this->writeCard(store: $store, data: $data);
			$link  = $this->link(app: $app, object: $object, entry: $entry, data: $data);
		} catch (Throwable $e) {
			return ['error' => $e->getMessage(), 'entry' => null];
		}

		return ['error' => '', 'entry' => [...$entry, 'object' => (string) $data['object'], 'link' => $link]];
	}//end write()

	/**
	 * Whether a written card is no longer there.
	 *
	 * @param array<string, mixed> $entry A record entry from write().
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/example-set-deck-cards/specs/example-sets/spec.md#requirement-a-fragment-may-put-deck-cards-on-its-objects
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
	 * @spec openspec/changes/example-set-deck-cards/specs/example-sets/spec.md#requirement-a-fragment-may-put-deck-cards-on-its-objects
	 */
	public function revert(array $entry): string {
		try {
			$store = $this->store();
			$this->container->get(DeckLinkMapper::class)->deleteByObjectAndCard(objectUuid: (string) $entry['object'], cardId: (int) $entry['card']);
			$store->deleteCard(card: (int) $entry['card']);
			foreach ((array) ($entry['createdLabels'] ?? []) as $label) {
				$store->deleteLabel(label: (int) $label);
			}

			$store->deleteUnused(
				stack: $this->createdId(entry: $entry, flag: 'createdStack', id: 'stack'),
				board: $this->createdId(entry: $entry, flag: 'createdBoard', id: 'board')
			);
		} catch (Throwable) {
			return 'failed';
		}

		return 'removed';
	}//end revert()

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
		$owner = (string) $data['owner'];
		$board = $store->board(
			owner: $owner,
			title: (string) $data['board'],
			color: (string) ($data['color'] ?? self::DEFAULT_COLOR),
			groups: array_map('strval', (array) ($data['shareWith'] ?? []))
		);
		$stack = $store->stack(board: $board['id'], title: (string) $data['list']);

		$labels  = [];
		$created = [];
		foreach ((array) ($data['labels'] ?? []) as $label) {
			$label = $this->labelSpec(label: $label);
			$found = $store->label(board: $board['id'], title: $label['title'], color: $label['color']);
			if ($found['created'] === true) {
				$created[] = $found['id'];
			}

			$labels[] = ['id' => $found['id'], 'title' => $label['title'], 'color' => $label['color']];
		}

		$card = $store->insertCard(
			stack: $stack['id'],
			owner: $owner,
			title: (string) $data['title'],
			description: (string) ($data['description'] ?? ''),
			due: $this->dateOrNull(value: ($data['due'] ?? null)),
			done: $this->dateOrNull(value: ($data['done'] ?? null)),
			labels: array_column($labels, 'id'),
			assignees: array_map('strval', (array) ($data['assignees'] ?? []))
		);

		return [
			'board'         => $board['id'],
			'createdBoard'  => $board['created'],
			'stack'         => $stack['id'],
			'createdStack'  => $stack['created'],
			'card'          => $card,
			'createdLabels' => $created,
			'labels'        => $labels,
		];
	}//end writeCard()

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
		$users     = $this->container->get(IUserManager::class);
		$assignees = [];
		foreach (array_map('strval', (array) ($data['assignees'] ?? [])) as $uid) {
			$assignees[] = ['uid' => $uid, 'type' => 'user', 'displayName' => ($users->get($uid)?->getDisplayName() ?? $uid)];
		}

		$link = new DeckLink();
		$link->setObjectUuid((string) $data['object']);
		$link->setRegisterId((int) $object->getRegister());
		$link->setSchemaId((int) $object->getSchema());
		$link->setBoardId((int) $entry['board']);
		$link->setStackId((int) $entry['stack']);
		$link->setCardId((int) $entry['card']);
		$link->setCardTitle((string) $data['title']);
		$link->setDueDate($this->dateOrNull(value: ($data['due'] ?? null)));
		$link->setLabels((string) json_encode($entry['labels']));
		$link->setAssignees((string) json_encode($assignees));
		$link->setLinkedBy($app);
		$link->setLinkedAt(new DateTime());

		return (int) $this->container->get(DeckLinkMapper::class)->insert($link)->getId();
	}//end link()

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
	 * A due or done that is not a date.
	 *
	 * @param array<string, mixed> $data The filled card fields.
	 *
	 * @return string
	 */
	private function datesRefusal(array $data): string {
		foreach (['due', 'done'] as $field) {
			if (isset($data[$field]) === false) {
				continue;
			}

			try {
				new DateTime((string) $data[$field]);
			} catch (Throwable) {
				return $field.' "'.(string) $data[$field].'" is not a date';
			}
		}

		return '';
	}//end datesRefusal()

	/**
	 * The Deck store.
	 *
	 * @return ExampleSetDeckStore
	 */
	private function store(): ExampleSetDeckStore {
		return $this->container->get(ExampleSetDeckStore::class);
	}//end store()
}//end class
