<?php

/**
 * TablesController
 *
 * Controller for managing database tables view.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IAppConfig;
use OCP\IRequest;

/**
 * TablesController class.
 */
/**
 * @psalm-suppress UnusedClass
 */

class TablesController extends Controller
{


    /**
     * Constructor
     *
     * @param string     $appName Application name
     * @param IRequest   $request Request object
     * @param IAppConfig $config  Application config
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly IAppConfig $config
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()


}//end class
