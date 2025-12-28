<?php

/**
 * ApplicationTool
 *
 * LLphant function tool for AI agents to manage applications.
 * Provides CRUD operations for applications with RBAC enforcement.
 *
 * @category Tool
 * @package  OCA\OpenRegister\Tool
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tool;

use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Db\Application;
use OCA\OpenRegister\Db\ApplicationMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * ApplicationTool
 *
 * Provides function calling capabilities for AI agents to perform CRUD operations on applications.
 * All operations respect the agent's configured views, RBAC permissions, and organisation boundaries.
 *
 * @package OCA\OpenRegister\Tool
 */
class ApplicationTool extends AbstractTool implements ToolInterface
{
    /**
     * Application mapper for database operations
     *
     * @var ApplicationMapper
     */
    private ApplicationMapper $applicationMapper;

    /**
     * ApplicationTool constructor
     *
     * @param ApplicationMapper $applicationMapper Application mapper instance
     * @param IUserSession      $userSession       User session
     * @param LoggerInterface   $logger            Logger instance
     */
    public function __construct(
        ApplicationMapper $applicationMapper,
        IUserSession $userSession,
        LoggerInterface $logger
    ) {
        parent::__construct($userSession, $logger);
        $this->applicationMapper = $applicationMapper;
    }//end __construct()

    /**
     * Get the tool name
     *
     * @return string Tool name
     *
     * @psalm-return 'Application Management'
     */
    public function getName(): string
    {
        return 'Application Management';
    }//end getName()

    /**
     * Get the tool description
     *
     * @return string Tool description for LLM
     *
     * @psalm-return 'Manage applications: list, view, create, update, or delete applications with RBAC permissions and organisation boundaries.'
     */
    public function getDescription(): string
    {
        return 'Manage applications: list, view, create, update, or delete applications with RBAC permissions and organisation boundaries.';
    }//end getDescription()

