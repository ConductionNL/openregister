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
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integration-email/tasks.md
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
class EmailProvider extends AbstractIntegrationProvider
{

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
     * @param EmailService     $emailService     Legacy service (list + search).
     * @param EmailLinkService $emailLinkService Tier-2 link-table service.
     * @param IAppManager      $appManager       NC app manager.
     * @param IL10N            $l10n             Localisation.
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

    public function getId(): string
    {
        return 'email';
    }//end getId()

    public function getLabel(): string
    {
        return $this->l10n->t('Emails');
    }//end getLabel()

    public function getIcon(): string
    {
        return 'Email';
    }//end getIcon()

    public function getGroup(): ?string
    {
        return 'comms';
    }//end getGroup()

    public function getRequiredApp(): ?string
    {
        return self::REQUIRED_APP;
    }//end getRequiredApp()

    public function getStorageStrategy(): string
    {
        return 'link-table';
    }//end getStorageStrategy()

    public function isEnabled(): bool
    {
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
     * mailMessageId — used for the deep-link into NC Mail). No
     * widening required for Phase B-2.
     *
     * Paging total: the inner `EmailService::getEmailsForObject()` does
     * return `['results' => [...], 'total' => int]`, but the
     * IntegrationProvider contract is `array<int,array>` (flat list),
     * so the total is dropped at this layer. The
     * `ObjectIntegrationsController` wraps the result in
     * `{items: [...]}` with no `total`. UI load-more (which expects
     * `data.total`) therefore relies on `messages.length === total`
     * fallback. Tracked as a fleet-wide contract widening — out of
     * scope for Phase B-2 (would change the IntegrationProvider return
     * type and ripple to all 19 providers).
     *
     * @param string              $register Register slug or numeric id (unused).
     * @param string              $schema   Schema slug or numeric id (unused).
     * @param string              $objectId Object uuid.
     * @param array<string,mixed> $filters  Optional `_limit` / `_page` (page zero-indexed).
     *
     * @return array<int,array<string,mixed>>
     */
    public function list(string $register, string $schema, string $objectId, array $filters=[]): array
    {
        $limit  = isset($filters['_limit']) === true ? (int) $filters['_limit'] : null;
        $offset = null;
        if ($limit !== null && isset($filters['_page']) === true) {
            $offset = max(0, ((int) $filters['_page'])) * $limit;
        }

        try {
            $result = $this->emailService->getEmailsForObject(
                objectUuid: $objectId,
                limit: $limit,
                offset: $offset
            );
        } catch (Throwable $e) {
            return [];
        }

        return $result['results'] ?? [];
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
     * @param string              $register Register slug or numeric id.
     * @param string              $schema   Schema slug or numeric id.
     * @param string              $objectId Object uuid.
     * @param array<string,mixed> $payload  Picker (Tier-2) or legacy shape.
     *
     * @return array<string,mixed>
     */
    public function create(string $register, string $schema, string $objectId, array $payload): array
    {
        $registerId    = (int) ($payload['registerId'] ?? $register);
        $schemaId      = (int) ($payload['schemaId'] ?? $schema);
        $mailAccountId = (int) ($payload['mailAccountId'] ?? 0);

        // Tier-2 fields first, legacy fall-through second.
        $messageId  = (string) ($payload['messageId'] ?? $payload['mailMessageId'] ?? '');
        $messageUid = (string) ($payload['messageUid'] ?? $payload['mailMessageUid'] ?? '');

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
     * @param string $schema   Schema slug or numeric id (unused).
     * @param string $objectId Object uuid.
     * @param string $entityId Numeric link id.
     *
     * @return void
     */
    public function delete(string $register, string $schema, string $objectId, string $entityId): void
    {
        $this->emailLinkService->unlinkEmail(objectUuid: $objectId, linkId: (int) $entityId);
    }//end delete()

    public function health(): array
    {
        $available = $this->emailLinkService->isMailAvailable();
        return [
            'status'     => $available === true ? 'ok' : 'unavailable',
            'authStatus' => 'configured',
            'message'    => $available === true ? null : 'NC Mail app is not installed',
        ];
    }//end health()
}//end class
