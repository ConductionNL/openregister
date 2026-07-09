<?php

/**
 * DeckCardService
 *
 * Service that wraps Nextcloud Deck card operations for linking cards to OpenRegister objects.
 * Uses the Deck app's internal PHP service classes when available.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\DeckLink;
use OCA\OpenRegister\Db\DeckLinkMapper;
use OCP\App\IAppManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * DeckCardService manages Deck card-to-object links.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Service composes DeckLinkMapper,
 *   IAppManager, IUserSession, LoggerInterface, and dynamic Deck service classes; the set
 *   cannot be reduced as each dependency serves a distinct orchestration concern.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Deck's Card entity resolves
 *   getDuedate/getLabels/getAssignedUsers via OCP Entity::__call magic — method_exists()
 *   returns false for all three, so each must be individually wrapped in try/catch.
 *   There is no alternative API that avoids this pattern; complexity is intrinsic
 *   to defensive interop with the Deck app's internal entity layer.
 */
class DeckCardService
{

    /**
     * Deck link mapper.
     *
     * @var DeckLinkMapper
     */
    private readonly DeckLinkMapper $deckLinkMapper;

    /**
     * App manager.
     *
     * @var IAppManager
     */
    private readonly IAppManager $appManager;

    /**
     * User session.
     *
     * @var IUserSession
     */
    private readonly IUserSession $userSession;

    /**
     * Logger.
     *
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;

    /**
     * Constructor.
     *
     * @param DeckLinkMapper  $deckLinkMapper Deck link mapper
     * @param IAppManager     $appManager     App manager
     * @param IUserSession    $userSession    User session
     * @param LoggerInterface $logger         Logger
     *
     * @return void
     */
    public function __construct(
        DeckLinkMapper $deckLinkMapper,
        IAppManager $appManager,
        IUserSession $userSession,
        LoggerInterface $logger
    ) {
        $this->deckLinkMapper = $deckLinkMapper;
        $this->appManager     = $appManager;
        $this->userSession    = $userSession;
        $this->logger         = $logger;
    }//end __construct()

    /**
     * Check if the Nextcloud Deck app is installed and enabled.
     *
     * @return bool True if Deck is available.
     */
    public function isDeckAvailable(): bool
    {
        return $this->appManager->isEnabledForUser('deck');
    }//end isDeckAvailable()

    /**
     * Get all deck links for an object.
     *
     * Each row is the canonical {@see DeckLink::jsonSerialize()} payload
     * enriched with `dueDate`, `labels`, and `assignees` resolved from
     * the underlying Deck card via Deck's CardService:
     *   * `dueDate`   — ISO 8601 datetime string ("2026-06-01T12:00:00+00:00")
     *                   or `null` when the card has no due date.
     *   * `labels`    — array of `{id, title, color}` (color preserved as
     *                   Deck stores it — hex without the leading `#`).
     *   * `assignees` — array of `{uid, type, displayName}`. The Deck
     *                   Assignment entity exposes `getParticipant()`
     *                   (a uid for type=USER) and `getType()` (user /
     *                   group / circle). The provider surfaces type so
     *                   the UI can render a distinct chip when an
     *                   assignment is to a group/circle.
     *
     * Enrichment is best-effort: if Deck's CardService isn't available
     * (Deck disabled at runtime) or `find()` throws for an individual
     * card (deleted from Deck but link row stale), the widened fields
     * fall back to `null` / `[]` and the link is still surfaced.
     *
     * Idempotent: re-running the enrichment doesn't double-write — the
     * widened keys are computed fresh each call.
     *
     * @param string $objectUuid The object UUID.
     *
     * @return array{results: array, total: int}
     *
     * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-8
     */
    public function getCardsForObject(string $objectUuid): array
    {
        $links = $this->deckLinkMapper->findByObjectUuid($objectUuid);

        // Resolve Deck's CardService once; null when unavailable so we
        // don't pay the lookup cost per link.
        $cardService = $this->resolveDeckCardService();

        $results = array_map(
            function (DeckLink $link) use ($cardService): array {
                $row     = $link->jsonSerialize();
                $widened = $this->extractCardFields(cardService: $cardService, cardId: $link->getCardId());
                return $row + $widened;
            },
            $links
        );

        return ['results' => $results, 'total' => count($results)];
    }//end getCardsForObject()

