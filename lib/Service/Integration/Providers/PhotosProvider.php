<?php

/**
 * PhotosProvider — exposes the image subset of the object's linked
 * files (with NC Photos metadata) via the IntegrationProvider contract.
 *
 * Builds on the Files integration: filters the object's linked files to
 * image MIME types and enriches each row with metadata (EXIF, taken-at,
 * camera, dimensions) from NC Photos. `link-table` storage strategy at
 * the registry level (reuses file links). The wrapping PhotoService
 * lands in a follow-up — this provider registers the registry surface
 * today, gated on the Photos app.
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
 * @spec openspec/changes/integration-photos/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- self-documenting IntegrationProvider metadata getters mirror the contract in the interface.

use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCP\App\IAppManager;
use OCP\IL10N;

class PhotosProvider extends AbstractIntegrationProvider
{

    private const REQUIRED_APP = 'photos';

    public function __construct(
        private IAppManager $appManager,
        private IL10N $l10n,
    ) {
    }//end __construct()

    public function getId(): string
    {
        return 'photos';
    }//end getId()

    public function getLabel(): string
    {
        return $this->l10n->t('Photos');
    }//end getLabel()

    public function getIcon(): string
    {
        return 'Image';
    }//end getIcon()

    public function getGroup(): ?string
    {
        return 'docs';
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

    public function list(string $register, string $schema, string $objectId, array $filters=[]): array
    {
        return [];
    }//end list()

    public function health(): array
    {
        $installed = $this->appManager->isInstalled(self::REQUIRED_APP);
        return [
            'status'     => $installed === true ? 'ok' : 'unavailable',
            'authStatus' => 'configured',
            'message'    => $installed === true ? null : 'NC Photos app is not installed',
        ];
    }//end health()
}//end class
