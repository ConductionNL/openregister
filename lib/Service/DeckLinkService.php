<?php

/**
 * DeckLinkService — Tier-2 deck integration service.
 *
 * Composes the {@see DeckLinkMapper} with Deck's internal `CardService`
 * and (Tier-1) `DeckCardService` to provide the picker + inline-create
 * UX surface area:
 *
 *   - linkCard(uuid, registerId, schemaId, cardId)         — link existing card
 *   - createAndLinkCard(uuid, ..., boardId, stackId, ...) — create + link new
 *   - unlinkCard(uuid, cardId)
 *   - getLinkedCards(uuid)                                — list, enriched
 *   - getAvailableBoards()
 *   - getStacksForBoard(boardId)
 *
 * Card create flow re-uses {@see DeckCardService::createDeckCard}
 * (kept private for Tier-1 link-or-create) via a thin wrapper to honour
 * the owner-arg fix (Phase A commit 3cdbfe71a) and the board-id
 * lookup (Phase F2 commit 72fceb720). When Deck is unavailable, list
 * still returns the persisted link row so historical references
 * survive even after Deck is uninstalled.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\DeckLink;
use OCA\OpenRegister\Db\DeckLinkMapper;
use OCP\App\IAppManager;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * DeckLinkService.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Composes mapper +
 *     Deck's CardService/BoardService/StackService + user mgmt. Each
 *     dependency is required.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Defensive try/catch
 *     blocks around every getter on Deck's Card/Label/Assignment
 *     entities (which use Entity::__call magic) inflate the cyclomatic
 *     score; splitting into helper classes would scatter the leaf-row
 *     contract.
 */
class DeckLinkService
{
    /**
     * Constructor.
     *
     * @param DeckLinkMapper  $deckLinkMapper Persistence for link rows.
     * @param IAppManager     $appManager     NC app manager.
     * @param IUserSession    $userSession    Active session.
     * @param IUserManager    $userManager    User lookup for assignee displayName.
     * @param LoggerInterface $logger         Logger.
     */
    public function __construct(
        private readonly DeckLinkMapper $deckLinkMapper,
        private readonly IAppManager $appManager,
        private readonly IUserSession $userSession,
        private readonly IUserManager $userManager,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Whether NC Deck is installed + enabled for the current user.
     *
     * @return bool
     */
    public function isDeckAvailable(): bool
    {
        return $this->appManager->isEnabledForUser('deck');
    }//end isDeckAvailable()

    /**
     * Link an existing Deck card to an OR object.
     *
     * The link row carries board_id/stack_id/title/dueDate/labels/assignees
     * harvested from the Deck card so subsequent reads don't need to hit
     * Deck. Idempotent: a duplicate link raises a 409 Exception (callers
     * should surface as HTTP 409 to the UI).
     *
     * @param string $objectUuid Parent OR object uuid.
     * @param int    $registerId OR register id.
     * @param int    $schemaId   OR schema id (Tier-2 column).
     * @param int    $cardId     Deck card id.
     *
     * @return DeckLink The persisted link row.
     *
     * @throws Exception On missing user, missing card (404), duplicate (409).
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Sequential guard
     *     clauses (no user, duplicate, Deck unavailable, find failure,
     *     null card) followed by best-effort entity extraction.
     * @SuppressWarnings(PHPMD.NPathComplexity)      Defensive try/catch
     *     blocks around every getter on Deck's Card entity (which uses
     *     OCP\AppFramework\Db\Entity::__call magic) — required to
     *     tolerate missing fields without burying the call site.
     *
     * @spec openspec/specs/generic-integrations/spec.md
     */
    public function linkCard(string $objectUuid, int $registerId, int $schemaId, int $cardId): DeckLink
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new Exception('No user logged in');
        }

        $existing = $this->deckLinkMapper->findByObjectAndCard($objectUuid, $cardId);
        if ($existing !== null) {
            throw new Exception('Card already linked to this object', 409);
        }