    /**
     * Resolve Deck's CardService from the server container.
     *
     * @return object|null The CardService, or `null` when Deck is not
     *                     installed / its service class can't be
     *                     resolved.
     */
    private function resolveDeckCardService(): ?object
    {
        if (class_exists('OCA\\Deck\\Service\\CardService') === false) {
            return null;
        }

        try {
            return \OC::$server->get('OCA\\Deck\\Service\\CardService');
        } catch (\Throwable $e) {
            $this->logger->debug('Deck CardService not resolvable: '.$e->getMessage());
            return null;
        }
    }//end resolveDeckCardService()

    /**
     * Extract the widened `dueDate`, `labels`, `assignees` payload from
     * a Deck card by its id.
     *
     * Always returns the full key set, even on lookup failure, so the
     * caller can safely union with the link row.
     *
     * @param object|null $cardService Deck CardService (may be null).
     * @param int|null    $cardId      Deck card id from the link row.
     *
     * @return array{dueDate: ?string, labels: array, assignees: array}
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Defensive null/type guards for service
     *   and card availability are mandatory; Deck's entity magic prevents compile-time
     *   checks, requiring runtime branches that cannot be removed without losing safety.
     * @SuppressWarnings(PHPMD.NPathComplexity)      NPath inflation is caused by PHPMD 2.x
     *   counting logical sub-expressions in guard conditions independently; the method
     *   body is already fully decomposed into extractDueDate/extractLabels/extractAssignees.
     */
    private function extractCardFields(?object $cardService, ?int $cardId): array
    {
        $defaults = ['dueDate' => null, 'labels' => [], 'assignees' => []];

        if ($cardService === null || $cardId === null || $cardId === 0) {
            return $defaults;
        }

        try {
            $card = $cardService->find($cardId);
        } catch (\Throwable $e) {
            // Card deleted from Deck (link row is stale) — degrade gracefully.
            return $defaults;
        }

        if ($card === null || is_object($card) === false) {
            return $defaults;
        }

        // NB: Deck's Card entity (and its CardDetails wrapper) resolves
        // getters via OCP\AppFramework\Db\Entity::__call magic — so PHP's
        // `method_exists()` returns false for `getDuedate`, `getLabels`,
        // and `getAssignedUsers` even though the calls work. Each call
        // is therefore wrapped in its own try/catch.
        return [
            'dueDate'   => $this->extractDueDate(card: $card),
            'labels'    => $this->extractLabels(card: $card),
            'assignees' => $this->extractAssignees(card: $card),
        ];
    }//end extractCardFields()

    /**
     * Extract the due date string from a Deck card entity.
     *
     * Returns null when the card has no due date or `getDuedate()` is unavailable
     * (resolved via OCP Entity magic — method_exists returns false).
     *
     * @param object $card Deck Card entity.
     *
     * @return string|null ISO 8601 datetime, or null.
     */
    private function extractDueDate(object $card): ?string
    {
        try {
            $due = $card->getDuedate();
            if ($due instanceof \DateTimeInterface) {
                return $due->format(\DateTime::ATOM);
            }
        } catch (\Throwable $e) {
            // GetDuedate missing on this Deck version — leave null.
        }

        return null;
    }//end extractDueDate()

