<?php

/**
 * DeckCardService
 *
 * Service that wraps Nextcloud Deck card operations for linking cards to OpenRegister objects.
 * Uses the _deck metadata column as primary storage.
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

use Exception;
use OCA\OpenRegister\Db\MagicMapper;
use OCP\App\IAppManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * DeckCardService manages Deck card-to-object links via the _deck metadata column.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class DeckCardService
{
    /**
     * Constructor.
     *
     * @param MagicMapper          $magicMapper         Object mapper
     * @param LinkedEntityService  $linkedEntityService Reverse lookup service
     * @param IAppManager          $appManager          App manager
     * @param IUserSession         $userSession         User session
     * @param LoggerInterface      $logger              Logger
     */
    public function __construct(
        private readonly MagicMapper $magicMapper,
        private readonly LinkedEntityService $linkedEntityService,
        private readonly IAppManager $appManager,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
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
     * Get all deck card links for an object, enriched from Deck DB.
     *
     * @param string $objectUuid The object UUID.
     *
     * @return array{results: array, total: int}
     */
    public function getCardsForObject(string $objectUuid): array
    {
        $object  = $this->magicMapper->find($objectUuid);
        $deckIds = $object->getDeck() ?? [];
        $total   = count($deckIds);

        $results = [];
        foreach ($deckIds as $deckRef) {
            $parts = explode('/', $deckRef, 2);
            if (count($parts) !== 2) {
                $results[] = ['id' => $deckRef, 'label' => 'Invalid format'];
                continue;
            }

            [$boardId, $cardId] = $parts;
            $cardInfo = $this->getDeckCardInfo((int) $cardId);

            if ($cardInfo === null) {
                $results[] = ['id' => $deckRef, 'label' => 'Not found'];
                continue;
            }

            $results[] = [
                'id'      => $deckRef,
                'title'   => $cardInfo['title'] ?? '',
                'boardId' => (int) $boardId,
                'stackId' => $cardInfo['stackId'] ?? 0,
            ];
        }

        return ['results' => $results, 'total' => $total];
    }//end getCardsForObject()

    /**
     * Create a new Deck card linked to an object, or link an existing card.
     *
     * @param string $objectUuid The object UUID.
     * @param int    $registerId The register ID (kept for interface compatibility).
     * @param array  $data       Card data: boardId, stackId, title, description, or cardId for existing.
     *
     * @return array The linked card data.
     *
     * @throws Exception If parameters are missing or Deck operations fail.
     */
    public function linkOrCreateCard(string $objectUuid, int $registerId, array $data): array
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new Exception('No user logged in');
        }

        $cardId  = null;
        $boardId = 0;

        if (empty($data['cardId']) === false) {
            // Link existing card.
            $cardId   = (int) $data['cardId'];
            $cardInfo = $this->getDeckCardInfo($cardId);
            if ($cardInfo === null) {
                throw new Exception('Deck card not found', 404);
            }

            $boardId = $cardInfo['boardId'] ?? (int) ($data['boardId'] ?? 0);
        } else if (empty($data['boardId']) === false && empty($data['stackId']) === false) {
            // Create new card.
            $boardId = (int) $data['boardId'];
            $stackId = (int) $data['stackId'];
            $title   = $data['title'] ?? 'Untitled';

            $cardId = $this->createDeckCard(
                $boardId,
                $stackId,
                $title,
                $data['description'] ?? '',
                $objectUuid
            );
            if ($cardId === null) {
                throw new Exception('Failed to create Deck card');
            }
        } else {
            throw new Exception('Either cardId or boardId+stackId is required');
        }//end if

        $deckRef = $boardId . '/' . $cardId;

        // Append to _deck column.
        $object  = $this->magicMapper->find($objectUuid);
        $deckIds = $object->getDeck() ?? [];

        if (in_array($deckRef, $deckIds, true) === true) {
            // Idempotent.
            return ['id' => $deckRef, 'cardId' => $cardId, 'boardId' => $boardId];
        }

        $deckIds[] = $deckRef;
        $object->setDeck($deckIds);
        $this->magicMapper->update($object);

        return ['id' => $deckRef, 'cardId' => $cardId, 'boardId' => $boardId];
    }//end linkOrCreateCard()

    /**
     * Remove a deck card link from an object.
     *
     * @param string $objectUuid The object UUID.
     * @param string $deckRef    The deck reference (e.g., "3/42").
     *
     * @return void
     *
     * @throws Exception If object not found.
     */
    public function unlinkCard(string $objectUuid, string $deckRef): void
    {
        $object  = $this->magicMapper->find($objectUuid);
        $deckIds = $object->getDeck() ?? [];

        $deckIds = array_values(array_filter(
            $deckIds,
            static function (string $id) use ($deckRef): bool {
                return $id !== $deckRef;
            }
        ));

        $object->setDeck($deckIds);
        $this->magicMapper->update($object);
    }//end unlinkCard()

    /**
     * Find all objects linked to cards on a board.
     *
     * @param int $boardId The Deck board ID.
     *
     * @return array Array of linked objects.
     */
    public function getObjectsForBoard(int $boardId): array
    {
        // Use reverse lookup — all deck refs start with "boardId/".
        // Since reverseLookup searches for exact ID, we need a different approach.
        // For now, return empty — this needs a prefix-based search.
        $this->logger->debug('[DeckCardService] getObjectsForBoard: prefix-based lookup not yet implemented');
        return [];
    }//end getObjectsForBoard()

    /**
     * Delete all deck card links for an object (cleanup on object deletion).
     *
     * @param string $objectUuid The object UUID.
     *
     * @return int Number of deleted links.
     */
    public function deleteLinksForObject(string $objectUuid): int
    {
        try {
            $object  = $this->magicMapper->find($objectUuid);
            $deckIds = $object->getDeck() ?? [];
            $count   = count($deckIds);

            $object->setDeck(null);
            $this->magicMapper->update($object);

            return $count;
        } catch (Exception $e) {
            $this->logger->warning('[DeckCardService] deleteLinksForObject failed: ' . $e->getMessage());
            return 0;
        }
    }//end deleteLinksForObject()

    /**
     * Get Deck card info by card ID using Deck's service classes.
     *
     * @param int $cardId The card ID.
     *
     * @return array|null Card info or null.
     */
    private function getDeckCardInfo(int $cardId): ?array
    {
        try {
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
            $this->logger->debug('Deck CardService not available: ' . $e->getMessage());
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

                $fullDescription .= '[Object: ' . $objectUuid . '](/apps/openregister/objects/' . $objectUuid . ')';

                $card = $cardService->create(
                    $title,
                    $stackId,
                    'plain',
                    0,
                    $this->userSession->getUser()->getUID()
                );
                $cardService->update(
                    $card->getId(),
                    $title,
                    $stackId,
                    'plain',
                    0,
                    $fullDescription,
                    $this->userSession->getUser()->getUID()
                );

                return $card->getId();
            }
        } catch (Exception $e) {
            $this->logger->warning('Failed to create Deck card: ' . $e->getMessage());
        }

        return null;
    }//end createDeckCard()
}//end class
