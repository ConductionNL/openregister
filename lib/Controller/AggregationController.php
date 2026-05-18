<?php

/**
 * AggregationController
 *
 * Executes named aggregations declared on a schema via the x-openregister-aggregations
 * extension and returns the result with backend attribution and cache-verdict header.
 *
 * Endpoint: GET /api/objects/aggregations/{register}/{schema}/{name}
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/aggregations-backend-native/tasks.md#task-6
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Aggregation\AggregationQuery;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * REST controller for named aggregation execution.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/aggregations-backend-native/tasks.md#task-6
 */
class AggregationController extends Controller
{
    /**
     * Constructor.
     *
     * @param string            $appName        App name.
     * @param IRequest          $request        HTTP request.
     * @param RegisterMapper    $registerMapper Register mapper.
     * @param SchemaMapper      $schemaMapper   Schema mapper.
     * @param AggregationRunner $runner         Aggregation runner.
     * @param IUserSession      $userSession    User session.
     * @param LoggerInterface   $logger         Logger.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly AggregationRunner $runner,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Execute a named aggregation for a register+schema.
     *
     * The aggregation definition is read from the schema's
     * `configuration.x-openregister-aggregations` array.
     * The X-OR-Cache response header indicates whether the result came from cache.
     *
     * @param string $register Register slug or UUID.
     * @param string $schema   Schema slug or UUID.
     * @param string $name     Named aggregation identifier.
     *
     * @return JSONResponse Aggregation result with backend attribution, or error.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/aggregations-backend-native/tasks.md#task-6
     */
    public function aggregate(string $register, string $schema, string $name): JSONResponse
    {
        try {
            $registerObj = $this->registerMapper->find(id: $register);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                data: ['message' => 'Register not found: '.$register],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        try {
            $schemaObj = $this->schemaMapper->find(id: $schema);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                data: ['message' => 'Schema not found: '.$schema],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        // Look up the named aggregation definition from the schema configuration.
        $configuration = $schemaObj->getConfiguration() ?? [];
        $definitions   = $configuration['x-openregister-aggregations'] ?? [];
        $definition    = null;

        foreach ($definitions as $def) {
            if (($def['name'] ?? '') === $name) {
                $definition = $def;
                break;
            }
        }

        if ($definition === null) {
            return new JSONResponse(
                data: ['message' => 'Aggregation "'.$name.'" not found on schema "'.$schema.'"'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        $uid = $this->userSession->getUser()?->getUID() ?? 'anonymous';

        try {
            $query = AggregationQuery::create(
                metric: $definition['metric'] ?? 'count',
                field: $definition['field'] ?? null,
                filter: $definition['filters'] ?? [],
                groupBy: $definition['groupBy'] ?? null
            );
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(
                data: ['message' => 'Invalid aggregation definition: '.$e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        $result = $this->runner->run(
            query: $query,
            register: $registerObj,
            schema: $schemaObj,
            name: $name,
            uid: $uid
        );

        $response = new JSONResponse(data: $result->toArray());
        $response->addHeader(name: 'X-OR-Cache', value: $result->cached === true ? 'hit' : 'miss');

        return $response;
    }//end aggregate()
}//end class
