<?php

/**
 * OpenRegister Admin Section
 *
 * Provides admin settings section for OpenRegister application.
 *
 * @category Section
 * @package  OCA\OpenRegister\Sections
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Sections;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * Admin section for OpenRegister settings.
 *
 * @category Section
 * @package  OCA\OpenRegister\Sections
 */
class OpenRegisterAdmin implements IIconSection
{

    /**
     * Localization service.
     *
     * @var IL10N
     */
    private IL10N $l;

    /**
     * URL generator service.
     *
     * @var IURLGenerator
     */
    private IURLGenerator $urlGenerator;


    /**
     * Constructor for OpenRegisterAdmin section.
     *
     * @param IL10N         $l            Localization service
     * @param IURLGenerator $urlGenerator URL generator service
     */
    public function __construct(IL10N $l, IURLGenerator $urlGenerator)
    {
        $this->l            = $l;
        $this->urlGenerator = $urlGenerator;

    }//end __construct()


    /**
     * Get the icon for this admin section.
     *
     * @return string Icon path
     */
    public function getIcon(): string
    {
        return $this->urlGenerator->imagePath(appName: 'openregister', file: 'app-dark.svg');

    }//end getIcon()


    /**
     * Get the ID of this admin section.
     *
     * @return string Section ID
     *
     * @psalm-return 'openregister'
     */
    public function getID(): string
    {
        return 'openregister';

    }//end getID()


    /**
     * Get the display name of this admin section.
     *
     * @return string Section name
     */
    public function getName(): string
    {
        return $this->l->t('Open Register');

    }//end getName()


    /**
     * Get the priority of this admin section.
     *
     * @return int Section priority
     *
     * @psalm-return 97
     */
    public function getPriority(): int
    {
        return 97;

    }//end getPriority()


}//end class
