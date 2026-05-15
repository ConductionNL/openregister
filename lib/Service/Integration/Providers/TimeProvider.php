<?php

/**
 * TimeProvider — exposes NC time-tracking entries linked to an OR
 * object via the IntegrationProvider contract.
 *
 * Default backing NC app is `timemanager`; the spec leaves this
 * configurable for sites running a different time-tracking app. The
 * wrapping TimeEntryService + `openregister_time_links` table land in
 * a follow-up — this provider registers the registry surface today,
 * gated on the chosen NC app.
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
 * @spec openspec/changes/integration-time-tracker/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- self-documenting IntegrationProvider metadata getters mirror the contract in the interface.

use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCP\App\IAppManager;
use OCP\IL10N;

class TimeProvider extends AbstractIntegrationProvider
{

    /**
     * Default NC app id. Configurable per the spec for sites running a
     * different time-tracking app.
     *
     * @var string
     */
    private const REQUIRED_APP = 'timemanager';

    public function __construct(
        private IAppManager $appManager,
        private IL10N $l10n,
    ) {
    }//end __construct()

    public function getId(): string
    {
        return 'time-tracker';
    }//end getId()

    public function getLabel(): string
    {
        return $this->l10n->t('Time');
    }//end getLabel()

    public function getIcon(): string
    {
        return 'Clock';
    }//end getIcon()

    public function getGroup(): ?string
    {
        return 'workflow';
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
            'message'    => $installed === true ? null : 'NC time-tracking app is not installed',
        ];
    }//end health()
}//end class
