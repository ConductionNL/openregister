<?php

/**
 * DeckObjectSourceProvider — serves the `nc-card` virtual schema's objects live
 * from the acting user's Nextcloud Deck boards (read-only).
 *
 * The authoritative record is the Deck card held by the Deck app; this provider
 * projects each card the acting user can read as a virtual ObjectEntity
 * (uuid = card id; object = {id, title, description, stackId, boardId, duedate})
 * and never writes back. Deck's own `BoardService`/`StackService` run in the
 * acting user's context and only return boards/cards the user may read, so the
 * projection is inherently user-scoped (denied == absent, no enumeration oracle)
 * — mirroring the scoping approach of the sibling object-source providers.
 *
 * Deck lives in ANOTHER app's namespace, so all access is gated behind
 * `class_exists` + a guarded container lookup (the defensive style of
 * {@see \OCA\OpenRegister\Service\DeckCardService} and the CalDAV provider): when
 * Deck is absent or its service classes cannot be resolved, the projection
 * degrades to an empty list with a logged warning rather than erroring.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCP\App\IAppManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read-only object-source provider backed by the Nextcloud Deck app.
 */
class DeckObjectSourceProvider implements ObjectSourceProvider
{

    /**
     * NC app id whose install-state gates this provider.
     *
     * @var string
     */
    private const REQUIRED_APP = 'deck';

    /**
     * Deck's per-user board service (resolved dynamically — Deck's namespace).
     *
     * @var string
     */
    private const BOARD_SERVICE = 'OCA\\Deck\\Service\\BoardService';

    /**
     * Deck's per-user stack service (resolved dynamically — Deck's namespace).
     *
     * @var string
     */
    private const STACK_SERVICE = 'OCA\\Deck\\Service\\StackService';

