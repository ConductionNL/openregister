<?php

/**
 * SharesProvider — exposes NC Shares linked to the OR object's files
 * via the IntegrationProvider contract.
 *
 * Shares are NC core (always available) and live in the Share Manager;
 * `requiredApp` is null. `query-time` storage strategy: shares are
 * queried live from `OCP\Share\IManager` filtered by the object's
 * linked files. The `list()` is wrapped here returning an empty list
 * until the file-link → share-filter glue lands (it requires the
 * Files integration's per-object link enumeration). `delete()`
 * delegates to `IManager::deleteShare()` for the immediate unshare path.
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
 * @spec openspec/changes/integration-shares/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- self-documenting IntegrationProvider metadata getters mirror the contract in the interface.

use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCP\IL10N;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager;
use Throwable;

class SharesProvider extends AbstractIntegrationProvider
{
    public function __construct(
        private IManager $shareManager,
        private IL10N $l10n,
    ) {
    }//end __construct()

    public function getId(): string
    {
        return 'shares';
    }//end getId()

    public function getLabel(): string
    {
        return $this->l10n->t('Shares');
    }//end getLabel()

    public function getIcon(): string
    {
        return 'Share';
    }//end getIcon()

    public function getGroup(): ?string
    {
        return 'core';
    }//end getGroup()

    public function getRequiredApp(): ?string
    {
        return null;
    }//end getRequiredApp()

    public function getStorageStrategy(): string
    {
        return 'query-time';
    }//end getStorageStrategy()

    public function isEnabled(): bool
    {
        return true;
    }//end isEnabled()

    /**
     * List shares relevant to an OR object.
     *
     * Stubbed to an empty list until the file-link → share-filter glue
     * lands (which needs the Files integration's per-object link
     * enumeration). The provider still registers so the slot exists and
     * `delete()` can resolve via shareId.
     *
     * @param string              $register Register slug or numeric id.
     * @param string              $schema   Schema slug or numeric id.
     * @param string              $objectId Object uuid.
     * @param array<string,mixed> $filters  Optional filters (ignored).
     *
     * @return array<int,array<string,mixed>>
     */
    public function list(string $register, string $schema, string $objectId, array $filters=[]): array
    {
        return [];
    }//end list()

    /**
     * Revoke a share. `entityId` is the share id surfaced by `list()`.
     *
     * @param string $register Register slug or numeric id (unused).
     * @param string $schema   Schema slug or numeric id (unused).
     * @param string $objectId Object uuid (unused).
     * @param string $entityId Share id.
     *
     * @return void
     */
    public function delete(string $register, string $schema, string $objectId, string $entityId): void
    {
        try {
            $share = $this->shareManager->getShareById($entityId);
        } catch (ShareNotFound $e) {
            // Idempotent: nothing to do.
            return;
        } catch (Throwable $e) {
            // Other failures surface as 500 at the controller; don't
            // pretend the unshare happened.
            throw $e;
        }

        $this->shareManager->deleteShare($share);
    }//end delete()
}//end class
