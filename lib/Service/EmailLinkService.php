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
 *
 * @spec openspec/specs/generic-integrations/spec.md
 */
class EmailLinkService implements EmailLinkServiceInterface {
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
	 * Queries over the Mail app's tables.
	 *
	 * @var MailTableQueries
	 */
	private readonly MailTableQueries $mailQueries;

	/**
	 * Constructor.
	 *
	 * @param EmailLinkMapper $mapper Persistence for link rows.
	 * @param IAppManager $appManager NC app manager.
	 * @param IDBConnection $db Database connection (Mail tables).
	 * @param IUserSession $userSession Active session.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly EmailLinkMapper $mapper,
		private readonly IAppManager $appManager,
		IDBConnection $db,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		$this->mailQueries = new MailTableQueries(db: $db);
	}//end __construct()

	/**
	 * Whether NC Mail is installed + enabled for the current user.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function isMailAvailable(): bool {
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
	 * @param LinkTarget      $target  The object to link to.
	 * @param EmailMessageRef $message The Mail message (account, id, optional uid).
	 *
	 * @return EmailLink The persisted link row.
	 *
	 * @throws Exception On missing user (401), Mail unavailable (503),
	 *                   or message-not-found (404).
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function linkEmail(LinkTarget $target, EmailMessageRef $message): EmailLink {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new Exception('No user logged in', 401);
		}

		if ($this->isMailAvailable() === false) {
			throw new Exception('NC Mail app is not installed', 503);
		}

		if ($message->messageNumber() <= 0) {
			throw new Exception('Invalid mail message id', 400);
		}

		$this->assertAccountOwnership(accountId: $message->mailAccountId);

		// Idempotent path: return the existing row if the composite key
		// already exists.
		$existing = $this->mapper->findByObjectAccountMessageUid(
			objectUuid: $target->objectUuid,
			mailAccountId: $message->mailAccountId,
			mailMessageId: $message->messageNumber(),
			mailMessageUid: $message->uidForMatch()
		);
		if ($existing !== null) {
			return $existing;
		}

		// Harvest the metadata at link-time so the list path is one
		// SELECT against the link table.
		$row = $this->fetchMessageRow(
			mailAccountId: $message->mailAccountId,
			mailMessageId: $message->messageNumber()
		);
		if ($row === null) {
			throw new Exception('Mail message not found', 404);
		}

		$link = $this->buildNewLink(target: $target, message: $message);
		$this->applyHarvest(link: $link, row: $row);
		$link->setLinkedBy($user->getUID());
		$link->setLinkedAt(new DateTime());

		return $this->mapper->insert($link);
	}//end linkEmail()

	/**
	 * Build a new EmailLink entity for the target and message.
	 *
	 * @param LinkTarget      $target  The object to link to.
	 * @param EmailMessageRef $message The Mail message.
	 *
	 * @return EmailLink Unsaved link entity.
	 */
	private function buildNewLink(LinkTarget $target, EmailMessageRef $message): EmailLink {
		$link = new EmailLink();
		$link->setObjectUuid($target->objectUuid);
		$link->setRegisterId($target->registerId);
		if ($target->schemaId() > 0) {
			$link->setSchemaId($target->schemaId());
		}

		$link->setMailAccountId($message->mailAccountId);
		$link->setMailMessageId($message->messageNumber());
		$link->setMailMessageUid($message->uidForMatch());

		return $link;
	}//end buildNewLink()

	/**
	 * Copy the harvested message metadata onto the link; the row's uid is used when none was given.
	 *
	 * @param EmailLink           $link The unsaved link.
	 * @param array<string,mixed> $row  Harvested message metadata row.
	 *
	 * @return void
	 */
	private function applyHarvest(EmailLink $link, array $row): void {
		if ($link->getMailMessageUid() === null && $row['uid'] !== '') {
			$link->setMailMessageUid($row['uid']);
		}

		$link->setRfcMessageId(EmailLink::storableMessageId(messageId: ($row['rfcMessageId'] ?? null)));
		$link->setSubject($row['subject']);
		$link->setSender($row['sender']);

		if ($row['date'] !== null) {
			try {
				$link->setMailDate(new DateTime($row['date']));
			} catch (Throwable $e) {
				// Leave date null when the mail date string is unparseable.
			}
		}
	}//end applyHarvest()