    /**
     * Constructor.
     *
     * @param IAppManager        $appManager  App availability checks.
     * @param IUserSession       $userSession The acting-user session.
     * @param ContainerInterface $container   Server container (lazy Deck-service lookup).
     * @param LoggerInterface    $logger      Logger for read failures.
     *
     * @return void
     */
    public function __construct(
        private readonly IAppManager $appManager,
        private readonly IUserSession $userSession,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * {@inheritDoc}
     *
     * @return string The provider id.
     *
     * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
     */
    public function getId(): string
    {
        return 'deck-source';
    }//end getId()

    /**
     * {@inheritDoc}
     *
     * Gated on the Deck app: when it is not installed the bound schema degrades
     * to an empty list rather than erroring.
     *
     * @return bool True when the Deck app is installed.
     *
     * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
     */
    public function isEnabled(): bool
    {
        try {
            return $this->appManager->isInstalled(self::REQUIRED_APP);
        } catch (Throwable $e) {
            return false;
        }
    }//end isEnabled()

    /**
     * {@inheritDoc}
     *
     * MUST return null when the card is absent OR the acting user may not read
     * it, so the two cases are indistinguishable (no enumeration oracle).
     *
     * @param Register             $register The register the schema belongs to.
     * @param Schema               $schema   The sourced schema.
     * @param string               $id       The Deck card id.
     * @param array<string, mixed> $config   The object-source config block (unused).
     *
     * @return ObjectEntity|null The virtual object, or null when absent/denied.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $config reserved for future scoping options.
     *
     * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
     */
    public function find(Register $register, Schema $schema, string $id, array $config=[]): ?ObjectEntity
    {
        foreach ($this->readCards() as $card) {
            if ((string) ($card['id'] ?? '') === $id) {
                return $this->toObjectEntity(register: $register, schema: $schema, card: $card);
            }
        }

        return null;
    }//end find()

    /**
     * {@inheritDoc}
     *
     * Honours `limit` and `offset`. The result is scoped by Deck's own
     * per-user services to the acting user's readable boards.
     *
     * @param Register             $register The register the schema belongs to.
     * @param Schema               $schema   The sourced schema.
     * @param array<string, mixed> $query    Query (limit/offset).
     * @param array<string, mixed> $config   The object-source config block (unused).
     *
     * @return ObjectEntity[] The matching virtual objects.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $config reserved for future scoping options.
     *
     * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
     */
    public function findAll(Register $register, Schema $schema, array $query=[], array $config=[]): array
    {
        $limit  = (int) ($query['limit'] ?? 200);
        $offset = (int) ($query['offset'] ?? 0);

        $cards = array_slice($this->readCards(), $offset, $limit);

        $objects = [];
        foreach ($cards as $card) {
            $objects[] = $this->toObjectEntity(register: $register, schema: $schema, card: $card);
        }

        return $objects;
    }//end findAll()

    /**
     * {@inheritDoc}
     *
     * @param Register             $register The register the schema belongs to.
     * @param Schema               $schema   The sourced schema.
     * @param array<string, mixed> $query    Query (limit/offset).
     * @param array<string, mixed> $config   The object-source config block (unused).
     *
     * @return int The number of matching virtual objects.
     *
     * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
     */
    public function count(Register $register, Schema $schema, array $query=[], array $config=[]): int
    {
        return count($this->findAll(register: $register, schema: $schema, query: $query, config: $config));
    }//end count()

    /**
     * Read the Deck cards visible to the acting user, failing closed to an empty
     * list.
     *
     * Walks the user's boards (Deck `BoardService::findAll()`) and, for each,
     * its stacks-with-cards (Deck `StackService::findAll()`); every access is
     * guarded so an absent Deck app or a per-card read failure degrades to an
     * empty/partial list rather than erroring.
     *
     * @return array<int, array<string, mixed>> The normalised card arrays.
     *
     * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
     */
    private function readCards(): array
    {
        if ($this->userSession->getUser() === null) {
            return [];
        }

        $boardService = $this->resolveService(class: self::BOARD_SERVICE);
        $stackService = $this->resolveService(class: self::STACK_SERVICE);
        if ($boardService === null || $stackService === null) {
            return [];
        }

        try {
            $boards = $boardService->findAll();
        } catch (Throwable $e) {
            $this->logger->warning('[ObjectSource:deck-source] could not list boards: '.$e->getMessage());
            return [];
        }

        $cards = [];
        foreach ($boards as $board) {
            $boardId = $this->intGetter(entity: $board, getter: 'getId');
            if ($boardId === null) {
                continue;
            }

            try {
                $stacks = $stackService->findAll($boardId);
            } catch (Throwable $e) {
                $this->logger->warning('[ObjectSource:deck-source] could not list stacks for board '.$boardId.': '.$e->getMessage());
                continue;
            }

            foreach ($stacks as $stack) {
                $cards = array_merge($cards, $this->cardsFromStack(stack: $stack, boardId: $boardId));
            }
        }

        return $cards;
    }//end readCards()

    /**
     * Extract the normalised card arrays from a single Deck stack.
     *
     * @param object $stack   The Deck stack entity (carries getCards()).
     * @param int    $boardId The parent board id (Deck cards expose only stackId).
     *
     * @return array<int, array<string, mixed>> The normalised card arrays.
     *
     * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
     */
    private function cardsFromStack(object $stack, int $boardId): array
    {
        try {
            $stackCards = $stack->getCards();
        } catch (Throwable $e) {
            return [];
        }

        if (is_array($stackCards) === false) {
            return [];
        }

        $result = [];
        foreach ($stackCards as $card) {
            if (is_object($card) === false) {
                continue;
            }

            $result[] = [
                'id'          => (string) ($this->intGetter(entity: $card, getter: 'getId') ?? ''),
                'title'       => $this->stringGetter(entity: $card, getter: 'getTitle'),
                'description' => $this->stringGetter(entity: $card, getter: 'getDescription'),
                'stackId'     => $this->intGetter(entity: $card, getter: 'getStackId'),
                'boardId'     => $boardId,
                'duedate'     => $this->dueDate(card: $card),
            ];
        }

        return $result;
    }//end cardsFromStack()

    /**
     * Resolve one of Deck's service classes from the container, or null when
     * Deck is not installed / its service cannot be resolved.
     *
     * @param string $class The fully-qualified Deck service class name.
     *
     * @return object|null The resolved service, or null.
     *
     * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
     */
    private function resolveService(string $class): ?object
    {
        if (class_exists($class) === false) {
            return null;
        }

        try {
            $service = $this->container->get($class);
            if (is_object($service) === true) {
                return $service;
            }
        } catch (Throwable $e) {
            $this->logger->warning('[ObjectSource:deck-source] could not resolve '.$class.': '.$e->getMessage());
        }

        return null;
    }//end resolveService()

    /**
     * Read an integer getter from a Deck entity (getters resolve via OCP Entity
     * magic, so method_exists is unreliable — each call is guarded).
     *
     * @param object $entity The Deck entity.
     * @param string $getter The getter method name.
     *
     * @return int|null The integer value, or null when unavailable.
     *
     * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
     */
    private function intGetter(object $entity, string $getter): ?int
    {
        try {
            $value = $entity->$getter();
            if ($value === null) {
                return null;
            }

            return (int) $value;
        } catch (Throwable $e) {
            return null;
        }
    }//end intGetter()

    /**
     * Read a string getter from a Deck entity, defaulting to '' on failure.
     *
     * @param object $entity The Deck entity.
     * @param string $getter The getter method name.
     *
     * @return string The string value, or ''.
     *
     * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
     */
    private function stringGetter(object $entity, string $getter): string
    {
        try {
            return (string) $entity->$getter();
        } catch (Throwable $e) {
            return '';
        }
    }//end stringGetter()

    /**
     * Read a Deck card's due date as an ISO-8601 string, or null.
     *
     * @param object $card The Deck card entity.
     *
     * @return string|null The ISO-8601 due date, or null.
     *
     * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
     */
    private function dueDate(object $card): ?string
    {
        try {
            $due = $card->getDuedate();
            if ($due instanceof \DateTimeInterface) {
                return $due->format(\DateTime::ATOM);
            }
        } catch (Throwable $e) {
            // Getter missing on this Deck version — leave null.
        }

        return null;
    }//end dueDate()

    /**
     * Map a normalised Deck card array onto a non-persisted virtual ObjectEntity.
     *
     * @param Register             $register The register.
     * @param Schema               $schema   The sourced schema.
     * @param array<string, mixed> $card     The normalised card array.
     *
     * @return ObjectEntity The virtual object (never saved).
     *
     * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
     */
    private function toObjectEntity(Register $register, Schema $schema, array $card): ObjectEntity
    {
        $id = (string) ($card['id'] ?? '');

        $entity = new ObjectEntity();
        $entity->setUuid($id);
        $entity->setRegister((string) $register->getId());
        $entity->setSchema((string) $schema->getId());
        $entity->setObject($card);

        return $entity;
    }//end toObjectEntity()
}//end class
