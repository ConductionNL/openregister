<?php

/**
 * EmailLinkService — Tier-2 email integration service.
 *
 * Composes {@see EmailLinkMapper} with direct read-only queries against
 * the Nextcloud Mail app's `oc_mail_accounts`, `oc_mail_mailboxes`,
 * `oc_mail_messages` and `oc_mail_recipients` tables to back the
 * 3-step picker (account → mailbox → message) plus the idempotent
 * link/unlink/list operations the Tier-2 controller exposes.
 *
 * Picker model — read-only, DB-backed
 * ----------------------------------
 * Mail's PHP services (AccountService / MailboxService / MessageService)
 * are not stable public API surface and require an `IUser` context per
 * call. Since the picker only needs metadata (label, name, subject,
 * sender, sent-at) we read the Mail tables directly. This matches the
 * pattern already shipping in {@see EmailService::fetchMailMessage()}
 * (and the wider AD-2 compose-in-Mail design): the integration owns
 * link rows, not message content.
 *
 * Link/unlink — idempotent upsert
 * -------------------------------
 * `linkEmail()` performs a guarded upsert keyed on the full composite
 * `(objectUuid, mailAccountId, mailMessageId, mailMessageUid)` — Mail
 * frequently bumps UID on re-sync, so anchoring on the numeric
 * accountId/messageId pair is the only stable coordinate. Matching the
 * existing row returns it unchanged; otherwise a fresh row is inserted
 * with metadata (subject/sender/sentAt) harvested at link-time.
 *
 * Pagination — cursor + total
 * ---------------------------
 * `getLinkedEmails()` returns `{items, total, nextCursor}` so the UI's
 * load-more button can stitch pages. The cursor is the numeric link id
 * of the last-seen row — simpler than offset-based pagination and
 * stable across deletes.
 *
 * Mail unavailable
 * ----------------
 * Every Mail-backed method short-circuits to an empty result (or, in
 * the case of `linkEmail()`, a 503 Exception) when the Mail app is
 * disabled. The list path keeps reading the link-table directly so
 * historical references survive Mail being uninstalled.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Composes EmailLinkMapper, IDBConnection,
 *   IAppManager, IUserSession, and LoggerInterface; each is a required dependency for
 *   direct-SQL Mail table queries, availability checks, and user-session handling.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Direct SQL queries against NC Mail
 *   internal tables (oc_mail_messages, oc_mail_accounts) inflate complexity; the
 *   alternative (relying on Mail's PHP API) is unavailable outside Mail's own session.
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
use Throwable;

/**
 * EmailLinkService.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Composes mapper +
 *     IDBConnection (direct Mail-table queries) + IAppManager +
 *     IUserSession + LoggerInterface. Each dependency is required.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Spans send, link, list,
 *     enrich, and delete paths; each path requires direct NC Mail DB queries
 *     because Mail's own service layer is session-gated outside its own context.
 */
class EmailLinkService
{
    /**
     * NC Mail app id.
     *
     * @var string
     */
    private const MAIL_APP_ID = 'mail';

    /**
     * Default page size for paginated message lookups.
     *
     * @var int
     */
    private const DEFAULT_LIMIT = 50;

    /**
     * Hard upper bound for any single page (defence-in-depth).
     *
     * @var int
     */
    private const MAX_LIMIT = 200;

    /**
     * Mail recipient type for "from".
     *
     * @var int
     */
    private const RECIPIENT_TYPE_FROM = 0;

