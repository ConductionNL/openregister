<?php

/**
 * IntegrationsAdminSettings — admin settings page that surfaces the
 * pluggable integration registry.
 *
 * Renders a table of every registered IntegrationProvider with:
 *   - id / label / group
 *   - storage strategy (magic-column / link-table / external / query-time)
 *   - required NC app + isEnabled() result
 *   - OpenConnector source (for external providers) + auth status
 *   - "Test connection" action (for external providers)
 *   - "Configure" deep-link into OpenConnector's credential UI
 *
 * Per AD-15: OpenRegister hosts the unified admin surface; the
 * actual credential flows live in OpenConnector. This page never
 * touches credentials directly — it links out to the right
 * OpenConnector source page.
 *
 * @category Settings
 * @package  OCA\OpenRegister\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-23
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Settings;

use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter;
use OCA\OpenRegister\Service\Integration\IntegrationProvider;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;

/**
 * Admin settings: integration registry overview + auth status.
 */
class IntegrationsAdminSettings implements ISettings
{

    /**
     * Constructor.
     *
     * @param IntegrationRegistry       $registry    Integration registry.
     * @param ExternalIntegrationRouter $router      External router (for probe()).
     * @param IAppManager               $appManager  NC app manager.
     * @param IURLGenerator             $urlGenerator URL generator (Configure deep-link).
     * @param IL10N                     $l10n        Localisation.
     *
     * @return void
     */
    public function __construct(
        private IntegrationRegistry $registry,
        private ExternalIntegrationRouter $router,
        private IAppManager $appManager,
        private IURLGenerator $urlGenerator,
        private IL10N $l10n,
    ) {
    }//end __construct()

    /**
     * @inheritDoc
     */
    public function getForm(): TemplateResponse
    {
        return new TemplateResponse(
            appName: 'openregister',
            templateName: 'settings/integrations-admin',
            params: ['rows' => $this->buildRows()],
            renderAs: 'admin'
        );
    }//end getForm()

    /**
     * @inheritDoc
     */
    public function getSection(): string
    {
        return 'openregister';
    }//end getSection()

    /**
     * @inheritDoc
     */
    public function getPriority(): int
    {
        // Below OpenRegisterAdmin (which is the default landing
        // surface) but above any future sections. 50 leaves room on
        // both sides.
        return 50;
    }//end getPriority()

    /**
     * Build the array of integration descriptors the template renders.
     *
     * Each row contains everything the table needs:
     *   id, label, group, storage, requiredApp, enabled, status,
     *   authStatus, message, openConnectorSource, configureUrl.
     *
     * @return array<int,array<string,mixed>>
     */
    private function buildRows(): array
    {
        $rows = [];
        foreach ($this->registry->list() as $provider) {
            $rows[] = $this->describe($provider);
        }

        return $rows;
    }//end buildRows()

    /**
     * Describe a single provider as a renderable row.
     *
     * @param IntegrationProvider $provider Provider.
     *
     * @return array<string,mixed>
     */
    private function describe(IntegrationProvider $provider): array
    {
        $requiredApp     = $provider->getRequiredApp();
        $isExternal      = ($provider->getStorageStrategy() === 'external');
        $health          = $this->probeHealth($provider);
        $openConnSource  = $provider->getOpenConnectorSource();
        $configureUrl    = ($isExternal === true && $openConnSource !== null)
            ? $this->buildOpenConnectorConfigureUrl($openConnSource)
            : null;

        return [
            'id'                  => $provider->getId(),
            'label'               => $provider->getLabel(),
            'group'               => $provider->getGroup() ?? '',
            'storage'             => $provider->getStorageStrategy(),
            'requiredApp'         => $requiredApp,
            'requiredAppOk'       => ($requiredApp === null || $this->appManager->isInstalled($requiredApp)),
            'enabled'             => $provider->isEnabled(),
            'isExternal'          => $isExternal,
            'openConnectorSource' => $openConnSource,
            'authStatus'          => $health['authStatus'],
            'status'              => $health['status'],
            'message'             => $health['message'],
            'configureUrl'        => $configureUrl,
            'testConnectionUrl'   => ($isExternal === true)
                ? $this->urlGenerator->linkToOCSRouteAbsolute(
                    'openregister.integrations.show',
                    ['id' => $provider->getId()]
                )
                : null,
        ];
    }//end describe()

    /**
     * Resolve the provider's health descriptor.
     *
     * External providers go through the router's `probe()` so failure
     * modes match what runtime callers will see. Native providers use
     * their own `health()` method (typically a static "ok" shape).
     *
     * @param IntegrationProvider $provider Provider.
     *
     * @return array{status: string, authStatus: string, message: ?string}
     */
    private function probeHealth(IntegrationProvider $provider): array
    {
        if ($provider->getStorageStrategy() === 'external') {
            return $this->router->probe($provider);
        }

        try {
            return $provider->health();
        } catch (\Throwable $e) {
            return [
                'status'     => 'unavailable',
                'authStatus' => 'unknown',
                'message'    => 'Provider health check threw',
            ];
        }
    }//end probeHealth()

    /**
     * Build the deep-link into OpenConnector's source-edit screen.
     *
     * OpenConnector exposes its sources at
     * `/apps/openconnector/sources/{sourceId}`; this helper builds
     * the absolute URL. When OpenConnector isn't installed we fall
     * back to the OpenConnector landing app entry so admins land on
     * an install/enable banner instead of a 404.
     *
     * @param string $sourceId OpenConnector source id declared by
     *                         the provider.
     *
     * @return string Absolute URL.
     */
    private function buildOpenConnectorConfigureUrl(string $sourceId): string
    {
        if ($this->appManager->isInstalled('openconnector') === false) {
            return $this->urlGenerator->getAbsoluteURL('/index.php/settings/apps/integration/openconnector');
        }

        try {
            return $this->urlGenerator->linkToRouteAbsolute(
                'openconnector.sources.show',
                ['id' => $sourceId]
            );
        } catch (\Throwable $e) {
            // OpenConnector's route names have varied across versions —
            // fall back to the source-edit URL by convention.
            return $this->urlGenerator->getAbsoluteURL(
                sprintf('/index.php/apps/openconnector/sources/%s', rawurlencode($sourceId))
            );
        }
    }//end buildOpenConnectorConfigureUrl()

}//end class
