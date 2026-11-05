<?php

/**
 * OpenRegister Endpoint Service
 *
 * Service for handling endpoint execution and management.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use OCA\OpenRegister\Db\AgentMapper;
use OCA\OpenRegister\Db\Endpoint;
use OCA\OpenRegister\Db\EndpointLog;
use OCA\OpenRegister\Db\EndpointLogMapper;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * EndpointService handles endpoint execution and logging
 *
 * Service for executing external API endpoints and logging execution results.
 * Supports multiple endpoint target types (view, agent, webhook, register, schema).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class EndpointService {
	/**
	 * Target types that run no logic yet, with the name their placeholder response uses.
	 */
	private const PLACEHOLDER_TARGETS = [
		'view' => 'View',
		'webhook' => 'Webhook',
		'register' => 'Register',
		'schema' => 'Schema',
	];

	/**
	 * Endpoint log mapper
	 *
	 * Handles database operations for endpoint execution logs.
	 *
	 * @var EndpointLogMapper Endpoint log mapper instance
	 */
	private readonly EndpointLogMapper $endpointLogMapper;

	/**
	 * Logger
	 *
	 * Used for logging endpoint execution, errors, and debug information.
	 *
	 * @var LoggerInterface Logger instance
	 */
	private readonly LoggerInterface $logger;

	/**
	 * User session
	 *
	 * Provides current user context for permission checks.
	 *
	 * @var IUserSession User session instance
	 */
	private readonly IUserSession $userSession;

	/**
	 * Group manager
	 *
	 * Used for checking user group permissions for endpoint access.
	 *
	 * @var IGroupManager Group manager instance
	 */
	private readonly IGroupManager $groupManager;

	/**
	 * Constructor.
	 *
	 * WHY THIS EXISTS. All four properties above were declared `readonly` and
	 * never assigned — this class had no constructor — so every method that
	 * touched one died with
	 *
	 *   Typed property EndpointService::$userSession must not be accessed
	 *   before initialization
	 *
	 * That is a fatal, not a degraded path, and it covers the permission checks
	 * at lines 402/410/457 and the audit-log write at 487. Same defect as
	 * NotificationService and UploadService in this repo.
	 *
	 * @param EndpointLogMapper $endpointLogMapper Endpoint execution log mapper.
	 * @param LoggerInterface   $logger            Logger.
	 * @param IUserSession      $userSession       Current user context.
	 * @param IGroupManager     $groupManager      Group membership lookups.
	 * @param AgentMapper       $agentMapper       Agent lookups for agent-type endpoints.
	 */
	public function __construct(
		EndpointLogMapper $endpointLogMapper,
		LoggerInterface $logger,
		IUserSession $userSession,
		IGroupManager $groupManager,
		private readonly AgentMapper $agentMapper,
	) {
		$this->endpointLogMapper = $endpointLogMapper;
		$this->logger = $logger;
		$this->userSession = $userSession;
		$this->groupManager = $groupManager;
	}//end __construct()

	/**
	 * Test an endpoint by executing it with test data
	 *
	 * Executes endpoint with optional test data to verify endpoint configuration
	 * and functionality. Checks permissions before execution and logs results.
	 *
	 * @param Endpoint $endpoint The endpoint to test
	 * @param array<string, mixed> $testData Optional test data to use in execution
	 *
	 * @return array<string, mixed> Test result with success status, status code, response, and optional error
	 *
	 * @phpstan-return array{success: bool, statusCode: int, response: mixed, error?: string}
	 * @psalm-return   array{success: bool, statusCode: int, response: mixed, error?: string}
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	public function testEndpoint(Endpoint $endpoint, array $testData = []): array {
		try {
			// Step 1: Check if user has permission to execute this endpoint.
			// Validates user group membership and endpoint access permissions.
			if ($this->canExecuteEndpoint(endpoint: $endpoint) === false) {
				return [
					'success' => false,
					'statusCode' => 403,
					'response' => null,
					'error' => 'Access denied: You do not have permission to execute this endpoint.',
				];
			}

			return $this->runEndpointTest(endpoint: $endpoint, testData: $testData);
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[EndpointService] Error testing endpoint: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'endpoint_id' => $endpoint->getId(), 'trace' => $e->getTraceAsString()]
			);
			return $this->failure(statusCode: 500, error: $e->getMessage());
		}//end try
	}//end testEndpoint()

	/**
	 * Run an endpoint once with test data and log the call.
	 *
	 * @param Endpoint             $endpoint The endpoint to run
	 * @param array<string, mixed> $testData The test data to send
	 *
	 * @return array<string, mixed> The execution result
	 */
	private function runEndpointTest(Endpoint $endpoint, array $testData): array {
		$request = [
			'method' => $endpoint->getMethod() ?? 'GET',
			'path' => $endpoint->getEndpoint(),
			'data' => $testData,
			'headers' => [],
		];
		$result = $this->executeEndpoint(endpoint: $endpoint, request: $request);
		$this->logEndpointCall(endpoint: $endpoint, request: $request, result: $result);

		return $result;
	}//end runEndpointTest()

	/**
	 * Execute an endpoint with given request data
	 *
	 * Routes endpoint execution to appropriate handler based on target type.
	 * Supports multiple target types: view, agent, webhook, register, and schema.
	 *
	 * @param Endpoint $endpoint The endpoint to execute
	 * @param array<string, mixed> $request Request data containing method, path, data, and headers
	 *
	 * @return array<string, mixed> Execution result with success status, status code, response, and optional error
	 *
	 * @phpstan-return array{success: bool, statusCode: int, response: mixed, error?: string}
	 * @psalm-return   array{success: bool, statusCode: int, response: mixed, error?: string}
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	private function executeEndpoint(Endpoint $endpoint, array $request): array {
		$type = $endpoint->getTargetType();
		if ($type === 'agent') {
			return $this->executeAgentEndpoint(endpoint: $endpoint, request: $request);
		}

		if (isset(self::PLACEHOLDER_TARGETS[$type]) === true) {
			return [
				'success' => true,
				'statusCode' => 200,
				'response' => ['message' => self::PLACEHOLDER_TARGETS[$type] . ' endpoint executed (placeholder)'],
			];
		}

		return $this->failure(statusCode: 400, error: 'Unknown target type: ' . $type);
	}//end executeEndpoint()

	/**
	 * Execute an agent endpoint
	 *
	 * Validates that the referenced agent exists and that a message was
	 * supplied, then returns a 501 Not Implemented response.
	 *
	 * Deprecated: agent endpoint LLM execution (tool loading + chat calls)
	 * is not implemented here anymore; it is pending the agent-core
	 * migration to the standalone hermiq application.
	 *
	 * @param Endpoint $endpoint The endpoint to execute
	 * @param array $request Request data
	 *
	 * @return array Execution result
	 * @phpstan-return array{success: bool, statusCode: int, response: mixed, error?: string}
	 * @psalm-return   array{success: bool, statusCode: int, response: mixed, error?: string}
	 * @psalm-suppress UnusedParam - False positive: both parameters are used within the method.
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	private function executeAgentEndpoint(Endpoint $endpoint, array $request): array {
		try {
			return $this->agentEndpointResult(endpoint: $endpoint, request: $request);
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[EndpointService] Error executing agent endpoint: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'trace' => $e->getTraceAsString()]
			);

			return $this->failure(statusCode: 500, error: $e->getMessage());
		}//end try
	}//end executeAgentEndpoint()

	/**
	 * The result of an agent endpoint: the agent must exist and a message is required.
	 *
	 * @param Endpoint             $endpoint The agent endpoint
	 * @param array<string, mixed> $request  The request data
	 *
	 * @return array{success: bool, statusCode: int, response: mixed, error?: string}
	 *
	 * @throws \Exception When the agent cannot be found.
	 */
	private function agentEndpointResult(Endpoint $endpoint, array $request): array {
		$agentId = $endpoint->getTargetId();
		$this->logger->info(
			message: '[EndpointService] Executing agent endpoint',
			context: ['file' => __FILE__, 'line' => __LINE__, 'agentId' => $agentId]
		);

		// Find agent by UUID; the mapper throws when there is none, and the
		// catch in executeAgentEndpoint() turns that into the 500 envelope.
		$this->agentMapper->findByUuid($agentId);

		// Extract message from request.
		$message = $request['data']['message'] ?? $request['message'] ?? '';

		if (empty($message) === true) {
			return $this->failure(statusCode: 400, error: 'Message is required');
		}

		// Agent endpoint LLM execution (tool loading + chat calls) is
		// deprecated pending the agent-core migration to hermiq; return
		// a clean not-implemented response instead of calling an LLM.
		return $this->failure(statusCode: 501, error: 'Agent endpoint type is not implemented');
	}//end agentEndpointResult()

	/**
	 * The result of an endpoint run that failed.
	 *
	 * @param int    $statusCode The HTTP status to report.
	 * @param string $error      The error message.
	 *
	 * @return array{success: false, statusCode: int, response: null, error: string}
	 */
	private function failure(int $statusCode, string $error): array {
		return [
			'success' => false,
			'statusCode' => $statusCode,
			'response' => null,
			'error' => $error,
		];
	}//end failure()

	/**
	 * Check if the current user can execute an endpoint
	 *
	 * @param Endpoint $endpoint The endpoint to check
	 *
	 * @return bool True if user can execute, false otherwise
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Permission check has multiple user and group conditions
	 */
	private function canExecuteEndpoint(Endpoint $endpoint): bool {
		// Get current user.
		$user = $this->userSession->getUser();
		if ($user === null) {
			// No user logged in - check if endpoint allows public access.
			$groups = $endpoint->getGroups();
			return empty($groups);
		}

		// Get user's groups.
		$userGroups = $this->groupManager->getUserGroupIds($user);

		// Check if user is admin.
		if (in_array('admin', $userGroups) === true) {
			return true;
		}

		// Check endpoint groups configuration.
		$endpointGroups = $endpoint->getGroups();

		// If no groups defined, allow all authenticated users.
		if (empty($endpointGroups) === true) {
			return true;
		}

		// Check if user is in any of the allowed groups.
		foreach ($userGroups as $groupId) {
			if (in_array($groupId, $endpointGroups) === true) {
				return true;
			}
		}

		return false;
	}//end canExecuteEndpoint()

	/**
	 * Log an endpoint call
	 *
	 * @param Endpoint $endpoint The endpoint that was called
	 * @param array $request Request data
	 * @param array $result Result data
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	private function logEndpointCall(Endpoint $endpoint, array $request, array $result): void {
		try {
			$log = new EndpointLog();

			// Generate UUID.
			$log->setUuid(Uuid::v4()->toRfc4122());

			// Set endpoint ID.
			$log->setEndpointId($endpoint->getId());

			// Set user info.
			$user = $this->userSession->getUser();
			if ($user !== null) {
				$log->setUserId($user->getUID());
			}

			// Set request/response data.
			$log->setRequest($request);
			// POSITIONAL, deliberately. setResponse() is magic — EndpointLog
			// declares the property, not the method — so it goes through
			// Entity::__call(array $args), where a NAMED argument lands under
			// its name and $args[0] is never set. Every endpoint log was
			// storing a null response.
			$log->setResponse(
				[
					'statusCode' => $result['statusCode'],
					'body' => $result['response'],
				]
			);

			// Set status.
			$log->setStatusCode($result['statusCode']);
			$log->setStatusMessage($result['error'] ?? 'Success');

			// Set timestamps.
			$log->setCreated(new DateTime());

			// Set expiry (1 week from now).
			$expires = new DateTime();
			$expires->modify('+1 week');
			$log->setExpires($expires);

			// Calculate size.
			$log->calculateSize();

			// Insert log.
			$this->endpointLogMapper->insert($log);

			$this->logger->debug(
				message: '[EndpointService] Endpoint call logged',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'endpoint_id' => $endpoint->getId(),
					'status_code' => $result['statusCode'],
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[EndpointService] Error logging endpoint call: ' . $e->getMessage(),
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'endpoint_id' => $endpoint->getId(),
					'trace' => $e->getTraceAsString(),
				]
			);
		}//end try
	}//end logEndpointCall()
}//end class