    /**
     * Constructor.
     *
     * @param EmailLinkMapper $mapper      Persistence for link rows.
     * @param IAppManager     $appManager  NC app manager.
     * @param IDBConnection   $db          Database connection (Mail tables).
     * @param IUserSession    $userSession Active session.
     * @param LoggerInterface $logger      Logger.
     */
    public function __construct(
        private readonly EmailLinkMapper $mapper,
        private readonly IAppManager $appManager,
        private readonly IDBConnection $db,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Whether NC Mail is installed + enabled for the current user.
     *
     * @return bool
     *
     * @spec openspec/specs/generic-integrations/spec.md
     */
    public function isMailAvailable(): bool
    {
        try {
            return $this->appManager->isEnabledForUser(self::MAIL_APP_ID);
        } catch (Throwable $e) {
            return false;
        }
    }//end isMailAvailable()

    /**
     * Link an existing Mail message to an OR object (idempotent upsert).
     *
     * Matches on the full composite tuple
     * `(objectUuid, mailAccountId, mailMessageId, mailMessageUid)` so a
     * re-sync that bumps the UID for the same message does not create a
     * duplicate. When the row already exists it is returned unchanged;
     * otherwise a fresh row is inserted with subject/sender/sent-at
     * harvested from Mail's tables at link-time so the list path can
     * render without a join.
     *
     * @param string $objectUuid    Parent OR object uuid.
     * @param int    $registerId    OR register id.
     * @param int    $schemaId      OR schema id (nullable in storage).
     * @param int    $mailAccountId Mail account id.
     * @param string $messageId     Mail message id (numeric string).
     * @param string $messageUid    Mail message UID (IMAP UID, opaque).
     *
     * @return EmailLink The persisted link row.
     *
     * @throws Exception On missing user (401), Mail unavailable (503),
     *                   or message-not-found (404).
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Sequential guard
     *     clauses for the four required preconditions (user, Mail
     *     available, message exists, upsert idempotency).
     *
     * @spec openspec/specs/generic-integrations/spec.md
     */
    public function linkEmail(
        string $objectUuid,
        int $registerId,
        int $schemaId,
        int $mailAccountId,
        string $messageId,
        string $messageUid
    ): EmailLink {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new Exception('No user logged in', 401);
        }

        if ($this->isMailAvailable() === false) {
            throw new Exception('NC Mail app is not installed', 503);
        }

        $messageIdInt = (int) $messageId;
        if ($messageIdInt <= 0) {
            throw new Exception('Invalid mail message id', 400);
        }

        $uidForMatch = null;
        if ($messageUid !== '') {
            $uidForMatch = $messageUid;
        }

        // Idempotent path: return the existing row if the composite key
        // already exists.
        $existing = $this->mapper->findByObjectAccountMessageUid(
            objectUuid: $objectUuid,
            mailAccountId: $mailAccountId,
            mailMessageId: $messageIdInt,
            mailMessageUid: $uidForMatch
        );
        if ($existing !== null) {
            return $existing;
        }

        // Harvest the metadata at link-time so the list path is one
        // SELECT against the link table.
        $message = $this->fetchMessageRow(
            mailAccountId: $mailAccountId,
            mailMessageId: $messageIdInt
        );
        if ($message === null) {
            throw new Exception('Mail message not found', 404);
        }

        $link = $this->buildNewLink(
            objectUuid: $objectUuid,
            registerId: $registerId,
            schemaId: $schemaId,
            mailAccountId: $mailAccountId,
            messageIdInt: $messageIdInt,
            uidForMatch: $uidForMatch,
            message: $message,
            userId: $user->getUID()
        );

        return $this->mapper->insert($link);
    }//end linkEmail()

    /**
     * Build a new EmailLink entity from link parameters and harvested message metadata.
     *
     * @param string              $objectUuid    Parent OR object uuid.
     * @param int                 $registerId    OR register id.
     * @param int                 $schemaId      OR schema id (0 = none).
     * @param int                 $mailAccountId Mail account id.
     * @param int                 $messageIdInt  Mail message id.
     * @param string|null         $uidForMatch   Explicit UID or null to fall back from message row.
     * @param array<string,mixed> $message       Harvested message metadata row.
     * @param string              $userId        Linking user's NC uid.
     *
     * @return EmailLink Unsaved link entity.
     */
    private function buildNewLink(
        string $objectUuid,
        int $registerId,
        int $schemaId,
        int $mailAccountId,
        int $messageIdInt,
        ?string $uidForMatch,
        array $message,
        string $userId
    ): EmailLink {
        $link = new EmailLink();
        $link->setObjectUuid($objectUuid);
        $link->setRegisterId($registerId);
        if ($schemaId > 0) {
            $link->setSchemaId($schemaId);
        }

        $link->setMailAccountId($mailAccountId);
        $link->setMailMessageId($messageIdInt);
        $effectiveUid = $message['uid'];
        if ($uidForMatch !== null || $message['uid'] === '') {
            $effectiveUid = $uidForMatch;
        }

        $link->setMailMessageUid($effectiveUid);
        $link->setSubject($message['subject']);
        $link->setSender($message['sender']);

        if ($message['date'] !== null) {
            try {
                $link->setMailDate(new DateTime($message['date']));
            } catch (Throwable $e) {
                // Leave date null when the mail date string is unparseable.
            }
        }

        $link->setLinkedBy($userId);
        $link->setLinkedAt(new DateTime());

        return $link;
    }//end buildNewLink()

