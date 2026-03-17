<?php

/**
 * OpenRegisterAdmin
 *
 * Admin settings page for OpenRegister application.
 *
 * @category  Settings
 * @package   OCA\OpenRegister\Settings
 * @author    OpenRegister Team <info@conduction.nl>
 * @copyright 2024 OpenRegister
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link      https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Settings;

use OCP\AppFramework\Http\TemplateResponse;
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
     * Constructor
     *
     * @param IConfig $config Config service
     * @param IL10N   $l      Localization helper
     */
    public function __construct(IConfig $config, IL10N $l)
    {
        $this->config = $config;
        $this->l      = $l;
    }//end __construct()

    /**
     * Get the admin settings form
     *
     * @return TemplateResponse Template response
     *
     * @psalm-return TemplateResponse<200, array<never, never>>
     */
    public function getForm()
    {
        $parameters = [
            'mySetting' => $this->config->getSystemValue('open_register_setting', true),
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
     */
    public function getPriority()
    {
        return 11;
    }//end getPriority()
}//end class
