<?php

/**
 * Test doubles for the optional Nextcloud Deck app (not installed in the
 * unit-test environment). The four OCA\Deck class names only have to exist
 * for the class_exists() probes in DeckLinkService / DeckCardService; they are
 * declared empty, exactly as DeckServicesGoldenTest declares them, so either
 * file may load first. The service doubles the container hands out live in
 * this test namespace and expose only methods the real Deck classes have.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Fixtures
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\Fixtures;

foreach (['OCA\\Deck\\Service\\CardService', 'OCA\\Deck\\Db\\CardMapper', 'OCA\\Deck\\Service\\BoardService', 'OCA\\Deck\\Service\\StackService'] as $deckStubClass) {
	if (class_exists($deckStubClass) === false) {
		$pos = strrpos($deckStubClass, '\\');
		eval('namespace ' . substr($deckStubClass, 0, $pos) . '; class ' . substr($deckStubClass, $pos + 1) . ' {}');
	}
}

/**
 * Double of \OCA\Deck\Service\CardService; behaviour configured per test.
 */
class DeckCardServiceDouble {
	/** @var array<int, mixed> card id => card object or Throwable */
	public array $cards = [];
	public mixed $createResult = null;
	public ?\Throwable $updateFailure = null;
	public array $calls = [];

	public function find(int $cardId): mixed {
		$this->calls[] = ['find', $cardId];
		$card = $this->cards[$cardId] ?? null;
		if ($card instanceof \Throwable) {
			throw $card;
		}
		return $card;
	}

	public function create(string $title, int $stackId, string $type, int $order, string $owner): mixed {
		$this->calls[] = ['create', $title, $stackId, $type, $order, $owner];
		if ($this->createResult instanceof \Throwable) {
			throw $this->createResult;
		}
		return $this->createResult;
	}

	public function update(int $id, string $title, int $stackId, string $type, string $owner, string $description = '', int $order = 0, ?string $duedate = null): mixed {
		$this->calls[] = ['update', $id, $title, $stackId, $type, $owner, $description, $order, $duedate];
		if ($this->updateFailure !== null) {
			throw $this->updateFailure;
		}
		return null;
	}
}

/**
 * Double of \OCA\Deck\Db\CardMapper.
 */
class DeckCardMapperDouble {
	public mixed $boardId = null;

	public function findBoardId(int $cardId): mixed {
		if ($this->boardId instanceof \Throwable) {
			throw $this->boardId;
		}
		return $this->boardId;
	}
}

/**
 * Double of \OCA\Deck\Service\BoardService and StackService (both expose find() and findAll()).
 */
class DeckListingServiceDouble {
	public mixed $result = [];
	public array $calls = [];
	public ?\Throwable $findFailure = null;
	public array $found = [];

	public function find(int $id): mixed {
		$this->found[] = $id;
		if ($this->findFailure !== null) {
			throw $this->findFailure;
		}
		return null;
	}

	public function findAll(...$args): mixed {
		$this->calls[] = $args;
		if ($this->result instanceof \Throwable) {
			throw $this->result;
		}
		return $this->result;
	}
}

/**
 * A Deck entity (card, board, stack, label, assignment) whose getters return
 * configured values, or throw when the configured value is a Throwable.
 */
class DeckEntityDouble {
	public function __construct(private array $fields) {
	}

	public function __call(string $name, array $args): mixed {
		$field = lcfirst(substr($name, 3));
		if (array_key_exists($field, $this->fields) === false) {
			throw new \BadMethodCallException($name);
		}
		$value = $this->fields[$field];
		if ($value instanceof \Throwable) {
			throw $value;
		}
		return $value;
	}
}
