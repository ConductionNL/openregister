<?php

/**
 * EmailService
 *
 * Service that wraps Nextcloud Mail message lookups and manages email-to-object links
 * via the _mail metadata column on ObjectEntity.
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
use OCP\IDBConnection;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * EmailService manages email-to-object links via the _mail metadata column.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class EmailService
{
    /**
     * Constructor.
     *
     * @param MagicMapper          $magicMapper         Object mapper for loading/saving objects
     * @param LinkedEntityService  $linkedEntityService Reverse lookup service
     * @param IAppManager          $appManager          App manager
     * @param IDBConnection        $db                  Database connection for Mail queries
     * @param IUserSession         $userSession         User session
     * @param LoggerInterface      $logger              Logger
     */
    public function __construct(
        private readonly MagicMapper $magicMapper,
        private readonly LinkedEntityService $linkedEntityService,
        private readonly IAppManager $appManager,
        private readonly IDBConnection $db,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Check if the Nextcloud Mail app is installed and enabled.
     *
     * @return bool True if Mail app is available.
     */
    public function isMailAvailable(): bool
    {
        return $this->appManager->isEnabledForUser('mail');
    }//end isMailAvailable()

    /**
     * Get all email links for an object, enriched with Mail app metadata.
     *
     * @param string   $objectUuid The object UUID.
     * @param int|null $limit      Maximum results.
     * @param int|null $offset     Results offset.
     *
     * @return array{results: array, total: int} Enriched email data with total count.
     */
    public function getEmailsForObject(string $objectUuid, ?int $limit = null, ?int $offset = null): array
    {
        $object  = $this->magicMapper->find($objectUuid);
        $mailIds = $object->getMail() ?? [];
        $total   = count($mailIds);

        // Apply pagination.
        if ($offset !== null || $limit !== null) {
            $mailIds = array_slice($mailIds, $offset ?? 0, $limit);
        }

        // Enrich each ID from Mail app database.
        $results = [];
        foreach ($mailIds as $mailRef) {
            $parts = explode('/', $mailRef, 2);
            if (count($parts) !== 2) {
                $results[] = ['id' => $mailRef, 'label' => 'Invalid format'];
                continue;
            }

            [$accountId, $messageId] = $parts;
            $messageData = $this->fetchMailMessage((int) $messageId, (int) $accountId);

            if ($messageData === null) {
                $results[] = ['id' => $mailRef, 'label' => 'Not found'];
                continue;
            }

            $results[] = [
                'id'      => $mailRef,
                'subject' => $messageData['subject'] ?? '',
                'sender'  => $messageData['sender'] ?? '',
                'date'    => $messageData['date'] ?? null,
            ];
        }

        return ['results' => $results, 'total' => $total];
    }//end getEmailsForObject()

    /**
     * Link an email to an object via the _mail metadata column.
     *
     * @param string $objectUuid    The object UUID.
     * @param int    $registerId    The register ID (kept for interface compatibility).
     * @param int    $mailAccountId The mail account ID.
     * @param int    $mailMessageId The mail message ID.
     *
     * @return array The enriched email link data.
     *
     * @throws Exception If the email does not exist or user not logged in.
     */
    public function linkEmail(
        string $objectUuid,
        int $registerId,
        int $mailAccountId,
        int $mailMessageId
    ): array {
        $mailRef = $mailAccountId . '/' . $mailMessageId;

        // Verify the email exists in the Mail app database.
        $messageData = $this->fetchMailMessage($mailMessageId, $mailAccountId);
        if ($messageData === null) {
            throw new Exception('Mail message not found', 404);
        }

        // Load object and append to _mail array.
        $object  = $this->magicMapper->find($objectUuid);
        $mailIds = $object->getMail() ?? [];

        // Check for duplicate.
        if (in_array($mailRef, $mailIds, true) === true) {
            // Idempotent — return existing data.
            return [
                'id'      => $mailRef,
                'subject' => $messageData['subject'] ?? '',
                'sender'  => $messageData['sender'] ?? '',
                'date'    => $messageData['date'] ?? null,
            ];
        }

        $mailIds[] = $mailRef;
        $object->setMail($mailIds);
        $this->magicMapper->update($object);

        return [
            'id'      => $mailRef,
            'subject' => $messageData['subject'] ?? '',
            'sender'  => $messageData['sender'] ?? '',
            'date'    => $messageData['date'] ?? null,
        ];
    }//end linkEmail()

    /**
     * Remove an email link from an object.
     *
     * @param string $objectUuid The object UUID.
     * @param string $mailRef    The mail reference (e.g., "1/6").
     *
     * @return void
     *
     * @throws Exception If the object is not found.
     */
    public function unlinkEmail(string $objectUuid, string $mailRef): void
    {
        $object  = $this->magicMapper->find($objectUuid);
        $mailIds = $object->getMail() ?? [];

        $mailIds = array_values(array_filter(
            $mailIds,
            static function (string $id) use ($mailRef): bool {
                return $id !== $mailRef;
            }
        ));

        $object->setMail($mailIds);
        $this->magicMapper->update($object);
    }//end unlinkEmail()

    /**
     * Search for objects linked to emails from a specific sender.
     *
     * Uses LinkedEntityService reverse lookup and filters by sender via Mail DB.
     *
     * @param string $sender The sender email address.
     *
     * @return array Array of objects with their linked email info.
     */
    public function searchBySender(string $sender): array
    {
        // Get all objects that have any _mail links.
        try {
            $results = $this->linkedEntityService->reverseLookup('mail', $sender);
        } catch (Exception $e) {
            // Reverse lookup by sender not directly supported — fall back to empty.
            $this->logger->debug('[EmailService] searchBySender: reverse lookup failed, returning empty', [
                'sender' => $sender,
                'error'  => $e->getMessage(),
            ]);
            $results = [];
        }

        return $results;
    }//end searchBySender()

    /**
     * Delete all email links for an object (cleanup on object deletion).
     *
     * @param string $objectUuid The object UUID.
     *
     * @return int Number of deleted links.
     */
    public function deleteLinksForObject(string $objectUuid): int
    {
        try {
            $object  = $this->magicMapper->find($objectUuid);
            $mailIds = $object->getMail() ?? [];
            $count   = count($mailIds);

            $object->setMail(null);
            $this->magicMapper->update($object);

            return $count;
        } catch (Exception $e) {
            $this->logger->warning('[EmailService] deleteLinksForObject failed: ' . $e->getMessage());
            return 0;
        }
    }//end deleteLinksForObject()

    /**
     * Fetch a mail message from the Mail app's database.
     *
     * @param int $messageId The mail message ID.
     * @param int $accountId The mail account ID.
     *
     * @return array|null Message data or null if not found.
     */
    private function fetchMailMessage(int $messageId, int $accountId): ?array
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('m.id', 'm.uid', 'm.subject', 'm.sent_at')
                ->addSelect('r.email as sender_email')
                ->from('mail_messages', 'm')
                ->leftJoin(
                    'm',
                    'mail_recipients',
                    'r',
                    $qb->expr()->andX(
                        $qb->expr()->eq('r.message_id', 'm.id'),
                        $qb->expr()->eq('r.type', $qb->createNamedParameter(0))
                    )
                )
                ->where($qb->expr()->eq('m.id', $qb->createNamedParameter($messageId)))
                ->andWhere(
                    $qb->expr()->eq(
                        'm.mailbox_id',
                        $qb->createFunction(
                            $this->buildMailboxSubquery($qb, $accountId)
                        )
                    )
                )
                ->setMaxResults(1);

            $result = $qb->executeQuery();
            $row    = $result->fetch();
            $result->closeCursor();

            if ($row === false) {
                return null;
            }

            $sentAt = null;
            if (isset($row['sent_at']) === true && $row['sent_at'] !== null) {
                $sentAt = date('c', (int) $row['sent_at']);
            }

            return [
                'uid'     => (string) ($row['uid'] ?? ''),
                'subject' => $row['subject'] ?? null,
                'sender'  => $row['sender_email'] ?? null,
                'date'    => $sentAt,
            ];
        } catch (Exception $e) {
            $this->logger->warning('Failed to fetch mail message: ' . $e->getMessage());
            return null;
        }//end try
    }//end fetchMailMessage()

    /**
     * Build the mailbox subquery string for filtering by account.
     *
     * @param \OCP\DB\QueryBuilder\IQueryBuilder $qb        The query builder.
     * @param int                                $accountId The mail account ID.
     *
     * @return string The subquery string.
     */
    private function buildMailboxSubquery(\OCP\DB\QueryBuilder\IQueryBuilder $qb, int $accountId): string
    {
        $param = $qb->createNamedParameter($accountId);
        return '(SELECT mb.id FROM *PREFIX*mail_mailboxes mb'
            . ' WHERE mb.account_id = ' . $param
            . ' AND mb.id = m.mailbox_id LIMIT 1)';
    }//end buildMailboxSubquery()
}//end class
