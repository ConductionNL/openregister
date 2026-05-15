<?php

/**
 * AnalyticsProvider — exposes NC Analytics reports linked to an OR
 * object through the IntegrationProvider contract.
 *
 * Storage strategy is `link-table`: a future
 * `openregister_analytics_links` table will pair an object/schema with
 * an analytics report id; the provider's read path will return those
 * links with embedded chart previews. The wrapping AnalyticsReportService
 * + migration land in a follow-up — this provider registers as a
 * registry surface today, gated on the Analytics app, returning an
 * empty list from `list()` until the service ships.
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
 * @spec openspec/changes/integration-analytics/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- self-documenting IntegrationProvider metadata getters mirror the contract in the interface.

use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCP\App\IAppManager;
use OCP\IL10N;

/**
 * Analytics (NC Analytics reports + datasets) integration provider.
 */
class AnalyticsProvider extends AbstractIntegrationProvider
{

    private const REQUIRED_APP = 'analytics';

    public function __construct(
        private IAppManager $appManager,
        private IL10N $l10n,
    ) {
    }//end __construct()

    public function getId(): string
    {
        return 'analytics';
    }//end getId()

    public function getLabel(): string
    {
        return $this->l10n->t('Analytics');
    }//end getLabel()

    public function getIcon(): string
    {
        return 'ChartBar';
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
            'message'    => $installed === true ? null : 'NC Analytics app is not installed',
        ];
    }//end health()
}//end class
