<?php

/**
 * OpenRegister AggregationController
 *
 * Sugar HTTP entry point for the x-openregister-aggregations annotation.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use RuntimeException;

class AggregationController extends Controller
{

    public function __construct(
        string $appName,
        IRequest $request,
        private readonly AggregationRunner $runner
    ) {
        parent::__construct($appName, $request);
    }//end __construct()

    /**
     * Run a named aggregation declared on the schema.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function aggregate(string $register, string $schema, string $name): JSONResponse
    {
        try {
            $result = $this->runner->run(registerRef: $register, schemaRef: $schema, name: $name);
        } catch (RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }

        return new JSONResponse($result);
    }//end aggregate()

}//end class
