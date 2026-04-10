<?php

/**
 * LinkedEntityEnricher
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Service\Object;

use OCP\IDBConnection;
use OCP\Comments\ICommentsManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Enriches linked entity IDs into full objects at read time.
 *
 * Follows the same pattern as RenderObject::renderFiles() — resolves IDs from
 * metadata columns into display objects using Nextcloud's native APIs.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Enrichment requires multiple NC APIs
 */
class LinkedEntityEnricher
{
    /**
     * Map of linked type names to enricher methods.
     */
    private const ENRICHER_MAP = [
        '_mail'     => 'enrichMail',
        '_contacts' => 'enrichContacts',
        '_notes'    => 'enrichNotes',
        '_todos'    => 'enrichTodos',
        '_calendar' => 'enrichCalendar',
        '_talk'     => 'enrichTalk',
        '_deck'     => 'enrichDeck',
    ];

    /**
     * Constructor for LinkedEntityEnricher.
     *
     * @param IDBConnection   $db              Database connection for Mail app queries
     * @param ICommentsManager $commentsManager Comments manager for notes
     * @param IUserManager    $userManager     User manager for resolving display names
     * @param LoggerInterface $logger          Logger
     */
    public function __construct(
        private readonly IDBConnection $db,
        private readonly ICommentsManager $commentsManager,
        private readonly IUserManager $userManager,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Enrich linked entity IDs for the requested _extend types.
     *
     * @param array $objectData The serialized object data (from jsonSerialize)
     * @param array $extend     The _extend parameters (e.g., ['_mail' => '1', '_contacts' => '1'])
     *
     * @return array The object data with enriched linked entities
     */
    public function enrich(array $objectData, array $extend): array
    {
        foreach (self::ENRICHER_MAP as $key => $method) {
            if (isset($extend[$key]) === false) {
                continue;
            }

            $ids = $objectData[$key] ?? [];
            if (empty($ids) === true || is_array($ids) === false) {
                continue;
            }

            $objectData[$key] = $this->$method($ids);
        }

        return $objectData;
    }//end enrich()

    /**
     * Enrich mail IDs to full mail objects.
     *
     * ID format: "{accountId}/{messageId}"
     *
     * @param array $ids Array of mail IDs
     *
     * @return array Array of enriched mail objects
     */
    private function enrichMail(array $ids): array
    {
        $results = [];

        foreach ($ids as $id) {
            $parts = explode('/', $id, 2);
            if (count($parts) !== 2) {
                $results[] = $this->notFoundResult($id);
                continue;
            }

            [$accountId, $messageId] = $parts;

            try {
                $sql  = "SELECT subject, `from` AS sender, sent_at FROM oc_mail_messages WHERE id = ? LIMIT 1";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([(int) $messageId]);
                $row = $stmt->fetch();

                if ($row === false) {
                    $results[] = $this->notFoundResult($id);
                    continue;
                }

                $results[] = [
                    'id'      => $id,
                    'subject' => $row['subject'] ?? '',
                    'sender'  => $row['sender'] ?? '',
                    'date'    => $row['sent_at'] ?? null,
                ];
            } catch (\Exception $e) {
                $this->logger->debug('[LinkedEntityEnricher] Mail enrichment failed', ['id' => $id, 'error' => $e->getMessage()]);
                $results[] = $this->notFoundResult($id);
            }
        }//end foreach

        return $results;
    }//end enrichMail()

    /**
     * Enrich contact UIDs to full contact objects.
     *
     * @param array $ids Array of contact UIDs
     *
     * @return array Array of enriched contact objects
     */
    private function enrichContacts(array $ids): array
    {
        $results = [];

        foreach ($ids as $id) {
            try {
                $sql  = "SELECT carddata FROM oc_cards WHERE uid = ? LIMIT 1";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([$id]);
                $row = $stmt->fetch();

                if ($row === false) {
                    $results[] = $this->notFoundResult($id);
                    continue;
                }

                // Parse minimal vCard data.
                $carddata = $row['carddata'] ?? '';
                $name     = $this->extractVcardField($carddata, 'FN');
                $email    = $this->extractVcardField($carddata, 'EMAIL');

                $results[] = [
                    'id'    => $id,
                    'name'  => $name ?? $id,
                    'email' => $email,
                ];
            } catch (\Exception $e) {
                $this->logger->debug('[LinkedEntityEnricher] Contact enrichment failed', ['id' => $id, 'error' => $e->getMessage()]);
                $results[] = $this->notFoundResult($id);
            }
        }//end foreach

        return $results;
    }//end enrichContacts()

    /**
     * Enrich note IDs to full note objects via ICommentsManager.
     *
     * @param array $ids Array of comment IDs
     *
     * @return array Array of enriched note objects
     */
    private function enrichNotes(array $ids): array
    {
        $results = [];

        foreach ($ids as $id) {
            try {
                $comment = $this->commentsManager->get($id);
                $actor   = $this->userManager->get($comment->getActorId());

                $results[] = [
                    'id'      => $id,
                    'message' => $comment->getMessage(),
                    'author'  => $actor !== null ? $actor->getDisplayName() : $comment->getActorId(),
                    'date'    => $comment->getCreationDateTime()->format('c'),
                ];
            } catch (\Exception $e) {
                $results[] = $this->notFoundResult($id);
            }
        }

        return $results;
    }//end enrichNotes()

    /**
     * Enrich todo UIDs to full todo objects.
     *
     * ID format: "{calendarId}/{uid}"
     *
     * @param array $ids Array of todo IDs
     *
     * @return array Array of enriched todo objects
     */
    private function enrichTodos(array $ids): array
    {
        $results = [];

        foreach ($ids as $id) {
            $parts = explode('/', $id, 2);
            if (count($parts) !== 2) {
                $results[] = $this->notFoundResult($id);
                continue;
            }

            [$calendarId, $uid] = $parts;

            try {
                $sql  = "SELECT calendardata FROM oc_calendarobjects WHERE calendarid = ? AND uid = ? LIMIT 1";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([(int) $calendarId, $uid]);
                $row = $stmt->fetch();

                if ($row === false) {
                    $results[] = $this->notFoundResult($id);
                    continue;
                }

                $caldata = $row['calendardata'] ?? '';
                $summary = $this->extractIcalField($caldata, 'SUMMARY');
                $status  = $this->extractIcalField($caldata, 'STATUS');
                $due     = $this->extractIcalField($caldata, 'DUE');

                $results[] = [
                    'id'     => $id,
                    'title'  => $summary ?? '',
                    'status' => $status ?? 'NEEDS-ACTION',
                    'due'    => $due,
                ];
            } catch (\Exception $e) {
                $results[] = $this->notFoundResult($id);
            }
        }//end foreach

        return $results;
    }//end enrichTodos()

    /**
     * Enrich calendar event UIDs to full event objects.
     *
     * ID format: "{calendarId}/{uid}"
     *
     * @param array $ids Array of calendar event IDs
     *
     * @return array Array of enriched event objects
     */
    private function enrichCalendar(array $ids): array
    {
        $results = [];

        foreach ($ids as $id) {
            $parts = explode('/', $id, 2);
            if (count($parts) !== 2) {
                $results[] = $this->notFoundResult($id);
                continue;
            }

            [$calendarId, $uid] = $parts;

            try {
                $sql  = "SELECT calendardata FROM oc_calendarobjects WHERE calendarid = ? AND uid = ? LIMIT 1";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([(int) $calendarId, $uid]);
                $row = $stmt->fetch();

                if ($row === false) {
                    $results[] = $this->notFoundResult($id);
                    continue;
                }

                $caldata  = $row['calendardata'] ?? '';
                $summary  = $this->extractIcalField($caldata, 'SUMMARY');
                $dtstart  = $this->extractIcalField($caldata, 'DTSTART');
                $dtend    = $this->extractIcalField($caldata, 'DTEND');
                $location = $this->extractIcalField($caldata, 'LOCATION');

                $results[] = [
                    'id'       => $id,
                    'title'    => $summary ?? '',
                    'start'    => $dtstart,
                    'end'      => $dtend,
                    'location' => $location,
                ];
            } catch (\Exception $e) {
                $results[] = $this->notFoundResult($id);
            }
        }//end foreach

        return $results;
    }//end enrichCalendar()

    /**
     * Enrich Talk conversation tokens to full conversation objects.
     *
     * @param array $ids Array of Talk tokens
     *
     * @return array Array of enriched Talk objects
     */
    private function enrichTalk(array $ids): array
    {
        $results = [];

        foreach ($ids as $id) {
            try {
                $sql  = "SELECT name, type FROM oc_talk_rooms WHERE token = ? LIMIT 1";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([$id]);
                $row = $stmt->fetch();

                if ($row === false) {
                    $results[] = $this->notFoundResult($id);
                    continue;
                }

                $results[] = [
                    'id'   => $id,
                    'name' => $row['name'] ?? '',
                    'type' => (int) ($row['type'] ?? 0),
                ];
            } catch (\Exception $e) {
                $results[] = $this->notFoundResult($id);
            }
        }

        return $results;
    }//end enrichTalk()

    /**
     * Enrich Deck card IDs to full card objects.
     *
     * ID format: "{boardId}/{cardId}"
     *
     * @param array $ids Array of Deck card IDs
     *
     * @return array Array of enriched Deck objects
     */
    private function enrichDeck(array $ids): array
    {
        $results = [];

        foreach ($ids as $id) {
            $parts = explode('/', $id, 2);
            if (count($parts) !== 2) {
                $results[] = $this->notFoundResult($id);
                continue;
            }

            [$boardId, $cardId] = $parts;

            try {
                $sql  = "SELECT c.title, b.title AS board_title, s.title AS stack_title
                         FROM oc_deck_cards c
                         JOIN oc_deck_stacks s ON c.stack_id = s.id
                         JOIN oc_deck_boards b ON s.board_id = b.id
                         WHERE c.id = ? AND b.id = ?
                         LIMIT 1";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([(int) $cardId, (int) $boardId]);
                $row = $stmt->fetch();

                if ($row === false) {
                    $results[] = $this->notFoundResult($id);
                    continue;
                }

                $results[] = [
                    'id'    => $id,
                    'title' => $row['title'] ?? '',
                    'board' => $row['board_title'] ?? '',
                    'stack' => $row['stack_title'] ?? '',
                ];
            } catch (\Exception $e) {
                $results[] = $this->notFoundResult($id);
            }
        }//end foreach

        return $results;
    }//end enrichDeck()

    /**
     * Create a "not found" fallback result for a missing entity.
     *
     * @param string $id The entity ID
     *
     * @return array The fallback result
     */
    private function notFoundResult(string $id): array
    {
        return [
            'id'    => $id,
            'label' => 'Not found',
        ];
    }//end notFoundResult()

    /**
     * Extract a field value from vCard data.
     *
     * @param string $carddata The raw vCard string
     * @param string $field    The field name (e.g., 'FN', 'EMAIL')
     *
     * @return string|null The field value or null
     */
    private function extractVcardField(string $carddata, string $field): ?string
    {
        if (preg_match('/' . preg_quote($field, '/') . '[^:]*:(.+)/i', $carddata, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }//end extractVcardField()

    /**
     * Extract a field value from iCalendar data.
     *
     * @param string $caldata The raw iCalendar string
     * @param string $field   The field name (e.g., 'SUMMARY', 'DTSTART')
     *
     * @return string|null The field value or null
     */
    private function extractIcalField(string $caldata, string $field): ?string
    {
        if (preg_match('/' . preg_quote($field, '/') . '[^:]*:(.+)/i', $caldata, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }//end extractIcalField()
}//end class