    /**
     * Extract the labels array from a Deck card entity.
     *
     * Returns an empty array when `getLabels()` is unavailable or returns an
     * unexpected shape.
     *
     * @param object $card Deck Card entity.
     *
     * @return array
     */
    private function extractLabels(object $card): array
    {
        try {
            $rawLabels = $card->getLabels();
            if (is_array($rawLabels) === true) {
                $labels = [];
                foreach ($rawLabels as $label) {
                    $labels[] = $this->mapLabel(label: $label);
                }

                return $labels;
            }
        } catch (\Throwable $e) {
            // GetLabels missing or returned unexpected shape — leave empty.
        }

        return [];
    }//end extractLabels()

    /**
     * Extract the assignees array from a Deck card entity.
     *
     * Returns an empty array when `getAssignedUsers()` is unavailable.
     *
     * @param object $card Deck Card entity.
     *
     * @return array
     */
    private function extractAssignees(object $card): array
    {
        try {
            $rawAssignees = $card->getAssignedUsers();
            if (is_array($rawAssignees) === true) {
                $assignees = [];
                foreach ($rawAssignees as $assignment) {
                    $assignees[] = $this->mapAssignee(assignment: $assignment);
                }

                return $assignees;
            }
        } catch (\Throwable $e) {
            // GetAssignedUsers missing — leave empty.
        }

        return [];
    }//end extractAssignees()

    /**
     * Map a Deck Label entity to the leaf-row label shape.
     *
     * @param object $label Deck Label.
     *
     * @return array{id: ?int, title: string, color: string}
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
        } catch (\Throwable $e) {
            // Leave null.
        }

        try {
            $title = (string) $label->getTitle();
        } catch (\Throwable $e) {
            // Leave empty.
        }

        try {
            $color = (string) $label->getColor();
        } catch (\Throwable $e) {
            // Leave empty.
        }

        return ['id' => $id, 'title' => $title, 'color' => $color];
    }//end mapLabel()

    /**
     * Map a Deck Assignment entity to the leaf-row assignee shape.
     *
     * `participant` carries the user uid (TYPE_USER), group name (TYPE_GROUP),
     * or circle id (TYPE_CIRCLE); we surface it as `uid` plus a typed
     * label so the UI can render a distinct chip for non-user assignments.
     *
     * @param object $assignment Deck Assignment.
     *
     * @return array{uid: string, type: string, displayName: string}
     */
    private function mapAssignee(object $assignment): array
    {
        $uid  = '';
        $type = 'user';

        try {
            $uid = (string) $assignment->getParticipant();
        } catch (\Throwable $e) {
            // Leave empty.
        }

        try {
            $type = (string) $assignment->getTypeString();
        } catch (\Throwable $e) {
            // Leave default.
        }

        // Resolve a displayName for user assignments via the user manager;
        // group/circle assignments echo the participant id as displayName
        // until we wire up those backends.
        $displayName = $uid;
        if ($type === 'user' && $uid !== '') {
            try {
                $userMgr = \OC::$server->get('OCP\\IUserManager');
                $userObj = $userMgr->get($uid);
                if ($userObj !== null) {
                    $displayName = (string) $userObj->getDisplayName();
                }
            } catch (\Throwable $e) {
                // Leave displayName as uid.
            }
        }

        return [
            'uid'         => $uid,
            'type'        => $type,
            'displayName' => $displayName,
        ];
    }//end mapAssignee()

