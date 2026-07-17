<?php

/**
 * IntegrationDashboardWidget — umbrella widget for the Nextcloud user-dashboard.
 *
 * Mounts a single Vue app under the NC dashboard tile that iterates over the
 * pluggable integration registry (ADR-019) and renders each registered
 * integration's `user-dashboard` widget. Following Option B
 * (provider directory): one umbrella widget, not 24 separate dashboard
 * tiles per leaf integration.
 *
 * The umbrella itself is ignorant of leaf data shapes — it just bootstraps
 * the registry and mounts {@see CnIntegrationWidgetGrid surface="user-dashboard"};
 * each leaf's widget knows how to render itself.
 *
 * @category Dashboard
 * @package  OCA\OpenRegister\Dashboard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Dashboard;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Service\ScriptManifestLoader;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\IWidget;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * Umbrella dashboard widget hosting all registered integration widgets
 * for the `user-dashboard` surface (ADR-019 Phase E).
 */
class IntegrationDashboardWidget implements IWidget, IIconWidget
{

    /**
     * Translation service.
     *
     * @var IL10N
     */
    private IL10N $l10n;

    /**
     * URL generator for icon resolution.
     *
     * @var IURLGenerator
     */
    private IURLGenerator $urlGenerator;

    /**
     * Constructor.
     *
     * @param IL10N         $l10n         Translation service.
     * @param IURLGenerator $urlGenerator URL generator.
     */
    public function __construct(IL10N $l10n, IURLGenerator $urlGenerator)
    {
        $this->l10n         = $l10n;
        $this->urlGenerator = $urlGenerator;
    }//end __construct()

    /**
     * Stable identifier for this widget.
     *
     * @return string Widget id.
     */
    public function getId(): string
    {
        return 'openregister-integrations';
    }//end getId()

    /**
     * User-facing widget title.
     *
     * @return string Translated title.
     */
    public function getTitle(): string
    {
        return $this->l10n->t('OpenRegister integrations');
    }//end getTitle()

    /**
     * Sorting order on the NC dashboard.
     *
     * @return int Order index.
     */
    public function getOrder(): int
    {
        return 50;
    }//end getOrder()

    /**
     * CSS class used for the small icon next to the title. Empty string
     * because we provide a full icon URL via {@see IIconWidget::getIconUrl()}.
     *
     * @return string Empty (icon provided via getIconUrl).
     */
    public function getIconClass(): string
    {
        return 'icon-openregister';
    }//end getIconClass()

    /**
     * Absolute URL for the small icon next to the widget title.
     *
     * @return string Icon URL.
     */
    public function getIconUrl(): string
    {
        return $this->urlGenerator->getAbsoluteURL(
            $this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg')
        );
    }//end getIconUrl()

    /**
     * Optional URL the user-facing widget title links to. We point at
     * the OpenRegister dashboard so users can drill into the full
     * integration grid.
     *
     * @return string|null Absolute URL.
     */
    public function getUrl(): ?string
    {
        return $this->urlGenerator->linkToRouteAbsolute('openregister.dashboard.page');
    }//end getUrl()

    /**
     * Bootstrap: enqueue the umbrella entry bundle that mounts the Vue
     * app inside the NC dashboard tile. The bundle is responsible for
     * installing the integration registry, registering builtins +
     * leaves, and rendering {@see CnIntegrationWidgetGrid}.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.StaticAccess) ScriptManifestLoader wraps the
     *   canonical \OCP\Util::addScript() API for enqueuing scripts from an
     *   IWidget::load() implementation; no injectable alternative (IUtil, etc.)
     *   exists in the NC AppFramework.
     */
    public function load(): void
    {
        ScriptManifestLoader::addEntryScripts(Application::APP_ID, 'userDashboard', 'openregister-user-dashboard');
    }//end load()
}//end class
