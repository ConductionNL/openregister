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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\EmailLink;
use OCA\OpenRegister\Db\EmailLinkMapper;
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
    public function __construct(
        EmailLinkMapper $emailLinkMapper,
        IAppManager $appManager,
        IDBConnection $db,
        IUserSession $userSession,
        LoggerInterface $logger
    ) {
        $this->emailLinkMapper = $emailLinkMapper;
        $this->appManager      = $appManager;
        $this->db          = $db;
        $this->userSession = $userSession;
        $this->logger      = $logger;
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
        $links = $this->emailLinkMapper->findBySender($sender);

        return array_map(
            static function (EmailLink $link): array {
                return $link->jsonSerialize();
            },
            $links
        );
    }//end searchBySender()

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
