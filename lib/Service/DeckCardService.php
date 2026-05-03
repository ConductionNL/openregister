<?php

/**
 * DeckCardService
 *
 * Service that wraps Nextcloud Deck card operations for linking cards to OpenRegister objects.
 * Uses the Deck app's internal PHP service classes when available.
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
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
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
     * @param string $objectUuid The object UUID.
     *
     * @return array{results: array, total: int}
     */
    public function getCardsForObject(string $objectUuid): array
    {
        $links = $this->deckLinkMapper->findByObjectUuid($objectUuid);

        $results = array_map(
            static function (DeckLink $link): array {
                return $link->jsonSerialize();
            },
            $links
        );

        return ['results' => $results, 'total' => count($results)];
    }//end getCardsForObject()

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

        if (empty($data['cardId']) === false) {
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
        } else if (empty($data['boardId']) === false && empty($data['stackId']) === false) {
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
        } else {
            throw new Exception('Either cardId or boardId+stackId is required');
        }//end if

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
     */
    public function getObjectsForBoard(int $boardId): array
    {
        $links = $this->deckLinkMapper->findByBoardId($boardId);

        return array_map(
            static function (DeckLink $link): array {
                return $link->jsonSerialize();
            },
            $links
        );
    }//end getObjectsForBoard()

    /**
     * Delete all deck links for an object (cleanup).
     *
     * @param string $objectUuid The object UUID.
     *
     * @return int Number of deleted links.
     */
    public function deleteLinksForObject(string $objectUuid): int
    {
        return $this->deckLinkMapper->deleteByObjectUuid($objectUuid);
    }//end deleteLinksForObject()

    /**
     * Get Deck card info by card ID using direct DB query.
     *
     * Falls back to direct DB if Deck service classes are not available.
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

                return [
                    'title'   => $card->getTitle(),
                    'boardId' => $card->getBoardId() ?? 0,
                    'stackId' => $card->getStackId(),
                ];
            }
        } catch (Exception $e) {
            $this->logger->debug('Deck CardService not available, card lookup skipped: '.$e->getMessage());
        }

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
                $cardService->update($card->getId(), $title, $stackId, 'plain', 0, $fullDescription, $userUid);

                return $card->getId();
            }
        } catch (Exception $e) {
            $this->logger->warning('Failed to create Deck card: '.$e->getMessage());
        }

        return null;
    }//end createDeckCard()
}//end class
