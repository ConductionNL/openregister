<?php

/**
 * EmailProvider — exposes Nextcloud Mail messages linked to an OR
 * object via the IntegrationProvider contract.
 *
 * Backed by the already-shipped EmailService. Link-only by design:
 * sending is out of scope (handled by n8n workflows per AD-2 of
 * `nextcloud-entity-relations`). The Mail app owns compose; the tab
 * offers "link existing message" via account/folder picker (the UI
 * concern), and the provider exposes that link path through `create()`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/integration-email/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- self-documenting IntegrationProvider metadata getters mirror the contract in the interface.

use OCA\OpenRegister\Service\EmailLinkService;
use OCA\OpenRegister\Service\EmailService;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCP\App\IAppManager;
use OCP\IL10N;
use Throwable;

/**
 * Email (NC Mail link-only) integration provider.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class EmailProvider extends AbstractIntegrationProvider {

	/**
	 * NC app id required for this integration.
	 *
	 * @var string
	 */
	private const REQUIRED_APP = 'mail';

	/**
	 * Constructor.
	 *
	 * Holds both the Tier-1 {@see EmailService} (kept for the legacy
	 * `list/search/bySender` surface that reads the `_mail` JSON column)
	 * and the Tier-2 {@see EmailLinkService} (idempotent upsert keyed
	 * on the full composite tuple, plus cursor pagination).
	 *
	 * @param EmailService $emailService Legacy service (list + search).
	 * @param EmailLinkService $emailLinkService Tier-2 link-table service.
	 * @param IAppManager $appManager NC app manager.
	 * @param IL10N $l10n Localisation.
	 *
	 * @return void
	 */
	public function __construct(
		private EmailService $emailService,
		private EmailLinkService $emailLinkService,
		private IAppManager $appManager,
		private IL10N $l10n,
	) {
	}//end __construct()

	public function getId(): string {
		return 'email';
	}//end getId()

	public function getLabel(): string {
		return $this->l10n->t('Emails');
	}//end getLabel()

	public function getIcon(): string {
		return 'Email';
	}//end getIcon()

	public function getGroup(): ?string {
		return 'comms';
	}//end getGroup()

	public function getRequiredApp(): ?string {
		return self::REQUIRED_APP;
	}//end getRequiredApp()

	public function getStorageStrategy(): string {
		return 'link-table';
	}//end getStorageStrategy()

	public function isEnabled(): bool {
		return $this->emailLinkService->isMailAvailable();
	}//end isEnabled()

	/**
	 * List email links for an OR object.
	 *
	 * `_limit` / `_page` filters are honoured. Page is zero-indexed
	 * (page=0 returns the first batch, page=1 returns offset=limit).
	 *
	 * Payload contract — each row carries the columns serialized by
	 * `EmailLink::jsonSerialize()`:
	 *   id, objectUuid, registerId, mailAccountId, mailMessageId,
	 *   mailMessageUid, subject, sender, mailDate (ATOM), linkedBy,
	 *   linkedAt (ATOM)
	 *
	 * This shape matches every field `CnEmailTab` and `CnEmailCard`
	 * consume (subject, sender, mailDate, mailAccountId,
	 * mailMessageId — used for the deep-link into NC Mail).
	 *
	 * Paging: the inner `EmailService::getEmailsForObject()` returns
	 * `['results' => [...], 'total' => int]`. Per the Tier-3 contract
	 * widening this provider now returns the full paginated envelope
	 * `{items, total, nextCursor}` so the generic
	 * `ObjectIntegrationsController` can pass the real `total` (and a
	 * next-page cursor) through to the frontend, which restores
	 * `CnEmailTab` load-more. The `nextCursor` is the zero-indexed next
	 * page number, present only while more rows remain.
	 *
	 * @param string $register Register slug or numeric id (unused).
	 * @param string $schema Schema slug or numeric id (unused).
	 * @param string $objectId Object uuid.
	 * @param array<string,mixed> $filters Optional `_limit` / `_page` (page zero-indexed).
	 *
	 * @return array{items:array<int,array<string,mixed>>,total:int,nextCursor:?string}
	 *
	 * @spec openspec/specs/integration-email/spec.md
	 */
	public function list(string $register, string $schema, string $objectId, array $filters = []): array {
		$limit = null;
		if (isset($filters['_limit']) === true) {
			$limit = (int)$filters['_limit'];
		}

		$page = 0;
		if (isset($filters['_page']) === true) {
			$page = max(0, (int)$filters['_page']);
		}

		$offset = 0;
		if ($limit !== null) {
			$offset = ($page * $limit);
		}

		$offsetArg = null;
		if ($limit !== null) {
			$offsetArg = $offset;
		}

		try {
			$result = $this->emailService->getEmailsForObject(
				objectUuid: $objectId,
				limit: $limit,
				offset: $offsetArg
			);
		} catch (Throwable $e) {
			return ['items' => [], 'total' => 0, 'nextCursor' => null];
		}

		$items = $result['results'];
		$total = (int)$result['total'];

		// A further page exists when the rows seen so far (offset + this
		// batch) is still short of the total. The cursor is the next
		// zero-indexed page number consumed by `_page`. Paging only
		// applies when a limit (and therefore a concrete offset) is set.
		$nextCursor = null;
		if ($limit !== null && (($offset + count($items)) < $total)) {
			$nextCursor = (string)($page + 1);
		}

		return [
			'items' => $items,
			'total' => $total,
			'nextCursor' => $nextCursor,
		];
	}//end list()

	/**
	 * Link an existing email to an OR object.
	 *
	 * Payload contract — supports both shapes:
	 *   - Tier-2 picker:  `{ mailAccountId, messageId, messageUid }`
	 *   - Legacy (back-compat): `{ mailAccountId, mailMessageId }`
	 *
	 * `registerId` / `schemaId` are read from the call's `$register` /
	 * `$schema` (numeric form). The Tier-2 path is idempotent on the
	 * full composite key `(objectUuid, accountId, messageId, messageUid)`
	 * and is preferred. When the caller still uses the legacy field
	 * `mailMessageId` the provider falls through to the Tier-2 service
	 * with an empty UID, which still de-duplicates on the
	 * `(objectUuid, accountId, messageId)` subset.
	 *
	 * @param string $register Register slug or numeric id.
	 * @param string $schema Schema slug or numeric id.
	 * @param string $objectId Object uuid.
	 * @param array<string,mixed> $payload Picker (Tier-2) or legacy shape.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/integration-email/spec.md
	 */
	public function create(string $register, string $schema, string $objectId, array $payload): array {
		$registerId = (int)($payload['registerId'] ?? $register);
		$schemaId = (int)($payload['schemaId'] ?? $schema);
		$mailAccountId = (int)($payload['mailAccountId'] ?? 0);

		// Tier-2 fields first, legacy fall-through second.
		$messageId = (string)($payload['messageId'] ?? $payload['mailMessageId'] ?? '');
		$messageUid = (string)($payload['messageUid'] ?? $payload['mailMessageUid'] ?? '');

		$link = $this->emailLinkService->linkEmail(
			objectUuid: $objectId,
			registerId: $registerId,
			schemaId: $schemaId,
			mailAccountId: $mailAccountId,
			messageId: $messageId,
			messageUid: $messageUid
		);

		return $link->jsonSerialize();
	}//end create()

	/**
	 * Unlink an email from an OR object. The message stays in NC Mail.
	 *
	 * @param string $register Register slug or numeric id (unused).
	 * @param string $schema Schema slug or numeric id (unused).
	 * @param string $objectId Object uuid.
	 * @param string $entityId Numeric link id.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/integration-email/spec.md
	 */
	public function delete(string $register, string $schema, string $objectId, string $entityId): void {
		$this->emailLinkService->unlinkEmail(objectUuid: $objectId, linkId: (int)$entityId);
	}//end delete()

	/**
	 * Provider health descriptor (enabled/disabled echo).
	 *
	 * @return array<string,mixed>
	 *
	 * @spec exclude Static enabled/disabled descriptor echoing isEnabled() — no standalone health behaviour;
	 *              the health/OCS contract is owned by pluggable-integration-registry task-2.
	 */
	public function health(): array {
		$available = $this->emailLinkService->isMailAvailable();

		$status = 'unavailable';
		if ($available === true) {
			$status = 'ok';
		}

		$message = 'NC Mail app is not installed';
		if ($available === true) {
			$message = null;
		}

		return [
			'status' => $status,
			'authStatus' => 'configured',
			'message' => $message,
		];
	}//end health()
}//end class
