<?php

/**
 * ContactsProvider — exposes vCard contacts linked to an OpenRegister
 * object via the IntegrationProvider contract.
 *
 * Backed by the already-shipped ContactService — dual storage in
 * `openregister_contact_links` (the link row, with role) and the vCard
 * itself (X-OPENREGISTER-OBJECT / X-OPENREGISTER-ROLE custom properties).
 * Role is a first-class field per AD-4 of `nextcloud-entity-relations`.
 *
 * The provider's `list()` returns the link rows (which carry the cached
 * vCard display name / email plus the role); `update()` re-points only
 * the role (the contact itself is edited in NC Contacts); `delete()`
 * removes the link and cleans the vCard's X-OPENREGISTER-* properties.
 * `create()` is intentionally not delegated here — the consuming UI has
 * two distinct flows (link existing vs. create new) which the dedicated
 * ContactsController routes; the registry's generic create() would lose
 * that distinction.
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
 * @spec openspec/changes/integration-contacts/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- self-documenting IntegrationProvider metadata getters mirror the contract in the interface.

use OCA\OpenRegister\Service\ContactService;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCP\App\IAppManager;
use OCP\IL10N;
use Throwable;

/**
 * Contacts (vCard / People) integration provider.
 */
class ContactsProvider extends AbstractIntegrationProvider
{

    /**
     * NC app id required for this integration.
     *
     * @var string
     */
    private const REQUIRED_APP = 'contacts';

    /**
     * Constructor.
     *
     * @param ContactService $contactService Backing service.
     * @param IAppManager    $appManager     NC app manager.
     * @param IL10N          $l10n           Localisation.
     *
     * @return void
     */
    public function __construct(
        private ContactService $contactService,
        private IAppManager $appManager,
        private IL10N $l10n,
    ) {
    }//end __construct()

    public function getId(): string
    {
        return 'contacts';
    }//end getId()

    public function getLabel(): string
    {
        return $this->l10n->t('Contacts');
    }//end getLabel()

    public function getIcon(): string
    {
        return 'AccountBox';
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
        return $this->appManager->isInstalled(self::REQUIRED_APP);
    }//end isEnabled()

    /**
     * List vCard links for an OR object.
     *
     * @param string              $register Register slug or numeric id (unused — CardDAV scope is per-user).
     * @param string              $schema   Schema slug or numeric id (unused).
     * @param string              $objectId Object uuid.
     * @param array<string,mixed> $filters  Optional filters (currently ignored).
     *
     * @return array<int,array<string,mixed>>
     */
    public function list(string $register, string $schema, string $objectId, array $filters=[]): array
    {
        try {
            $result = $this->contactService->getContactsForObject(objectUuid: $objectId);
        } catch (Throwable $e) {
            return [];
        }

        return $result['results'] ?? [];
    }//end list()

    /**
     * Update a contact link's role.
     *
     * Only the role can be updated through the registry path — the
     * vCard itself is owned by NC Contacts.
     *
     * @param string              $register Register slug or numeric id (unused).
     * @param string              $schema   Schema slug or numeric id (unused).
     * @param string              $objectId Object uuid (unused; link id is enough).
     * @param string              $entityId Numeric link id.
     * @param array<string,mixed> $payload  Must carry `role`.
     *
     * @return array<string,mixed>
     */
    public function update(string $register, string $schema, string $objectId, string $entityId, array $payload): array
    {
        $role = (string) ($payload['role'] ?? '');
        $link = $this->contactService->updateRole(linkId: (int) $entityId, role: $role);
        return $link->jsonSerialize();
    }//end update()

    /**
     * Unlink a contact from an OR object. The vCard remains in the
     * user's addressbook — only the link row + X-OPENREGISTER-*
     * properties are removed.
     *
     * @param string $register Register slug or numeric id (unused).
     * @param string $schema   Schema slug or numeric id (unused).
     * @param string $objectId Object uuid (unused; link id is enough).
     * @param string $entityId Numeric link id.
     *
     * @return void
     */
    public function delete(string $register, string $schema, string $objectId, string $entityId): void
    {
        $this->contactService->unlinkContact(linkId: (int) $entityId);
    }//end delete()

    public function health(): array
    {
        $installed = $this->appManager->isInstalled(self::REQUIRED_APP);
        return [
            'status'     => $installed === true ? 'ok' : 'unavailable',
            'authStatus' => 'configured',
            'message'    => $installed === true ? null : 'NC Contacts app is not installed',
        ];
    }//end health()
}//end class