    /**
     * Create a new Deck card linked to an object, or link an existing card.
     *
     * @param string $objectUuid The object UUID.
     * @param int    $registerId The register ID.
     * @param array  $data       Card data: boardId, stackId, title, description, or cardId for existing.
     *
     * @return DeckLink The created link.
     *
     * @throws Exception If parameters are missing or Deck operations fail.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Two mutually exclusive dispatch paths
     *   (link existing card vs. create new card) each require their own guard and error
     *   branches; the logic cannot be split further without hiding the business rule.
     *
     * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-8
     */
    public function linkOrCreateCard(string $objectUuid, int $registerId, array $data): DeckLink
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new Exception('No user logged in');
        }

        $cardId    = null;
        $cardTitle = null;
        $boardId   = 0;
        $stackId   = 0;

        $hasCardId    = (empty($data['cardId']) === false);
        $hasBoardData = (empty($data['boardId']) === false && empty($data['stackId']) === false);

        if ($hasCardId === false && $hasBoardData === false) {
            throw new Exception('Either cardId or boardId+stackId is required');
        }

        if ($hasCardId === true) {
            // Link existing card.
            $cardId   = (int) $data['cardId'];
            $cardInfo = $this->getDeckCardInfo(cardId: $cardId);
            if ($cardInfo === null) {
                throw new Exception('Deck card not found', 404);
            }

            $cardTitle = $cardInfo['title'] ?? 'Unknown';
            $boardId   = $cardInfo['boardId'] ?? 0;
            $stackId   = $cardInfo['stackId'] ?? 0;

            // Check for duplicate.
            $existing = $this->deckLinkMapper->findByObjectAndCard($objectUuid, $cardId);
            if ($existing !== null) {
                throw new Exception('Card already linked to this object', 409);
            }
        }

        if ($hasCardId === false && $hasBoardData === true) {
            // Create new card.
            $boardId   = (int) $data['boardId'];
            $stackId   = (int) $data['stackId'];
            $cardTitle = $data['title'] ?? 'Untitled';

            $cardId = $this->createDeckCard(
                boardId: $boardId,
                    stackId: $stackId,
                    title: $cardTitle,
                description: $data['description'] ?? '',
                    objectUuid: $objectUuid
            );
            if ($cardId === null) {
                throw new Exception('Failed to create Deck card');
            }
        }

        $link = new DeckLink();
        $link->setObjectUuid($objectUuid);
        $link->setRegisterId($registerId);
        $link->setBoardId($boardId);
        $link->setStackId($stackId);
        $link->setCardId($cardId);
        $link->setCardTitle($cardTitle);
        $link->setLinkedBy($user->getUID());
        $link->setLinkedAt(new DateTime());

        return $this->deckLinkMapper->insert($link);
    }//end linkOrCreateCard()

    /**
     * Remove a deck link.
     *
     * @param int $linkId The link ID.
     *
     * @return void
     *
     * @throws Exception If link not found.
     *
     * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-8
     */
    public function unlinkCard(int $linkId): void
    {
        try {
            $link = $this->deckLinkMapper->find($linkId);
            $this->deckLinkMapper->delete($link);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            throw new Exception('Deck link not found', 404);
        }
    }//end unlinkCard()

    /**
     * Find all objects linked to cards on a board.
     *
     * @param int $boardId The Deck board ID.
     *
     * @return array Array of deck links.
     *
     * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-8
     */
    public function getObjectsForBoard(int $boardId): array
    {
        // IDOR guard: only return links for a board the caller can access in
        // Deck. Without this any authenticated user could enumerate board IDs
        // and read OpenRegister object links for boards they have no Deck
        // access to (the deckLinkMapper table bypasses Deck's own ACL).
        if ($this->userCanAccessBoard(boardId: $boardId) === false) {
            return [];
        }

        $links = $this->deckLinkMapper->findByBoardId($boardId);

        return array_map(
            static function (DeckLink $link): array {
                return $link->jsonSerialize();
            },
            $links
        );
    }//end getObjectsForBoard()

    /**
     * Whether the current session user may read the given Deck board.
     *
     * Delegates to Deck's own BoardService::find(), which enforces the board
     * ACL and throws when the session user lacks read access. Fail-closed:
     * returns false when Deck is unavailable or access is denied, so no link
     * metadata leaks for a board the caller cannot see.
     *
     * @param int $boardId The Deck board ID.
     *
     * @return bool True when the caller may read the board.
     */
    protected function userCanAccessBoard(int $boardId): bool
    {
        if (class_exists('OCA\\Deck\\Service\\BoardService') === false) {
            return false;
        }

        try {
            $boardService = \OC::$server->get('OCA\\Deck\\Service\\BoardService');
            // Find() throws (NoPermissionException) when the session user
            // lacks read access to the board.
            $boardService->find($boardId);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }//end userCanAccessBoard()

    /**
     * Delete all deck links for an object (cleanup).
     *
     * @param string $objectUuid The object UUID.
     *
     * @return int Number of deleted links.
     *
     * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-8
     */
    public function deleteLinksForObject(string $objectUuid): int
    {
        return $this->deckLinkMapper->deleteByObjectUuid($objectUuid);
    }//end deleteLinksForObject()

    /**
     * Get Deck card info by card ID using Deck's services.
     *
     * Resolves the board ID via `CardMapper::findBoardId()` because the
     * Deck `Card` entity exposes only `getStackId()` — there is no
     * `getBoardId()` method (board is reachable via the stack). Mirrors the
     * pattern used inside Deck's own `CardService::update()` (line 331 in
     * deck/lib/Service/CardService.php).
     *
     * @param int $cardId The card ID.
     *
     * @return array|null Card info or null.
     */
    private function getDeckCardInfo(int $cardId): ?array
    {
        try {
            // Try using Deck's CardService if available.
            if (class_exists('OCA\Deck\Service\CardService') === true) {
                $cardService = \OC::$server->get('OCA\Deck\Service\CardService');
                $card        = $cardService->find($cardId);

                // Board ID is not a Card property — look it up via CardMapper,
                // which is how Deck itself derives it (see CardService::update).
                $boardId = 0;
                if (class_exists('OCA\Deck\Db\CardMapper') === true) {
                    $cardMapper = \OC::$server->get('OCA\Deck\Db\CardMapper');
                    $boardId    = ($cardMapper->findBoardId($cardId) ?? 0);
                }

                return [
                    'title'   => $card->getTitle(),
                    'boardId' => $boardId,
                    'stackId' => $card->getStackId(),
                ];
            }
        } catch (Exception $e) {
            $this->logger->debug('Deck CardService not available, card lookup skipped: '.$e->getMessage());
        }//end try

        return null;
    }//end getDeckCardInfo()

    /**
     * Create a Deck card using Deck's service classes.
     *
     * @param int    $boardId     The board ID.
     * @param int    $stackId     The stack ID.
     * @param string $title       The card title.
     * @param string $description The card description.
     * @param string $objectUuid  The object UUID for the back-link.
     *
     * @return int|null The created card ID or null.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) boardId reserved for future board-context APIs.
     */
    private function createDeckCard(
        int $boardId,
        int $stackId,
        string $title,
        string $description,
        string $objectUuid
    ): ?int {
        try {
            if (class_exists('OCA\Deck\Service\CardService') === true) {
                $cardService = \OC::$server->get('OCA\Deck\Service\CardService');

                $fullDescription = $description;
                if (empty($fullDescription) === false) {
                    $fullDescription .= "\n\n";
                }

                $fullDescription .= '[Object: '.$objectUuid.'](/apps/openregister/objects/'.$objectUuid.')';

                $userUid = $this->userSession->getUser()->getUID();
                $card    = $cardService->create($title, $stackId, 'plain', 0, $userUid);
                // Deck CardService::update signature is:
                // update($id, $title, $stackId, $type, $owner, $description = '', $order = 0, ...)
                // The owner argument is required and must be a string user UID;
                // a previous version of this call passed 0 (zero) for owner and
                // mis-aligned every subsequent arg by one slot. Restore the
                // correct positional order so updating a freshly-created card
                // doesn't throw.
                $cardService->update($card->getId(), $title, $stackId, 'plain', $userUid, $fullDescription, 0);

                return $card->getId();
            }//end if
        } catch (Exception $e) {
            $this->logger->warning('Failed to create Deck card: '.$e->getMessage());
        }//end try

        return null;
    }//end createDeckCard()
}//end class
