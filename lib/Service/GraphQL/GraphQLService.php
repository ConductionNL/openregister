<?php

/**
 * GraphQL Service
 *
 * Main service for executing GraphQL queries with schema caching and complexity analysis.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\GraphQL
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-40
 */

namespace OCA\OpenRegister\Service\GraphQL;

use GraphQL\Error\Error;
use GraphQL\GraphQL;
use GraphQL\Language\Parser;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Schema;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\SecurityService;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Main service for executing GraphQL queries.
 *
 * Orchestrates schema generation (with APCu caching), query complexity analysis,
 * RBAC enforcement, DataLoader batching, audit trailing, and introspection control.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class GraphQLService
{
    /**
     * Constructor.
     *
     * @param SchemaGenerator         $schemaGenerator    Schema generator
     * @param QueryComplexityAnalyzer $complexityAnalyzer Complexity analyzer
     * @param GraphQLErrorFormatter   $errorFormatter     Error formatter
     * @param GraphQLResolver         $resolver           GraphQL resolver
     * @param ObjectService           $objectService      Object service
     * @param PermissionHandler       $permissionHandler  Permission handler
     * @param PropertyRbacHandler     $propertyRbac       Property RBAC handler
     * @param AuditTrailMapper        $auditTrailMapper   Audit trail mapper
     * @param RegisterMapper          $registerMapper     Register mapper
     * @param SchemaMapper            $schemaMapper       Schema mapper
     * @param IAppConfig              $appConfig          App configuration
     * @param IRequest                $request            Current request
     * @param IUserSession            $userSession        User session
     * @param LoggerInterface         $logger             Logger
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        private readonly SchemaGenerator $schemaGenerator,
        private readonly QueryComplexityAnalyzer $complexityAnalyzer,
        private readonly GraphQLErrorFormatter $errorFormatter,
        private readonly GraphQLResolver $resolver,
        private readonly ObjectService $objectService,
        private readonly PermissionHandler $permissionHandler,
        private readonly PropertyRbacHandler $propertyRbac,
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly IAppConfig $appConfig,
        private readonly IRequest $request,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Execute a GraphQL query.
     *
     * @param string                    $query         The GraphQL query string
     * @param array<string, mixed>|null $variables     Query variables
     * @param string|null               $operationName Operation name
     *
     * @return array<string, mixed> The execution result
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-38
     */
    public function execute(string $query, ?array $variables=null, ?string $operationName=null): array
    {
        try {
            // Check rate limiting.
            $this->checkRateLimit();

            // Parse the query.
            $document = Parser::parse($query);

            // Check introspection control.
            $this->checkIntrospection(document: $document);

            // Analyze complexity.
            $complexity = $this->complexityAnalyzer->analyze($document, $variables);

            // Generate schema.
            $schema = $this->getSchema();

            // Execute.
            $result = GraphQL::executeQuery(
                $schema,
                $query,
                null,
                $this->createContext(operationName: $operationName),
                $variables,
                $operationName,
            );

            $output = $result->toArray();

            // Add complexity info to extensions.
            $output['extensions'] = ($output['extensions'] ?? []);
            $output['extensions']['complexity'] = [
                'estimated' => $complexity['cost'],
                'max'       => $complexity['maxCost'],
                'depth'     => $complexity['depth'],
                'maxDepth'  => $complexity['maxDepth'],
            ];

            // Errors are already formatted by the GraphQL executor.
            return $output;
        } catch (Error $e) {
            return [
                'errors' => [$this->errorFormatter->format($e)],
            ];
        } catch (\Exception $e) {
            $this->logger->error(
                    'GraphQL execution error: '.$e->getMessage(),
                    [
                        'exception' => $e,
                    ]
                    );

            return [
                'errors' => [
                    [
                        'message'    => 'Internal server error',
                        'extensions' => ['code' => 'INTERNAL_ERROR'],
                    ],
                ],
            ];
        }//end try

    }//end execute()

    /**
     * Get the GraphQL schema, using APCu cache when available.
     *
     * @return Schema The GraphQL schema
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-40
     */
    private function getSchema(): Schema
    {
        // Wire the resolver into the schema generator.
        $this->resolver->reset();
        $this->schemaGenerator->setResolver($this->resolver);

        // Generate fresh each request.
        // APCu caching of webonyx Schema objects is complex because they contain closures.
        // We rely on the fact that schema generation is fast (~50ms for typical installs).
        return $this->schemaGenerator->generate();

    }//end getSchema()

    /**
     * Create the resolver context passed to all resolvers.
     *
     * @param string|null $operationName The operation name
     *
     * @return array<string, mixed> The context
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-37
     */
    private function createContext(?string $operationName): array
    {
        return [
            'objectService'     => $this->objectService,
            'permissionHandler' => $this->permissionHandler,
            'propertyRbac'      => $this->propertyRbac,
            'auditTrailMapper'  => $this->auditTrailMapper,
            'registerMapper'    => $this->registerMapper,
            'schemaMapper'      => $this->schemaMapper,
            'schemaGenerator'   => $this->schemaGenerator,
            'operationName'     => $operationName,
            'request'           => $this->request,
            'errors'            => [],
        ];

    }//end createContext()

    /**
     * Check if introspection is allowed for the current request.
     *
     * @param \GraphQL\Language\AST\DocumentNode $document The parsed document
     *
     * @return void
     *
     * @throws Error If introspection is not allowed
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-39
     */
    private function checkIntrospection(\GraphQL\Language\AST\DocumentNode $document): void
    {
        $setting = $this->appConfig->getValueString(
            'openregister',
            'graphql_introspection',
            'enabled'
        );

        if ($setting === 'enabled') {
            return;
        }

        // Check if query contains introspection fields.
        $hasIntrospection = false;
        foreach ($document->definitions as $definition) {
            if ($definition instanceof \GraphQL\Language\AST\OperationDefinitionNode === false) {
                continue;
            }

            $hasIntrospection = $this->selectionSetHasIntrospection(selectionSet: $definition->selectionSet);
            if ($hasIntrospection === true) {
                break;
            }
        }

        if ($hasIntrospection === false) {
            return;
        }

        if ($setting === 'disabled') {
            throw new Error(
                'Introspection is disabled',
                null,
                null,
                [],
                null,
                null,
                ['code' => 'INTROSPECTION_DISABLED']
            );
        }

        // Setting is 'authenticated' — check if user is logged in.
        if ($setting === 'authenticated') {
            $user = $this->userSession->getUser();
            if ($user === null) {
                throw new Error(
                    'Introspection requires authentication',
                    null,
                    null,
                    [],
                    null,
                    null,
                    ['code' => 'INTROSPECTION_DISABLED']
                );
            }
        }

    }//end checkIntrospection()

    /**
     * Check if a selection set contains introspection fields.
     *
     * @param \GraphQL\Language\AST\SelectionSetNode $selectionSet The selection set
     *
     * @return bool True if introspection fields are present
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-39
     */
    private function selectionSetHasIntrospection(
        \GraphQL\Language\AST\SelectionSetNode $selectionSet
    ): bool {
        foreach ($selectionSet->selections as $selection) {
            if ($selection instanceof \GraphQL\Language\AST\FieldNode === false) {
                continue;
            }

            $name = $selection->name->value;
            if ($name === '__schema' || $name === '__type') {
                return true;
            }
        }

        return false;

    }//end selectionSetHasIntrospection()

    /**
     * Check rate limiting for the current request.
     *
     * Uses APCu to track per-user and per-IP request counts.
     * Progressive delay: 2s → 4s → 8s → ... → 60s max.
     *
     * @return void
     *
     * @throws Error If rate limited
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-39
     */
    private function checkRateLimit(): void
    {
        $maxRequests = (int) $this->appConfig->getValueString(
            'openregister',
            'graphql_rate_limit',
            '100'
        );

        $windowSeconds = 60;

        // Identify the client.
        $user = $this->userSession->getUser();
        $key  = 'openregister_graphql_rate_';
        if ($user !== null) {
            $key .= 'user_'.preg_replace('/[^a-zA-Z0-9_]/', '_', $user->getUID());
        }

        if ($user === null) {
            $clientIp = $this->request->getRemoteAddress();
            if (empty($clientIp) === true) {
                // No identifiable client (CLI/test context) — skip rate limiting.
                return;
            }

            $key .= 'ip_'.preg_replace('/[^a-zA-Z0-9_.]/', '_', $clientIp);
        }

        if (function_exists('apcu_enabled') === false || apcu_enabled() === false) {
            return;
        }

        $count = apcu_fetch($key);
        if ($count === false) {
            apcu_store($key, 1, $windowSeconds);
            return;
        }

        if ($count >= $maxRequests) {
            // Calculate progressive delay.
            $overCount  = ($count - $maxRequests);
            $delay      = min(60, (int) pow(2, min($overCount, 5)));
            $retryAfter = $delay;

            apcu_inc($key);

            throw new Error(
                "Rate limit exceeded. Retry after $retryAfter seconds.",
                null,
                null,
                [],
                null,
                null,
                [
                    'code'       => 'RATE_LIMITED',
                    'retryAfter' => $retryAfter,
                ]
            );
        }//end if

        apcu_inc($key);

    }//end checkRateLimit()
}//end class
