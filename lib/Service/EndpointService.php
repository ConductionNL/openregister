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
use OCA\OpenRegister\Kernel\Contracts\AgentMapperInterface;
use OCA\OpenRegister\Db\Endpoint;
use OCA\OpenRegister\Db\EndpointLog;
use OCA\OpenRegister\Db\EndpointLogMapperInterface;
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
class EndpointService implements EndpointServiceInterface {
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
	 * @var EndpointLogMapperInterface Endpoint log mapper instance
	 */
	private readonly EndpointLogMapperInterface $endpointLogMapper;

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
	 * Agent lookups for agent-type endpoints.
	 *
	 * @var AgentMapperInterface
	 */
	private readonly AgentMapperInterface $agentMapper;

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
	 * @param EndpointMappers $mappers Endpoint execution log and agent mappers.
	 * @param LoggerInterface   $logger            Logger.
	 * @param IUserSession      $userSession       Current user context.
	 * @param IGroupManager     $groupManager      Group membership lookups.
	 */
	public function __construct(
		EndpointMappers $mappers,
		LoggerInterface $logger,
		IUserSession $userSession,
		IGroupManager $groupManager,
	) {
		$this->agentMapper = $mappers->agentMapper;
		$this->endpointLogMapper = $mappers->endpointLogMapper;
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
			if ($this->canExecuteEndpoint(endpoint: $endpoint) === false) {
				return $this->failure(statusCode: 403, error: 'Access denied: You do not have permission to execute this endpoint.');
			}

			return $this->runEndpointTest(endpoint: $endpoint, testData: $testData);
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[EndpointService] Error testing endpoint: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'endpoint_id' => $endpoint->getId(), 'trace' => $e->getTraceAsString()]
			);
			return $this->failure(statusCode: 500, error: $e->getMessage());
		}
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
		$this->logEndpointCall(endpoint: $endpoint, exchange: new EndpointExchange(request: $request, result: $result));

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
		$user = $this->userSession->getUser();
		if ($user === null) {
			// Without a user only an endpoint open to everyone may run.
			return empty($endpoint->getGroups());
		}

		$userGroups = $this->groupManager->getUserGroupIds($user);
		if (in_array('admin', $userGroups) === true) {
			return true;
		}

		return $this->inAllowedGroup(userGroups: $userGroups, endpointGroups: $endpoint->getGroups());
	}//end canExecuteEndpoint()

	/**
	 * Whether a user in these groups may run an endpoint limited to those groups;
	 * an endpoint without groups is open to every authenticated user.
	 *
	 * @param array<int, string> $userGroups     The user's group ids.
	 * @param array|null         $endpointGroups The endpoint's allowed groups.
	 *
	 * @return bool
	 */
	private function inAllowedGroup(array $userGroups, ?array $endpointGroups): bool {
		if (empty($endpointGroups) === true) {
			return true;
		}

		foreach ($userGroups as $groupId) {
			if (in_array($groupId, $endpointGroups) === true) {
				return true;
			}
		}

		return false;
	}//end inAllowedGroup()

	/**
	 * Log an endpoint call
	 *
	 * @param Endpoint         $endpoint The endpoint that was called
	 * @param EndpointExchange $exchange The request and its result
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	private function logEndpointCall(Endpoint $endpoint, EndpointExchange $exchange): void {
		try {
			$this->endpointLogMapper->insert($this->endpointLog(endpoint: $endpoint, exchange: $exchange));
			$this->logger->debug(
				message: '[EndpointService] Endpoint call logged',
				context: ['file' => __FILE__, 'line' => __LINE__, 'endpoint_id' => $endpoint->getId(), 'status_code' => $exchange->result['statusCode']]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[EndpointService] Error logging endpoint call: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'endpoint_id' => $endpoint->getId(), 'trace' => $e->getTraceAsString()]
			);
		}
	}//end logEndpointCall()

	/**
	 * The log row of one endpoint call, kept for a week.
	 *
	 * @param Endpoint         $endpoint The endpoint that was called.
	 * @param EndpointExchange $exchange The request and its result.
	 *
	 * @return EndpointLog
	 */
	private function endpointLog(Endpoint $endpoint, EndpointExchange $exchange): EndpointLog {
		$log = $this->newEndpointLog();
		$log->setEndpointId($endpoint->getId());
		$user = $this->userSession->getUser();
		if ($user !== null) {
			$log->setUserId($user->getUID());
		}

		$log->setRequest($exchange->request);
		// POSITIONAL: setResponse() is magic (Entity::__call), where a named argument never reaches $args[0].
		$log->setResponse(['statusCode' => $exchange->result['statusCode'], 'body' => $exchange->result['response']]);
		$log->setStatusCode($exchange->result['statusCode']);
		$log->setStatusMessage($exchange->result['error'] ?? 'Success');
		$log->calculateSize();

		return $log;
	}//end endpointLog()

	/**
	 * A new log row with its uuid, created now and expiring in a week.
	 *
	 * @return EndpointLog
	 */
	private function newEndpointLog(): EndpointLog {
		$log = new EndpointLog();
		$log->setUuid(Uuid::v4()->toRfc4122());
		$log->setCreated(new DateTime());
		$log->setExpires((new DateTime())->modify('+1 week'));

		return $log;
	}//end newEndpointLog()

}//end class
