<?php
namespace OCA\OpenRegister\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IL10N;
use OCP\Settings\ISettings;

class OpenRegisterAdmin implements ISettings
{

    private IL10N $l;

    private IConfig $config;


    public function __construct(IConfig $config, IL10N $l)
    {
        $this->config = $config;
        $this->l      = $l;

    }//end __construct()


    /**
     * @return TemplateResponse
     */
    public function getForm()
    {
        $parameters = [
            'mySetting' => $this->config->getSystemValue('open_register_setting', true),
        ];

        return new TemplateResponse('openregister', 'settings/admin', $parameters, 'admin');

    }//end getForm()


    public function getSection()
    {
        // Name of the previously created section.
        $sectionName = 'openregister';
        return $sectionName;

    }//end getSection()


    /**
     * @return int whether the form should be rather on the top or bottom of
     * the admin section. The forms are arranged in ascending order of the
     * priority values. It is required to return a value between 0 and 100.
     *
     * E.g.: 70
     */
    public function getPriority()
    {
        return 11;

    }//end getPriority()


}//end class
