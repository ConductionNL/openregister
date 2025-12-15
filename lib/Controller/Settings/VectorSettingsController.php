<?php

declare(strict_types=1);

/*
 * OpenRegister Vector Settings Controller
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller\Settings
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Controller\Settings;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Exception;
use OCA\OpenRegister\Service\VectorizationService;
use Psr\Log\LoggerInterface;

/**
 * Controller for vector search operations.
 *
 * Handles:
 * - Semantic search
 * - Hybrid search (SOLR + vectors)
 * - Vector statistics
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Settings
 */
class VectorSettingsController extends Controller
{


    /**
     * Constructor.
     *
     * @param string               $appName              The app name.
     * @param IRequest             $request              The request.
     * @param VectorizationService $vectorizationService Vectorization service.
     * @param LoggerInterface      $logger               Logger.
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly VectorizationService $vectorizationService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()


}//end class
