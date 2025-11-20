<?php

namespace OCA\OpenRegister\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IAppConfig;
use OCP\IRequest;

class TablesController extends Controller
{


    public function __construct(
        $appName,
        IRequest $request,
        private readonly IAppConfig $config
    ) {
        parent::__construct($appName, $request);

    }//end __construct()


}//end class