        $cardService = $this->resolveCardService();
        if ($cardService === null) {
            throw new Exception('Deck is not available', 503);
        }

        try {
            $card = $cardService->find($cardId);
        } catch (Throwable $e) {
            throw new Exception('Deck card not found', 404);
        }

        if ($card === null) {
            throw new Exception('Deck card not found', 404);
        }

        $boardId = $this->resolveBoardId(cardId: $cardId);
        $stackId = 0;
        $title   = '';
        try {
            $stackId = (int) $card->getStackId();
        } catch (Throwable $e) {
            // Leave default zero.
        }

        try {
            $title = (string) $card->getTitle();
        } catch (Throwable $e) {
            // Leave default empty.
        }

        $widened = $this->extractCardFields(card: $card);

        $link = new DeckLink();
        $link->setObjectUuid($objectUuid);
        $link->setRegisterId($registerId);
        $link->setSchemaId($schemaId);
        $link->setBoardId($boardId);
        $link->setStackId($stackId);
        $link->setCardId($cardId);
        $link->setCardTitle($title);
        $link->setDueDate($widened['dueDateRaw']);
        $labels = null;
        if ($widened['labels'] !== []) {
            $labels = json_encode($widened['labels']);
        }

        $assignees = null;
        if ($widened['assignees'] !== []) {
            $assignees = json_encode($widened['assignees']);
        }

        $link->setLabels($labels);
        $link->setAssignees($assignees);
        $link->setLinkedBy($user->getUID());
        $link->setLinkedAt(new DateTime());