    /**
     * Get function definitions for LLM function calling
     *
     * Returns function definitions in OpenAI function calling format.
     * These are used by LLMs to understand what capabilities this tool provides.
     *
     * @return (((string|string[])[]|string)[]|string)[][]
     *
     * @psalm-return list{array{name: 'list_applications', description: 'List all accessible applications. Returns basic information. Use filters to narrow results.', parameters: array{type: 'object', properties: array{limit: array{type: 'integer', description: 'Maximum number of results to return (default: 50)'}, offset: array{type: 'integer', description: 'Number of results to skip for pagination (default: 0)'}}, required: array<never, never>}}, array{name: 'get_application', description: 'Get detailed application information by UUID. Returns name, description, metadata, and configuration.', parameters: array{type: 'object', properties: array{uuid: array{type: 'string', description: 'UUID of the application to retrieve'}}, required: list{'uuid'}}}, array{name: 'create_application', description: 'Create a new application. Requires unique name. Can include description, metadata, and configuration.', parameters: array{type: 'object', properties: array{name: array{type: 'string', description: 'Name of the application (required)'}, description: array{type: 'string', description: 'Description of what the application does'}, domain: array{type: 'string', description: 'Domain or URL where the application is hosted'}}, required: list{'name'}}}, array{name: 'update_application', description: 'Update application (owner/update permission required). Provide UUID and fields to update.', parameters: array{type: 'object', properties: array{uuid: array{type: 'string', description: 'UUID of the application to update'}, name: array{type: 'string', description: 'New name for the application'}, description: array{type: 'string', description: 'New description'}, domain: array{type: 'string', description: 'New domain or URL'}}, required: list{'uuid'}}}, array{name: 'delete_application', description: 'Permanently delete application (owner/delete permission required). Cannot be undone.', parameters: array{type: 'object', properties: array{uuid: array{type: 'string', description: 'UUID of the application to delete'}}, required: list{'uuid'}}}}
     */
    public function getFunctions(): array
    {
        return [
            [
                'name'        => 'list_applications',
                'description' => 'List all accessible applications. Returns basic information. Use filters to narrow results.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'limit'  => [
                            'type'        => 'integer',
                            'description' => 'Maximum number of results to return (default: 50)',
                        ],
                        'offset' => [
                            'type'        => 'integer',
                            'description' => 'Number of results to skip for pagination (default: 0)',
                        ],
                    ],
                    'required'   => [],
                ],
            ],
            [
                'name'        => 'get_application',
                'description' => 'Get detailed application information by UUID. Returns name, description, metadata, and configuration.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'uuid' => [
                            'type'        => 'string',
                            'description' => 'UUID of the application to retrieve',
                        ],
                    ],
                    'required'   => ['uuid'],
                ],
            ],
            [
                'name'        => 'create_application',
                'description' => 'Create a new application. Requires unique name. Can include description, metadata, and configuration.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'name'        => [
                            'type'        => 'string',
                            'description' => 'Name of the application (required)',
                        ],
                        'description' => [
                            'type'        => 'string',
                            'description' => 'Description of what the application does',
                        ],
                        'domain'      => [
                            'type'        => 'string',
                            'description' => 'Domain or URL where the application is hosted',
                        ],
                    ],
                    'required'   => ['name'],
                ],
            ],
            [
                'name'        => 'update_application',
                'description' => 'Update application (owner/update permission required). Provide UUID and fields to update.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'uuid'        => [
                            'type'        => 'string',
                            'description' => 'UUID of the application to update',
                        ],
                        'name'        => [
                            'type'        => 'string',
                            'description' => 'New name for the application',
                        ],
                        'description' => [
                            'type'        => 'string',
                            'description' => 'New description',
                        ],
                        'domain'      => [
                            'type'        => 'string',
                            'description' => 'New domain or URL',
                        ],
                    ],
                    'required'   => ['uuid'],
                ],
            ],
            [
                'name'        => 'delete_application',
                'description' => 'Permanently delete application (owner/delete permission required). Cannot be undone.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'uuid' => [
                            'type'        => 'string',
                            'description' => 'UUID of the application to delete',
                        ],
                    ],
                    'required'   => ['uuid'],
                ],
            ],
        ];
    }//end getFunctions()

    /**
     * List applications
     *
     * @param int $limit  Maximum number of results (default: 50)
     * @param int $offset Offset for pagination (default: 0)
     *
     * @return (bool|mixed|string)[] Response with applications list
     *
     * @psalm-return array{success: bool, error?: string, details?: mixed, message?: string, data?: mixed}
     */
    public function listApplications(int $limit = 50, int $offset = 0): array
    {
        try {
            $this->logger->info(
                '[ApplicationTool] Listing applications',
                [
                        'limit'  => $limit,
                        'offset' => $offset,
                    ]
            );

            // Get applications via mapper (RBAC is enforced in mapper).
            $applications = $this->applicationMapper->findAll(limit: $limit, offset: $offset);
            $total        = $this->applicationMapper->countAll();

            // Convert to array.
            $results = array_map(fn ($app) => $app->jsonSerialize(), $applications);

            return $this->formatSuccess(
                data: [
                        'applications' => $results,
                        'total'        => $total,
                        'limit'        => $limit,
                        'offset'       => $offset,
                    ],
                message: "Found {$total} applications."
            );
        } catch (\Exception $e) {
            $this->logger->error(
                '[ApplicationTool] Failed to list applications',
                [
                        'error' => $e->getMessage(),
                    ]
            );
            return $this->formatError(message: 'Failed to list applications: ' . $e->getMessage());
        }//end try
    }//end listApplications()

    /**
     * Get application details
     *
     * @param string $uuid Application UUID
     *
     * @return (bool|mixed|string)[] Response with application details
     *
     * @psalm-return array{success: bool, error?: string, details?: mixed, message?: string, data?: mixed}
     */
    public function getApplication(string $uuid): array
    {
        try {
            $this->logger->info('[ApplicationTool] Getting application', ['uuid' => $uuid]);

            // Find application (RBAC enforced in mapper).
            $application = $this->applicationMapper->findByUuid(uuid: $uuid);

            return $this->formatSuccess(
                data: $application->jsonSerialize(),
                message: "Application '{$application->getName()}' retrieved successfully."
            );
        } catch (DoesNotExistException $e) {
            return $this->formatError(message: "Application with UUID '{$uuid}' not found.");
        } catch (\Exception $e) {
            $this->logger->error(
                '[ApplicationTool] Failed to get application',
                [
                        'uuid'  => $uuid,
                        'error' => $e->getMessage(),
                    ]
            );
            return $this->formatError(message: 'Failed to get application: ' . $e->getMessage());
        }//end try
    }//end getApplication()

    /**
     * Create application
     *
     * @param string      $name        Application name
     * @param string|null $description Application description
     * @param string|null $_domain     Application domain/URL (unused, kept for API compatibility)
     *
     * @return (bool|mixed|string)[] Response with created application
     *
     * @psalm-return array{success: bool, error?: string, details?: mixed, message?: string, data?: mixed}
     */
    public function createApplication(
        string $name,
        ?string $description = null,
        ?string $_domain = null
    ): array {
        try {
            $this->logger->info('[ApplicationTool] Creating application', ['name' => $name]);

            // Create application entity.
            $application = new Application();
            $application->setName($name);
            if ($description !== null && $description !== '') {
                $application->setDescription($description);
            }

            // Save via mapper (RBAC and organisation are enforced in mapper).
            $application = $this->applicationMapper->insert($application);

            return $this->formatSuccess(
                data: $application->jsonSerialize(),
                message: "Application '{$name}' created successfully with UUID {$application->getUuid()}."
            );
        } catch (\Exception $e) {
            $this->logger->error(
                '[ApplicationTool] Failed to create application',
                [
                        'name'  => $name,
                        'error' => $e->getMessage(),
                    ]
            );
            return $this->formatError(message: 'Failed to create application: ' . $e->getMessage());
        }//end try
    }//end createApplication()

    /**
     * Update application
     *
     * @param string      $uuid        Application UUID
     * @param string|null $name        New name
     * @param string|null $description New description
     * @param string|null $_domain     New domain (unused, kept for API compatibility)
     *
     * @return (bool|mixed|string)[] Response with updated application
     *
     * @psalm-return array{success: bool, error?: string, details?: mixed, message?: string, data?: mixed}
     */
    public function updateApplication(
        string $uuid,
        ?string $name = null,
        ?string $description = null,
        ?string $_domain = null
    ): array {
        try {
            $this->logger->info('[ApplicationTool] Updating application', ['uuid' => $uuid]);

            // Find application (RBAC enforced in mapper).
            $application = $this->applicationMapper->findByUuid(uuid: $uuid);

            // Update fields.
            if ($name !== null) {
                $application->setName($name);
            }

            if ($description !== null) {
                $application->setDescription($description);
            }

            // Save changes (RBAC enforced in mapper).
            $application = $this->applicationMapper->update($application);

            return $this->formatSuccess(
                data: $application->jsonSerialize(),
                message: "Application updated successfully."
            );
        } catch (DoesNotExistException $e) {
            return $this->formatError(message: "Application with UUID '{$uuid}' not found.");
        } catch (\Exception $e) {
            $this->logger->error(
                '[ApplicationTool] Failed to update application',
                [
                        'uuid'  => $uuid,
                        'error' => $e->getMessage(),
                    ]
            );
            return $this->formatError(message: 'Failed to update application: ' . $e->getMessage());
        }//end try
    }//end updateApplication()

    /**
     * Delete application
     *
     * @param string $uuid Application UUID
     *
     * @return (bool|mixed|string)[] Response confirming deletion
     *
     * @psalm-return array{success: bool, error?: string, details?: mixed, message?: string, data?: mixed}
     */
    public function deleteApplication(string $uuid): array
    {
        try {
            $this->logger->info('[ApplicationTool] Deleting application', ['uuid' => $uuid]);

            // Find application (RBAC enforced in mapper).
            $application = $this->applicationMapper->findByUuid(uuid: $uuid);
            $name        = $application->getName();

            // Delete (RBAC enforced in mapper).
            $this->applicationMapper->delete($application);

            return $this->formatSuccess(
                data: ['uuid' => $uuid],
                message: "Application '{$name}' deleted successfully."
            );
        } catch (DoesNotExistException $e) {
            return $this->formatError(message: "Application with UUID '{$uuid}' not found.");
        } catch (\Exception $e) {
            $this->logger->error(
                '[ApplicationTool] Failed to delete application',
                [
                        'uuid'  => $uuid,
                        'error' => $e->getMessage(),
                    ]
            );
            return $this->formatError(message: 'Failed to delete application: ' . $e->getMessage());
        }//end try
    }//end deleteApplication()

    /**
     * Execute a function by name
     *
     * @param string      $functionName Name of the function to execute
     * @param array       $parameters   Function parameters
     * @param string|null $userId       User ID for session context (optional)
     *
     * @return array Response
     */
    public function executeFunction(string $functionName, array $parameters, ?string $userId = null): array
    {
        // Convert snake_case to camelCase for PSR compliance.
        $methodName = lcfirst(str_replace('_', '', ucwords($functionName, '_')));

        // Call the method directly (LLPhant-compatible).
        return $this->$methodName(...array_values($parameters));
    }//end executeFunction()
}//end class
