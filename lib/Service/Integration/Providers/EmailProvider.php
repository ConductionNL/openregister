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

use OCA\OpenRegister\Service\EmailService;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCP\App\IAppManager;
use OCP\IL10N;
use Throwable;

/**
 * Email (NC Mail link-only) integration provider.
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
     * @param EmailService $emailService Backing service.
     * @param IAppManager  $appManager   NC app manager.
     * @param IL10N        $l10n         Localisation.
     *
     * @return void
     */
    public function __construct(
        private EmailService $emailService,
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
        return $this->emailService->isMailAvailable();
    }//end isEnabled()

    /**
     * List email links for an OR object.
     *
     * `_limit` / `_page` filters are honoured.
     *
     * @param string              $register Register slug or numeric id (unused).
     * @param string              $schema   Schema slug or numeric id (unused).
     * @param string              $objectId Object uuid.
     * @param array<string,mixed> $filters  Optional `_limit` / `_page`.
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
     * Payload must carry `mailAccountId` (int) and `mailMessageId` (int);
     * `registerId` is read from the call's `$register` (numeric form).
     *
     * @param string              $register Register slug or numeric id.
     * @param string              $schema   Schema slug or numeric id (unused).
     * @param string              $objectId Object uuid.
     * @param array<string,mixed> $payload  Must carry `mailAccountId` + `mailMessageId`.
     *
     * @return array<string,mixed>
     */
    public function create(string $register, string $schema, string $objectId, array $payload): array
    {
        $registerId    = (int) ($payload['registerId'] ?? $register);
        $mailAccountId = (int) ($payload['mailAccountId'] ?? 0);
        $mailMessageId = (int) ($payload['mailMessageId'] ?? 0);

        $link = $this->emailService->linkEmail(
            objectUuid: $objectId,
            registerId: $registerId,
            mailAccountId: $mailAccountId,
            mailMessageId: $mailMessageId
        );

        return $link->jsonSerialize();
    }//end create()

    /**
     * Unlink an email from an OR object. The message stays in NC Mail.
     *
     * @param string $register Register slug or numeric id (unused).
     * @param string $schema   Schema slug or numeric id (unused).
     * @param string $objectId Object uuid (unused).
     * @param string $entityId Numeric link id.
     *
     * @return void
     */
    public function delete(string $register, string $schema, string $objectId, string $entityId): void
    {
        $this->emailService->unlinkEmail(linkId: (int) $entityId);
    }//end delete()

    public function health(): array
    {
        $available = $this->emailService->isMailAvailable();
        return [
            'status'     => $available === true ? 'ok' : 'unavailable',
            'authStatus' => 'configured',
            'message'    => $available === true ? null : 'NC Mail app is not installed',
        ];
    }//end health()
}//end class
