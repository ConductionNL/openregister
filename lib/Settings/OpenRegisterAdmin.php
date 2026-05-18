<?php

/**
 * OpenRegisterAdmin
 *
 * Admin settings page for OpenRegister application.
 *
 * @category Settings
 * @package  OCA\OpenRegister\Settings
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/add-live-updates/tasks.md#task-10
 */

namespace OCA\OpenRegister\Settings;

use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IL10N;
use OCP\Settings\ISettings;

/**
 * OpenRegisterAdmin
 *
 * Admin settings implementation for OpenRegister.
 *
 * @category Settings
 * @package  OCA\OpenRegister\Settings
 */
class OpenRegisterAdmin implements ISettings
{

    /**
     * Localization helper
     *
     * @var IL10N $l Localization helper
     */
    private IL10N $l;

    /**
     * Config service
     *
     * @var IConfig $config Config service
     */
    private IConfig $config;

    /**
     * App manager for checking installed apps
     *
     * @var IAppManager $appManager App manager
     */
    private IAppManager $appManager;

    /**
     * App configuration for reading push_available flag
     *
     * @var IAppConfig $appConfig App configuration
     */
    private IAppConfig $appConfig;

    /**
     * Initial state service for providing data to Vue
     *
     * @var IInitialState $initialState Initial state service
     */
    private IInitialState $initialState;

    /**
     * Constructor
     *
     * @param IConfig       $config       Config service
     * @param IL10N         $l            Localization helper
     * @param IAppManager   $appManager   App manager for checking notify_push installation
     * @param IAppConfig    $appConfig    App config for reading push_available flag
     * @param IInitialState $initialState Initial state service for Vue data
     *
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-25
     * @spec openspec/changes/add-live-updates/tasks.md#task-10
     */
    public function __construct(
        IConfig $config,
        IL10N $l,
        IAppManager $appManager,
        IAppConfig $appConfig,
        IInitialState $initialState
    ) {
        $this->config       = $config;
        $this->l            = $l;
        $this->appManager   = $appManager;
        $this->appConfig    = $appConfig;
        $this->initialState = $initialState;
    }//end __construct()

    /**
     * Get the push notification status for the admin UI.
     *
     * Returns one of three statuses:
     *   - `not_installed` — the notify_push app is not installed
     *   - `active`        — installed and at least one push has been confirmed
     *   - `unreachable`   — installed but no push confirmed yet
     *
     * This method MUST NOT instantiate IQueue to avoid exceptions in the UI
     * when notify_push is partially installed or misconfigured.
     *
     * @return string One of `not_installed`, `active`, or `unreachable`
     *
     * @spec openspec/changes/add-live-updates/tasks.md#task-10
     */
    private function getPushStatus(): string
    {
        if ($this->appManager->isInstalled('notify_push') === false) {
            return 'not_installed';
        }

        if ($this->appConfig->getValueString('openregister', 'push_available', '') === '1') {
            return 'active';
        }

        return 'unreachable';

    }//end getPushStatus()

    /**
     * Get the admin settings form
     *
     * @return TemplateResponse Template response
     *
     * @psalm-return TemplateResponse<200, array<string, mixed>>
     *
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-25
     * @spec openspec/changes/add-live-updates/tasks.md#task-10
     */
    public function getForm()
    {
        $pushStatus = $this->getPushStatus();

        // Provide pushStatus as initial state for the Vue admin settings app.
        $this->initialState->provideInitialState('push_status', $pushStatus);

        $parameters = [
            'mySetting'  => $this->config->getSystemValue('open_register_setting', true),
            'pushStatus' => $pushStatus,
        ];

        return new TemplateResponse(
            appName: 'openregister',
            templateName: 'settings/admin',
            params: $parameters,
            renderAs: 'admin'
        );
    }//end getForm()

    /**
     * Get the section identifier
     *
     * @return string Section identifier
     *
     * @psalm-return 'openregister'
     *
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-25
     */
    public function getSection()
    {
        // Name of the previously created section.
        $sectionName = 'openregister';
        return $sectionName;
    }//end getSection()

    /**
     * Get the priority of this settings form
     *
     * The form position in the admin section. Forms are arranged in ascending order
     * of priority values. Must return a value between 0 and 100.
     *
     * @return int Priority value between 0 and 100
     *
     * @psalm-return 11
     *
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-25
     */
    public function getPriority()
    {
        return 11;
    }//end getPriority()
}//end class