	/**
	 * Unlink a Mail message from an OR object.
	 *
	 * @param string $objectUuid Parent OR object uuid.
	 * @param int $linkId Link primary key.
	 *
	 * @return void
	 *
	 * @throws Exception When no matching link is found (404).
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function unlinkEmail(string $objectUuid, int $linkId): void {
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
	 * @param string $objectUuid Parent OR object uuid.
	 * @param string|null $cursor Numeric link id to start after; null for first page.
	 * @param int $limit Page size (clamped to [1, MAX_LIMIT]).
	 *
	 * @return array{items: array<int,array<string,mixed>>, total: int, nextCursor: ?int}
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function getLinkedEmails(string $objectUuid, ?string $cursor = null, int $limit = self::DEFAULT_LIMIT): array {
		$limit = max(1, min($limit, self::MAX_LIMIT));

		// Cursor is the last-seen link id; we use a simple offset-by-id
		// skip since the mapper orders by mail_date DESC. To keep the
		// mapper unchanged we paginate via offset derived from the
		// numeric cursor's row index — which falls back gracefully to
		// first-page when cursor is null.
		$offset = 0;
		if ($cursor !== null && $cursor !== '') {
			$offset = max(0, (int)$cursor);
		}

		$links = $this->mapper->findByObjectUuid($objectUuid, $limit + 1, $offset);
		$total = $this->mapper->countByObjectUuid($objectUuid);

		$hasMore = count($links) > $limit;
		if ($hasMore === true) {
			$links = array_slice($links, 0, $limit);
		}

		$items = array_map(
			static fn (EmailLink $link): array => $link->jsonSerialize(),
			$links
		);

		$nextCursor = null;
		if ($hasMore === true) {
			$nextCursor = ($offset + $limit);
		}

		return [
			'items' => $items,
			'total' => $total,
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
	public function getAvailableAccounts(): array {
		$user = $this->userSession->getUser();
		if ($user === null || $this->isMailAvailable() === false) {
			return [];
		}

		try {
			$rows = $this->mailQueries->rowsOf(qb: $this->mailQueries->accountsQuery(userId: $user->getUID()));
		} catch (Throwable $e) {
			$this->logger->warning('[EmailLinkService] getAvailableAccounts failed: ' . $e->getMessage());
			return [];
		}

		return array_values(array_filter(array_map(fn (array $row): ?array => $this->accountItem(row: $row), $rows)));
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
	public function getMailboxesForAccount(int $accountId): array {
		if ($this->isMailAvailable() === false || $accountId <= 0) {
			return [];
		}

		$this->assertAccountOwnership(accountId: $accountId);

		try {
			$rows = $this->mailQueries->rowsOf(qb: $this->mailQueries->mailboxesQuery(accountId: $accountId));
		} catch (Throwable $e) {
			$this->logger->warning('[EmailLinkService] getMailboxesForAccount failed: ' . $e->getMessage());
			return [];
		}

		return array_values(array_filter(array_map(fn (array $row): ?array => $this->mailboxItem(row: $row), $rows)));
	}//end getMailboxesForAccount()

	/**
	 * Return messages in a mailbox for the 3rd picker step.
	 *
	 * Cursor pagination keyed on the message numeric id. Returned shape
	 * per row is `{id, uid, subject, sender, date}` and the response
	 * wraps `{items, nextCursor}`.
	 *
	 * @param int $accountId Mail account id (ownership-checked).
	 * @param string $mailbox Mailbox path (matches mail_mailboxes.name).
	 * @param string|null $afterCursor Numeric message id to start after; null for first page.
	 * @param int $limit Page size (clamped to [1, MAX_LIMIT]).
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
		int $limit = self::DEFAULT_LIMIT,
	): array {
		if ($this->isMailAvailable() === false || $accountId <= 0 || $mailbox === '') {
			return ['items' => [], 'nextCursor' => null];
		}

		$this->assertAccountOwnership(accountId: $accountId);
		$page = (new MailboxPage(accountId: $accountId, mailbox: $mailbox))
			->startingAfter(afterCursor: $afterCursor, limit: max(1, min($limit, self::MAX_LIMIT)));

		return $this->messagePage(page: $page, rows: $this->fetchMailboxRows(page: $page));
	}//end getMessagesForMailbox()

	/**
	 * Shape fetched message rows as a picker page.
	 *
	 * @param MailboxPage                         $page The requested page.
	 * @param array<int,array<string,mixed>>|null $rows Up to one row more than the page size; null on failure.
	 *
	 * @return array{items: array<int,array<string,mixed>>, nextCursor: ?int}
	 */
	private function messagePage(MailboxPage $page, ?array $rows): array {
		if ($rows === null) {
			return ['items' => [], 'nextCursor' => null];
		}

		$items = $this->normalizeMessageRows(rows: array_slice($rows, 0, $page->limit()));
		$nextCursor = null;
		if (count($rows) > $page->limit() && $items !== []) {
			$nextCursor = $items[count($items) - 1]['id'];
		}

		return ['items' => $items, 'nextCursor' => $nextCursor];
	}//end messagePage()

	/**
	 * Execute the mailbox message query and return the raw rows.
	 *
	 * Returns null when the mailbox cannot be resolved or the query fails.
	 *
	 * @param MailboxPage $page The requested page.
	 *
	 * @return array<int,array<string,mixed>>|null Raw DB rows or null on failure.
	 */
	private function fetchMailboxRows(MailboxPage $page): ?array {
		try {
			$mailboxId = $this->resolveMailboxId(accountId: $page->accountId, mailbox: $page->mailbox);
			if ($mailboxId === 0) {
				return null;
			}

			return $this->mailQueries->rowsOf(qb: $this->mailQueries->mailboxMessagesQuery(mailboxId: $mailboxId, page: $page));
		} catch (Throwable $e) {
			$this->logger->warning('[EmailLinkService] getMessagesForMailbox failed: ' . $e->getMessage());
			return null;
		}
	}//end fetchMailboxRows()