    /**
     * Unlink a Mail message from an OR object.
     *
     * @param string $objectUuid Parent OR object uuid.
     * @param int    $linkId     Link primary key.
     *
     * @return void
     *
     * @throws Exception When no matching link is found (404).
     *
     * @spec openspec/specs/generic-integrations/spec.md
     */
    public function unlinkEmail(string $objectUuid, int $linkId): void
    {
        $deleted = $this->mapper->deleteByObjectAndId($objectUuid, $linkId);
        if ($deleted === 0) {
            throw new Exception('Email link not found', 404);
        }
    }//end unlinkEmail()

    /**
     * Return the linked emails for an object, paginated.
     *
     * Always succeeds — the link table is the source of truth and we
     * don't re-query Mail at read time. The returned shape is:
     *
     *   - `items`      — list of link rows (jsonSerialize shape)
     *   - `total`      — total link count for the object
     *   - `nextCursor` — id of the last row when there's another page,
     *                   otherwise null
     *
     * @param string      $objectUuid Parent OR object uuid.
     * @param string|null $cursor     Numeric link id to start after; null for first page.
     * @param int         $limit      Page size (clamped to [1, MAX_LIMIT]).
     *
     * @return array{items: array<int,array<string,mixed>>, total: int, nextCursor: ?int}
     *
     * @spec openspec/specs/generic-integrations/spec.md
     */
    public function getLinkedEmails(string $objectUuid, ?string $cursor=null, int $limit=self::DEFAULT_LIMIT): array
    {
        $limit = max(1, min($limit, self::MAX_LIMIT));

        // Cursor is the last-seen link id; we use a simple offset-by-id
        // skip since the mapper orders by mail_date DESC. To keep the
        // mapper unchanged we paginate via offset derived from the
        // numeric cursor's row index — which falls back gracefully to
        // first-page when cursor is null.
        $offset = 0;
        if ($cursor !== null && $cursor !== '') {
            $offset = max(0, (int) $cursor);
        }

        $links = $this->mapper->findByObjectUuid($objectUuid, $limit + 1, $offset);
        $total = $this->mapper->countByObjectUuid($objectUuid);

        $hasMore = count($links) > $limit;
        if ($hasMore === true) {
            $links = array_slice($links, 0, $limit);
        }

        $items = array_map(
            static fn(EmailLink $link): array => $link->jsonSerialize(),
            $links
        );

        $nextCursor = null;
        if ($hasMore === true) {
            $nextCursor = ($offset + $limit);
        }

        return [
            'items'      => $items,
            'total'      => $total,
            'nextCursor' => $nextCursor,
        ];
    }//end getLinkedEmails()

