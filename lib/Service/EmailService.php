<?php

/**
 * EmailService
 *
 * Service that wraps Nextcloud Mail message lookups and manages email-to-object links.
 * Emails are immutable; this service only creates/removes link references.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-46
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\EmailLink;
use OCA\OpenRegister\Db\EmailLinkMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * EmailService manages email-to-object links via the openregister_email_links table.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class EmailService
{

    /**
     * Email link mapper.
     *
     * @var EmailLinkMapper
     */
    private readonly EmailLinkMapper $emailLinkMapper;

    /**
     * App manager for checking Mail app availability.
     *
     * @var IAppManager
     */
    private readonly IAppManager $appManager;

    /**
     * Database connection for direct Mail queries.
     *
     * @var IDBConnection
     */
    private readonly IDBConnection $db;

    /**
     * User session for current user context.
     *
     * @var IUserSession
     */
    private readonly IUserSession $userSession;

    /**
     * Logger for error reporting.
     *
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;

    /**
     * Constructor.
     *
     * @param EmailLinkMapper $emailLinkMapper Email link mapper
     * @param IAppManager     $appManager      App manager
     * @param IDBConnection   $db              Database connection
     * @param IUserSession    $userSession     User session
     * @param LoggerInterface $logger          Logger
     *
     * @return void
     */
    /**
     * Schema mapper for iterating over schemas with `_mail` linked-type column.
     */
    private readonly SchemaMapper $schemaMapper;

    /**
     * Magic mapper for cross-table _mail JSONB containment queries.
     */
    private readonly MagicMapper $magicMapper;

    public function __construct(
        EmailLinkMapper $emailLinkMapper,
        IAppManager $appManager,
        IDBConnection $db,
        IUserSession $userSession,
        LoggerInterface $logger,
        SchemaMapper $schemaMapper,
        MagicMapper $magicMapper
    ) {
        $this->emailLinkMapper = $emailLinkMapper;
        $this->appManager      = $appManager;
        $this->db          = $db;
        $this->userSession = $userSession;
        $this->logger      = $logger;
        $this->schemaMapper = $schemaMapper;
        $this->magicMapper  = $magicMapper;
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
     * Get all email links for an object.
     *
     * @param string   $objectUuid The object UUID.
     * @param int|null $limit      Maximum results.
     * @param int|null $offset     Results offset.
     *
     * @return array{results: array, total: int} Email links with total count.
     */
    public function getEmailsForObject(string $objectUuid, ?int $limit=null, ?int $offset=null): array
    {
        $links = $this->emailLinkMapper->findByObjectUuid($objectUuid, $limit, $offset);
        $total = $this->emailLinkMapper->countByObjectUuid($objectUuid);

        $results = array_map(
            static function (EmailLink $link): array {
                return $link->jsonSerialize();
            },
            $links
        );

        return ['results' => $results, 'total' => $total];
    }//end getEmailsForObject()

    /**
     * Link an existing email to an object.
     *
     * @param string $objectUuid    The object UUID.
     * @param int    $registerId    The register ID.
     * @param int    $mailAccountId The mail account ID.
     * @param int    $mailMessageId The mail message ID.
     *
     * @return EmailLink The created link.
     *
     * @throws Exception If the email does not exist or is already linked.
     */
    public function linkEmail(
        string $objectUuid,
        int $registerId,
        int $mailAccountId,
        int $mailMessageId
    ): EmailLink {
        // Check for duplicate.
        $existing = $this->emailLinkMapper->findByObjectAndMessage($objectUuid, $mailMessageId);
        if ($existing !== null) {
            throw new Exception('Email already linked to this object', 409);
        }

        // Verify the email exists in the Mail app database.
        $messageData = $this->fetchMailMessage(messageId: $mailMessageId, accountId: $mailAccountId);
        if ($messageData === null) {
            throw new Exception('Mail message not found', 404);
        }

        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new Exception('No user logged in');
        }

        $link = new EmailLink();
        $link->setObjectUuid($objectUuid);
        $link->setRegisterId($registerId);
        $link->setMailAccountId($mailAccountId);
        $link->setMailMessageId($mailMessageId);
        $link->setMailMessageUid($messageData['uid'] ?? null);
        $link->setSubject($messageData['subject'] ?? null);
        $link->setSender($messageData['sender'] ?? null);
        $link->setLinkedBy($user->getUID());
        $link->setLinkedAt(new DateTime());

        if (isset($messageData['date']) === true && $messageData['date'] !== null) {
            $link->setMailDate(new DateTime($messageData['date']));
        }

        return $this->emailLinkMapper->insert($link);
    }//end linkEmail()

    /**
     * Remove an email link.
     *
     * @param int $linkId The link ID.
     *
     * @return void
     *
     * @throws Exception If the link is not found.
     */
    public function unlinkEmail(int $linkId): void
    {
        try {
            $link = $this->emailLinkMapper->find($linkId);
            $this->emailLinkMapper->delete($link);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            throw new Exception('Email link not found', 404);
        }
    }//end unlinkEmail()

    /**
     * Search email links by sender.
     *
     * @param string $sender The sender email address.
     *
     * @return array Array of email links with object UUIDs.
     */
    public function searchBySender(string $sender): array
    {
        // Backward-compat: if the legacy openregister_email_links table is
        // still present (deployments that haven't run the drop migration
        // yet), prefer it.
        $legacy = $this->emailLinkMapper->findBySender($sender);
        if ($legacy !== []) {
            return array_map(
                static fn(EmailLink $link): array => $link->jsonSerialize(),
                $legacy
            );
        }

        // New path: find Mail messages from this sender, then for each
        // {accountId}/{messageId} ID look up which OR objects link them
        // via the `_mail` JSON column on each magic table.
        $messageIds = $this->findMessageIdsBySender(sender: $sender);
        if ($messageIds === []) {
            return [];
        }

        $results = [];
        foreach ($this->getMailLinkedSchemas() as $schema) {
            foreach ($messageIds as $linkedId) {
                $entities = $this->magicMapper->findByLinkedEntity(
                    schema: $schema,
                    columnName: '_mail',
                    entityId: $linkedId
                );
                foreach ($entities as $entity) {
                    $results[] = [
                        'objectUuid'    => (string) $entity->getUuid(),
                        'register'      => (string) $entity->getRegister(),
                        'schema'        => (string) $entity->getSchema(),
                        'mailLinkedId'  => $linkedId,
                    ];
                }
            }
        }

        return $results;
    }//end searchBySender()

    /**
     * Query the Mail app's database for message IDs from a sender.
     *
     * @return array<int, string> List of "{accountId}/{messageId}" identifiers.
     */
    private function findMessageIdsBySender(string $sender): array
    {
        if ($this->isMailAvailable() === false) {
            return [];
        }
        try {
            // mail_recipients.type=0 is "from"; mail_recipients.email is the address.
            $sql  = "SELECT mb.account_id, m.id AS message_id
                     FROM oc_mail_messages m
                     JOIN oc_mail_recipients r ON r.message_id = m.id AND r.type = 0
                     JOIN oc_mail_mailboxes mb ON mb.id = m.mailbox_id
                     WHERE LOWER(r.email) = LOWER(?)
                     LIMIT 200";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$sender]);
            $ids  = [];
            while (($row = $stmt->fetch()) !== false) {
                $ids[] = ((int) $row['account_id']).'/'.((int) $row['message_id']);
            }
            return $ids;
        } catch (Exception $e) {
            $this->logger->warning('[EmailService] findMessageIdsBySender failed: '.$e->getMessage());
            return [];
        }
    }//end findMessageIdsBySender()

    /**
     * Schemas that opt into mail linkage via `linkedTypes: ["mail"]`.
     *
     * @return array<int, \OCA\OpenRegister\Db\Schema>
     */
    private function getMailLinkedSchemas(): array
    {
        try {
            $all = $this->schemaMapper->findAll(_multitenancy: false);
        } catch (\Throwable) {
            return [];
        }
        return array_values(array_filter(
            $all,
            static fn($schema) => in_array('mail', $schema->getLinkedTypes(), true) === true
        ));
    }//end getMailLinkedSchemas()

    /**
     * Delete all email links for an object (cleanup).
     *
     * @param string $objectUuid The object UUID.
     *
     * @return int Number of deleted links.
     */
    public function deleteLinksForObject(string $objectUuid): int
    {
        return $this->emailLinkMapper->deleteByObjectUuid($objectUuid);
    }//end deleteLinksForObject()

    /**
     * Fetch a mail message from the Mail app's database.
     *
     * @param int $messageId The mail message ID.
     * @param int $accountId The mail account ID.
     *
     * @return array|null Message data or null if not found.
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-46
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
                    $this->buildMailboxSubquery(qb: $qb, accountId: $accountId)
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
            $this->logger->warning('Failed to fetch mail message: '.$e->getMessage());
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
        return '(SELECT mb.id FROM *PREFIX*mail_mailboxes mb WHERE mb.account_id = '.$param.' AND mb.id = m.mailbox_id LIMIT 1)';

    }//end buildMailboxSubquery()
}//end class