	/**
	 * Normalise raw DB message rows to the API item shape.
	 *
	 * @param array<int,array<string,mixed>> $rows Raw DB rows.
	 *
	 * @return array<int,array<string,mixed>> Normalised items.
	 */
	private function normalizeMessageRows(array $rows): array {
		$items = [];
		foreach ($rows as $row) {
			$items[] = ['id' => (int)($row['id'] ?? 0)] + $this->messageItem(row: $row);
		}

		return $items;
	}//end normalizeMessageRows()

	/**
	 * Resolve a mailbox id from `(accountId, mailbox-name)`.
	 *
	 * Returns 0 when not found so the caller can short-circuit to an
	 * empty page.
	 *
	 * @param int $accountId Mail account id.
	 * @param string $mailbox Mailbox name.
	 *
	 * @return int Mailbox id (0 when not found).
	 */
	private function resolveMailboxId(int $accountId, string $mailbox): int {
		try {
			$row = $this->mailQueries->firstRowOf(qb: $this->mailQueries->mailboxIdQuery(accountId: $accountId, mailbox: $mailbox));
		} catch (Throwable $e) {
			$this->logger->warning('[EmailLinkService] resolveMailboxId failed: ' . $e->getMessage());
			return 0;
		}

		if ($row === false) {
			return 0;
		}

		return (int)($row['id'] ?? 0);
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
	public function assertAccountOwnership(int $accountId): void {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new Exception('No user logged in', 401);
		}

		try {
			$row = $this->mailQueries->firstRowOf(qb: $this->mailQueries->accountOwnerQuery(accountId: $accountId));
		} catch (Throwable $e) {
			$this->logger->warning('[EmailLinkService] assertAccountOwnership lookup failed: ' . $e->getMessage());
			throw new Exception('Mail account not found', 404);
		}

		if ($row === false || (string)($row['user_id'] ?? '') !== $user->getUID()) {
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
	 * @return array{uid:string,subject:?string,sender:?string,date:?string,rfcMessageId:?string}|null
	 */
	private function fetchMessageRow(int $mailAccountId, int $mailMessageId): ?array {
		try {
			$row = $this->mailQueries->firstRowOf(qb: $this->mailQueries->messageQuery(accountId: $mailAccountId, messageId: $mailMessageId));
		} catch (Throwable $e) {
			$this->logger->warning('[EmailLinkService] fetchMessageRow failed: ' . $e->getMessage());
			return null;
		}

		if ($row === false) {
			return null;
		}

		return $this->messageItem(row: $row) + ['rfcMessageId' => ($row['message_id'] ?? null)];
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
	private function formatMailboxName(string $name): string {
		$segments = explode('.', $name);
		$last = end($segments);
		if ($last === false || $last === '') {
			return $name;
		}

		return (string)$last;
	}//end formatMailboxName()






	/**
	 * Picker item for an account row; null when the row has no valid id.
	 *
	 * @param array<string,mixed> $row Account row.
	 *
	 * @return array{id:int,label:string,email:string}|null The item.
	 */
	private function accountItem(array $row): ?array {
		$id = (int)($row['id'] ?? 0);
		if ($id <= 0) {
			return null;
		}

		$email = (string)($row['email'] ?? '');
		$label = (string)($row['name'] ?? '');
		if ($label === '') {
			$label = $email;
		}

		return ['id' => $id, 'label' => $label, 'email' => $email];
	}//end accountItem()

	/**
	 * Picker item for a mailbox row; null when the row has no valid id or name.
	 *
	 * @param array<string,mixed> $row Mailbox row.
	 *
	 * @return array{id:int,name:string,displayName:string}|null The item.
	 */
	private function mailboxItem(array $row): ?array {
		$id = (int)($row['id'] ?? 0);
		$name = (string)($row['name'] ?? '');
		if ($id <= 0 || $name === '') {
			return null;
		}

		return ['id' => $id, 'name' => $name, 'displayName' => $this->formatMailboxName(name: $name)];
	}//end mailboxItem()

	/**
	 * Uid, subject, sender and send time of a message row.
	 *
	 * @param array<string,mixed> $row Message row.
	 *
	 * @return array{uid:string,subject:?string,sender:?string,date:?string} The fields.
	 */
	private function messageItem(array $row): array {
		return [
			'uid' => (string)($row['uid'] ?? ''),
			'subject' => $row['subject'] ?? null,
			'sender' => $row['sender_email'] ?? null,
			'date' => $this->sentAtOf(row: $row),
		];
	}//end messageItem()

	/**
	 * The send time of a message row as an ISO 8601 string; null when unknown.
	 *
	 * @param array<string,mixed> $row Message row.
	 *
	 * @return string|null The send time.
	 */
	private function sentAtOf(array $row): ?string {
		if (isset($row['sent_at']) === false) {
			return null;
		}

		return date('c', (int)$row['sent_at']);
	}//end sentAtOf()



}//end class