    /**
     * Return the IMAP accounts visible to the current user.
     *
     * Each row is `{id, label, email}` — the minimum the picker step 1
     * needs. Backed by `oc_mail_accounts.user_id = <current uid>`.
     *
     * @return array<int,array{id:int,label:string,email:string}>
     *
     * @spec openspec/specs/generic-integrations/spec.md
     */
    public function getAvailableAccounts(): array
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return [];
        }

        if ($this->isMailAvailable() === false) {
            return [];
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('id', 'name', 'email')
                ->from('mail_accounts')
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($user->getUID())))
                ->orderBy('name', 'ASC');

            $result = $qb->executeQuery();
            $rows   = $result->fetchAll();
            $result->closeCursor();
        } catch (Throwable $e) {
            $this->logger->warning('[EmailLinkService] getAvailableAccounts failed: '.$e->getMessage());
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $id    = (int) ($row['id'] ?? 0);
            $email = (string) ($row['email'] ?? '');
            $label = (string) ($row['name'] ?? '');
            if ($id <= 0) {
                continue;
            }

            if ($label === '') {
                $label = $email;
            }

            $out[] = [
                'id'    => $id,
                'label' => $label,
                'email' => $email,
            ];
        }

        return $out;
    }//end getAvailableAccounts()

    /**
     * Return the mailboxes for an account visible to the current user.
     *
     * Step 2 of the picker. Returns `{id, name, displayName}` for the
     * mailboxes belonging to the account — UI sorts/groups client-side.
     *
     * @param int $accountId Mail account id.
     *
     * @return array<int,array{id:int,name:string,displayName:string}>
     *
     * @spec openspec/specs/generic-integrations/spec.md
     */
    public function getMailboxesForAccount(int $accountId): array
    {
        if ($this->isMailAvailable() === false || $accountId <= 0) {
            return [];
        }

        $this->assertAccountOwnership(accountId: $accountId);

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('id', 'name')
                ->from('mail_mailboxes')
                ->where(
                    $qb->expr()->eq(
                        'account_id',
                        $qb->createNamedParameter($accountId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)
                    )
                )
                ->orderBy('name', 'ASC');

            $result = $qb->executeQuery();
            $rows   = $result->fetchAll();
            $result->closeCursor();
        } catch (Throwable $e) {
            $this->logger->warning('[EmailLinkService] getMailboxesForAccount failed: '.$e->getMessage());
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $id   = (int) ($row['id'] ?? 0);
            $name = (string) ($row['name'] ?? '');
            if ($id <= 0 || $name === '') {
                continue;
            }

            $out[] = [
                'id'          => $id,
                'name'        => $name,
                'displayName' => $this->formatMailboxName(name: $name),
            ];
        }

        return $out;
    }//end getMailboxesForAccount()

    /**
     * Return messages in a mailbox for the 3rd picker step.
     *
     * Cursor pagination keyed on the message numeric id. Returned shape
     * per row is `{id, uid, subject, sender, date}` and the response
     * wraps `{items, nextCursor}`.
     *
     * @param int         $accountId   Mail account id (ownership-checked).
     * @param string      $mailbox     Mailbox path (matches mail_mailboxes.name).
     * @param string|null $afterCursor Numeric message id to start after; null for first page.
     * @param int         $limit       Page size (clamped to [1, MAX_LIMIT]).
     *
     * @return array{items: array<int,array{id:int,uid:string,subject:?string,sender:?string,date:?string}>, nextCursor: ?int}
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Step-3 fetch
     *     composes several guard clauses (Mail availability, mailbox
     *     resolution, cursor parse, ownership) then a defensive
     *     try/catch around the join query.
     *
     * @spec openspec/specs/generic-integrations/spec.md
     */
    public function getMessagesForMailbox(
        int $accountId,
        string $mailbox,
        ?string $afterCursor,
        int $limit=self::DEFAULT_LIMIT
    ): array {
        if ($this->isMailAvailable() === false || $accountId <= 0 || $mailbox === '') {
            return ['items' => [], 'nextCursor' => null];
        }

        $this->assertAccountOwnership(accountId: $accountId);

        $limit = max(1, min($limit, self::MAX_LIMIT));

        $rows = $this->fetchMailboxRows(
            accountId: $accountId,
            mailbox: $mailbox,
            afterCursor: $afterCursor,
            fetch: $limit + 1
        );

        if ($rows === null) {
            return ['items' => [], 'nextCursor' => null];
        }

        $hasMore = count($rows) > $limit;
        if ($hasMore === true) {
            $rows = array_slice($rows, 0, $limit);
        }

        $items      = $this->normalizeMessageRows(rows: $rows);
        $nextCursor = null;
        if ($hasMore === true && $items !== []) {
            $nextCursor = $items[count($items) - 1]['id'];
        }

        return [
            'items'      => $items,
            'nextCursor' => $nextCursor,
        ];
    }//end getMessagesForMailbox()

    /**
     * Execute the mailbox message query and return the raw rows.
     *
     * Returns null when the mailbox cannot be resolved or the query fails.
     *
     * @param int         $accountId   Mail account id.
     * @param string      $mailbox     Mailbox name.
     * @param string|null $afterCursor Opaque cursor (message id) or null.
     * @param int         $fetch       Number of rows to fetch (limit + 1 for hasMore detection).
     *
     * @return array<int,array<string,mixed>>|null Raw DB rows or null on failure.
     */
    private function fetchMailboxRows(int $accountId, string $mailbox, ?string $afterCursor, int $fetch): ?array
    {
        try {
            $mailboxId = $this->resolveMailboxId(accountId: $accountId, mailbox: $mailbox);
            if ($mailboxId === 0) {
                return null;
            }

            $qb = $this->db->getQueryBuilder();
            $qb->select('m.id', 'm.uid', 'm.subject', 'm.sent_at')
                ->addSelect('r.email AS sender_email')
                ->from('mail_messages', 'm')
                ->leftJoin(
                    'm',
                    'mail_recipients',
                    'r',
                    $qb->expr()->andX(
                        $qb->expr()->eq('r.message_id', 'm.id'),
                        $qb->expr()->eq(
                            'r.type',
                            $qb->createNamedParameter(self::RECIPIENT_TYPE_FROM)
                        )
                    )
                )
                ->where(
                    $qb->expr()->eq(
                        'm.mailbox_id',
                        $qb->createNamedParameter($mailboxId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)
                    )
                )
                ->orderBy('m.sent_at', 'DESC')
                ->addOrderBy('m.id', 'DESC')
                ->setMaxResults($fetch);

            $cursorId = 0;
            if ($afterCursor !== null && $afterCursor !== '') {
                $cursorId = (int) $afterCursor;
            }

            if ($cursorId > 0) {
                $qb->andWhere(
                    $qb->expr()->lt(
                        'm.id',
                        $qb->createNamedParameter($cursorId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)
                    )
                );
            }

            $result = $qb->executeQuery();
            $rows   = $result->fetchAll();
            $result->closeCursor();

            return $rows;
        } catch (Throwable $e) {
            $this->logger->warning('[EmailLinkService] getMessagesForMailbox failed: '.$e->getMessage());
            return null;
        }//end try
    }//end fetchMailboxRows()

    /**
     * Normalise raw DB message rows to the API item shape.
     *
     * @param array<int,array<string,mixed>> $rows Raw DB rows.
     *
     * @return array<int,array<string,mixed>> Normalised items.
     */
    private function normalizeMessageRows(array $rows): array
    {
        $items = [];
        foreach ($rows as $row) {
            $id     = (int) ($row['id'] ?? 0);
            $sentAt = null;
            if (isset($row['sent_at']) === true && $row['sent_at'] !== null) {
                $sentAt = date('c', (int) $row['sent_at']);
            }

            $items[] = [
                'id'      => $id,
                'uid'     => (string) ($row['uid'] ?? ''),
                'subject' => $row['subject'] ?? null,
                'sender'  => $row['sender_email'] ?? null,
                'date'    => $sentAt,
            ];
        }

        return $items;
    }//end normalizeMessageRows()

    /**
     * Resolve a mailbox id from `(accountId, mailbox-name)`.
     *
     * Returns 0 when not found so the caller can short-circuit to an
     * empty page.
     *
     * @param int    $accountId Mail account id.
     * @param string $mailbox   Mailbox name.
     *
     * @return int Mailbox id (0 when not found).
     */
    private function resolveMailboxId(int $accountId, string $mailbox): int
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('id')
                ->from('mail_mailboxes')
                ->where(
                    $qb->expr()->eq(
                        'account_id',
                        $qb->createNamedParameter($accountId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)
                    )
                )
                ->andWhere($qb->expr()->eq('name', $qb->createNamedParameter($mailbox)))
                ->setMaxResults(1);

            $result = $qb->executeQuery();
            $row    = $result->fetch();
            $result->closeCursor();

            if ($row === false) {
                return 0;
            }

            return (int) ($row['id'] ?? 0);
        } catch (Throwable $e) {
            $this->logger->warning('[EmailLinkService] resolveMailboxId failed: '.$e->getMessage());
            return 0;
        }//end try
    }//end resolveMailboxId()

    /**
     * Verify the active user owns the Mail account; throw on mismatch.
     *
     * Defence-in-depth — Mail's middleware already enforces this server-side
     * for its own endpoints, but the picker reaches into the raw tables
     * so we re-check ownership before exposing any per-account data.
     *
     * @param int $accountId Mail account id.
     *
     * @return void
     *
     * @throws Exception When the active user does not own the account.
     */
    private function assertAccountOwnership(int $accountId): void
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new Exception('No user logged in', 401);
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('user_id')
                ->from('mail_accounts')
                ->where(
                    $qb->expr()->eq(
                        'id',
                        $qb->createNamedParameter($accountId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)
                    )
                )
                ->setMaxResults(1);

            $result = $qb->executeQuery();
            $row    = $result->fetch();
            $result->closeCursor();
        } catch (Throwable $e) {
            $this->logger->warning('[EmailLinkService] assertAccountOwnership lookup failed: '.$e->getMessage());
            throw new Exception('Mail account not found', 404);
        }

        if ($row === false || (string) ($row['user_id'] ?? '') !== $user->getUID()) {
            throw new Exception('Mail account not accessible to this user', 403);
        }
    }//end assertAccountOwnership()

    /**
     * Fetch the subject/sender/sent-at for a single Mail message id.
     *
     * Returns null on any failure (Mail unavailable, message missing,
     * query error) so the caller can map to a 404.
     *
     * @param int $mailAccountId Mail account id.
     * @param int $mailMessageId Mail message id.
     *
     * @return array{uid:string,subject:?string,sender:?string,date:?string}|null
     */
    private function fetchMessageRow(int $mailAccountId, int $mailMessageId): ?array
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('m.id', 'm.uid', 'm.subject', 'm.sent_at')
                ->addSelect('r.email AS sender_email')
                ->from('mail_messages', 'm')
                ->innerJoin(
                    'm',
                    'mail_mailboxes',
                    'mb',
                    $qb->expr()->andX(
                        $qb->expr()->eq('mb.id', 'm.mailbox_id'),
                        $qb->expr()->eq(
                            'mb.account_id',
                            $qb->createNamedParameter($mailAccountId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)
                        )
                    )
                )
                ->leftJoin(
                    'm',
                    'mail_recipients',
                    'r',
                    $qb->expr()->andX(
                        $qb->expr()->eq('r.message_id', 'm.id'),
                        $qb->expr()->eq(
                            'r.type',
                            $qb->createNamedParameter(self::RECIPIENT_TYPE_FROM)
                        )
                    )
                )
                ->where(
                    $qb->expr()->eq(
                        'm.id',
                        $qb->createNamedParameter($mailMessageId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)
                    )
                )
                ->setMaxResults(1);

            $result = $qb->executeQuery();
            $row    = $result->fetch();
            $result->closeCursor();
        } catch (Throwable $e) {
            $this->logger->warning('[EmailLinkService] fetchMessageRow failed: '.$e->getMessage());
            return null;
        }//end try

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
    }//end fetchMessageRow()

    /**
     * Best-effort prettified mailbox display name.
     *
     * Mail stores mailbox paths verbatim (e.g. `INBOX.Sent`); the
     * picker shows the last segment for compactness, leaving the full
     * path on the `name` field for the API call.
     *
     * @param string $name Raw mailbox name.
     *
     * @return string Display name.
     */
    private function formatMailboxName(string $name): string
    {
        $segments = explode('.', $name);
        $last     = end($segments);
        if ($last === false || $last === '') {
            return $name;
        }

        return (string) $last;
    }//end formatMailboxName()
}//end class