        return $this->deckLinkMapper->insert($link);
    }//end linkCard()

    /**
     * Unlink a Deck card from an object.
     *
     * @param string $objectUuid Parent OR object uuid.
     * @param int    $cardId     Deck card id.
     *
     * @return void
     *
     * @throws Exception When no matching link is found (404).
     *
     * @spec openspec/specs/generic-integrations/spec.md
     */
    public function unlinkCard(string $objectUuid, int $cardId): void
    {
        $deleted = $this->deckLinkMapper->deleteByObjectAndCard($objectUuid, $cardId);
        if ($deleted === 0) {
            throw new Exception('Deck link not found', 404);
        }
    }//end unlinkCard()

    /**
     * Return the linked cards for an object, enriched from Deck where
     * available.
     *
     * Always succeeds: when Deck's `CardService` is missing or `find()`
     * throws for a stale link, the stored row is returned with empty
     * `labels`/`assignees` and `dueDate` falling back to the cached column.
     *
     * @param string $objectUuid Parent OR object uuid.
     *
     * @return array<int,array<string,mixed>>
     *
     * @spec openspec/specs/generic-integrations/spec.md
     */
    public function getLinkedCards(string $objectUuid): array
    {
        $links       = $this->deckLinkMapper->findByObjectUuid($objectUuid);
        $cardService = $this->resolveCardService();

        $results = [];
        foreach ($links as $link) {
            $row = $link->jsonSerialize();

            if ($cardService !== null) {
                try {
                    $card = $cardService->find($link->getCardId());
                    if (is_object($card) === true) {
                        $widened          = $this->extractCardFields(card: $card);
                        $row['dueDate']   = $widened['dueDate'] ?? $row['dueDate'];
                        $row['labels']    = $widened['labels'];
                        $row['assignees'] = $widened['assignees'];
                    }
                } catch (Throwable $e) {
                    // Stale link — keep cached row as-is.
                    $this->logger->debug('Stale deck link for card '.$link->getCardId().': '.$e->getMessage());
                }
            }

            $results[] = $row;
        }

        return $results;
    }//end getLinkedCards()

    /**
     * Create a new Deck card and link it to an object in one transaction.
     *
     * Uses Deck's CardService for the create (with the owner-arg fix
     * from commit 3cdbfe71a) then persists the link row carrying the
     * new card's id + cached title + due date.
     *
     * @param string      $objectUuid  Parent OR object uuid.
     * @param int         $registerId  OR register id.
     * @param int         $schemaId    OR schema id.
     * @param int         $boardId     Deck board id (resolved server-side
     *                                 via CardMapper::findBoardId after
     *                                 create — accepted for symmetry).
     * @param int         $stackId     Deck stack id (target column).
     * @param string      $title       Card title (required).
     * @param string|null $description Card description (optional).
     * @param string|null $duedate     ISO 8601 due date (optional).
     *
     * @return DeckLink The persisted link row.
     *
     * @throws Exception On missing user, Deck unavailable, or create failure.
     *
     * @spec openspec/specs/generic-integrations/spec.md
     */
    public function createAndLinkCard(
        string $objectUuid,
        int $registerId,
        int $schemaId,
        int $boardId,
        int $stackId,
        string $title,
        ?string $description,
        ?string $duedate
    ): DeckLink {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new Exception('No user logged in');
        }

        $cardService = $this->resolveCardService();
        if ($cardService === null) {
            throw new Exception('Deck is not available', 503);
        }

        $fullDescription = (string) $description;
        if ($fullDescription !== '') {
            $fullDescription .= "\n\n";
        }

        $fullDescription .= '[Object: '.$objectUuid.'](/apps/openregister/objects/'.$objectUuid.')';

        $userUid = $user->getUID();

        try {
            $card = $cardService->create($title, $stackId, 'plain', 0, $userUid);
            // Deck CardService::update signature:
            // update($id, $title, $stackId, $type, $owner, $description='', $order=0, $duedate=null, ...)
            // The owner argument must be a string user UID (Phase A fix).
            $cardService->update(
                $card->getId(),
                $title,
                $stackId,
                'plain',
                $userUid,
                $fullDescription,
                0,
                $duedate
            );
        } catch (Throwable $e) {
            $this->logger->warning('Failed to create Deck card: '.$e->getMessage());
            throw new Exception('Failed to create Deck card: '.$e->getMessage(), 500);
        }

        $cardId = (int) $card->getId();

        // Resolve board id authoritatively from the persisted card so we
        // don't trust the (caller-supplied) value in case the stack was
        // moved between boards.
        $resolvedBoard = $this->resolveBoardId(cardId: $cardId);
        if ($resolvedBoard === 0) {
            $resolvedBoard = $boardId;
        }

        $dueDateObj = null;
        if ($duedate !== null && $duedate !== '') {
            try {
                $dueDateObj = new DateTime($duedate);
            } catch (Throwable $e) {
                $dueDateObj = null;
            }
        }

        $link = new DeckLink();
        $link->setObjectUuid($objectUuid);
        $link->setRegisterId($registerId);
        $link->setSchemaId($schemaId);
        $link->setBoardId($resolvedBoard);
        $link->setStackId($stackId);
        $link->setCardId($cardId);
        $link->setCardTitle($title);
        $link->setDueDate($dueDateObj);
        $link->setLabels(null);
        $link->setAssignees(null);
        $link->setLinkedBy($userUid);
        $link->setLinkedAt(new DateTime());

        return $this->deckLinkMapper->insert($link);
    }//end createAndLinkCard()

    /**
     * Return the Deck boards visible to the current user.
     *
     * Each row is `{id, title}` — the minimum the picker needs.
     *
     * @return array<int,array{id:int,title:string}>
     *
     * @spec openspec/specs/generic-integrations/spec.md
     */
    public function getAvailableBoards(): array
    {
        $boardService = $this->resolveBoardService();
        if ($boardService === null) {
            return [];
        }

        $user = $this->userSession->getUser();
        if ($user === null) {
            return [];
        }

        try {
            $boards = $boardService->findAll();
        } catch (Throwable $e) {
            $this->logger->debug('Deck BoardService->findAll failed: '.$e->getMessage());
            return [];
        }

        if (is_array($boards) === false) {
            return [];
        }

        $result = [];
        foreach ($boards as $board) {
            $id    = 0;
            $title = '';
            try {
                $id = (int) $board->getId();
            } catch (Throwable $e) {
                continue;
            }

            try {
                $title = (string) $board->getTitle();
            } catch (Throwable $e) {
                $title = '';
            }

            if ($id === 0) {
                continue;
            }

            $result[] = ['id' => $id, 'title' => $title];
        }//end foreach

        return $result;
    }//end getAvailableBoards()

    /**
     * Return the stacks for a Deck board.
     *
     * @param int $boardId Deck board id.
     *
     * @return array<int,array{id:int,title:string,boardId:int}>
     *
     * @spec openspec/specs/generic-integrations/spec.md
     */
    public function getStacksForBoard(int $boardId): array
    {
        $stackService = $this->resolveStackService();
        if ($stackService === null) {
            return [];
        }

        try {
            $stacks = $stackService->findAll($boardId);
        } catch (Throwable $e) {
            $this->logger->debug('Deck StackService->findAll('.$boardId.') failed: '.$e->getMessage());
            return [];
        }

        if (is_array($stacks) === false) {
            return [];
        }

        $result = [];
        foreach ($stacks as $stack) {
            $id    = 0;
            $title = '';
            try {
                $id = (int) $stack->getId();
            } catch (Throwable $e) {
                continue;
            }

            try {
                $title = (string) $stack->getTitle();
            } catch (Throwable $e) {
                $title = '';
            }

            if ($id === 0) {
                continue;
            }

            $result[] = ['id' => $id, 'title' => $title, 'boardId' => $boardId];
        }//end foreach

        return $result;
    }//end getStacksForBoard()

    /**
     * Resolve Deck's CardService from the server container.
     *
     * @return object|null Returns null when Deck is unavailable.
     */
    private function resolveCardService(): ?object
    {
        if (class_exists('OCA\\Deck\\Service\\CardService') === false) {
            return null;
        }

        try {
            return \OC::$server->get('OCA\\Deck\\Service\\CardService');
        } catch (Throwable $e) {
            $this->logger->debug('Deck CardService not resolvable: '.$e->getMessage());
            return null;
        }
    }//end resolveCardService()

    /**
     * Resolve Deck's BoardService.
     *
     * @return object|null Returns null when Deck is unavailable.
     */
    private function resolveBoardService(): ?object
    {
        if (class_exists('OCA\\Deck\\Service\\BoardService') === false) {
            return null;
        }

        try {
            return \OC::$server->get('OCA\\Deck\\Service\\BoardService');
        } catch (Throwable $e) {
            $this->logger->debug('Deck BoardService not resolvable: '.$e->getMessage());
            return null;
        }
    }//end resolveBoardService()

    /**
     * Resolve Deck's StackService.
     *
     * @return object|null Returns null when Deck is unavailable.
     */
    private function resolveStackService(): ?object
    {
        if (class_exists('OCA\\Deck\\Service\\StackService') === false) {
            return null;
        }

        try {
            return \OC::$server->get('OCA\\Deck\\Service\\StackService');
        } catch (Throwable $e) {
            $this->logger->debug('Deck StackService not resolvable: '.$e->getMessage());
            return null;
        }
    }//end resolveStackService()

    /**
     * Resolve a card's board id via Deck's `CardMapper::findBoardId()`.
     *
     * Returns `0` when the lookup fails or the mapper is unavailable.
     *
     * @param int $cardId Deck card id.
     *
     * @return int
     */
    private function resolveBoardId(int $cardId): int
    {
        if (class_exists('OCA\\Deck\\Db\\CardMapper') === false) {
            return 0;
        }

        try {
            $cardMapper = \OC::$server->get('OCA\\Deck\\Db\\CardMapper');
            $boardId    = $cardMapper->findBoardId($cardId);
            if ($boardId !== null) {
                return (int) $boardId;
            }

            return 0;
        } catch (Throwable $e) {
            $this->logger->debug('Deck CardMapper::findBoardId('.$cardId.') failed: '.$e->getMessage());
            return 0;
        }
    }//end resolveBoardId()

    /**
     * Extract `dueDate`/`labels`/`assignees` from a Deck card.
     *
     * Returns:
     *   - `dueDate`     — ISO 8601 string or null
     *   - `dueDateRaw`  — DateTime|null (for persistence)
     *   - `labels`      — list of {id,title,color}
     *   - `assignees`   — list of {uid,type,displayName}
     *
     * Each call is defensive: missing entity methods are swallowed.
     *
     * @param object $card Deck Card entity.
     *
     * @return array{dueDate:?string,dueDateRaw:?DateTime,labels:array,assignees:array}
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function extractCardFields(object $card): array
    {
        $dueDate    = null;
        $dueDateRaw = null;
        try {
            $due = $card->getDuedate();
            if ($due instanceof \DateTimeInterface) {
                $dueDate    = $due->format(DateTime::ATOM);
                $dueDateRaw = new DateTime($dueDate);
            }
        } catch (Throwable $e) {
            // Leave default null.
        }

        $labels = [];
        try {
            $rawLabels = $card->getLabels();
            if (is_array($rawLabels) === true) {
                foreach ($rawLabels as $label) {
                    $labels[] = $this->mapLabel(label: $label);
                }
            }
        } catch (Throwable $e) {
            // Leave default empty.
        }

        $assignees = [];
        try {
            $rawAssignees = $card->getAssignedUsers();
            if (is_array($rawAssignees) === true) {
                foreach ($rawAssignees as $assignment) {
                    $assignees[] = $this->mapAssignee(assignment: $assignment);
                }
            }
        } catch (Throwable $e) {
            // Leave default empty.
        }

        return [
            'dueDate'    => $dueDate,
            'dueDateRaw' => $dueDateRaw,
            'labels'     => $labels,
            'assignees'  => $assignees,
        ];
    }//end extractCardFields()

    /**
     * Map a Deck Label entity to the leaf-row shape.
     *
     * @param object $label Deck Label.
     *
     * @return array{id:?int,title:string,color:string}
     */
    private function mapLabel(object $label): array
    {
        $id    = null;
        $title = '';
        $color = '';

        try {
            $rawId = $label->getId();
            if ($rawId !== null) {
                $id = (int) $rawId;
            }
        } catch (Throwable $e) {
            // Leave default null.
        }

        try {
            $title = (string) $label->getTitle();
        } catch (Throwable $e) {
            // Leave default empty.
        }

        try {
            $color = (string) $label->getColor();
        } catch (Throwable $e) {
            // Leave default empty.
        }

        return ['id' => $id, 'title' => $title, 'color' => $color];
    }//end mapLabel()

    /**
     * Map a Deck Assignment entity to the leaf-row shape.
     *
     * @param object $assignment Deck Assignment.
     *
     * @return array{uid:string,type:string,displayName:string}
     */
    private function mapAssignee(object $assignment): array
    {
        $uid  = '';
        $type = 'user';

        try {
            $uid = (string) $assignment->getParticipant();
        } catch (Throwable $e) {
            // Leave default empty.
        }

        try {
            $type = (string) $assignment->getTypeString();
        } catch (Throwable $e) {
            // Leave default value.
        }

        $displayName = $uid;
        if ($type === 'user' && $uid !== '') {
            try {
                $userObj = $this->userManager->get($uid);
                if ($userObj !== null) {
                    $displayName = (string) $userObj->getDisplayName();
                }
            } catch (Throwable $e) {
                // Leave displayName as uid.
            }
        }

        return [
            'uid'         => $uid,
            'type'        => $type,
            'displayName' => $displayName,
        ];
    }//end mapAssignee()
}//end class
