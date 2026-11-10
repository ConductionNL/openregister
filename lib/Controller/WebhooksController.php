<?php

/**
 * OpenRegister Webhooks Controller
 *
 * Controller for handling webhook management operations.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/webhook-payload-mapping/spec.md
 * @spec openspec/specs/webhook-payload-mapping/spec.md
 * @spec openspec/specs/event-driven-architecture/spec.md
 * @spec openspec/specs/webhook-payload-mapping/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use DateTime;
use GuzzleHttp\Exception\GuzzleException;
use OCA\OpenRegister\Controller\Support\WebhooksControllerServices;
use OCA\OpenRegister\Controller\Support\WebhookLogQuery;
use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Db\Webhook;
use OCA\OpenRegister\Db\WebhookLog;
use OCA\OpenRegister\Db\WebhookLogMapperInterface;
use OCA\OpenRegister\Db\WebhookMapperInterface;
use OCA\OpenRegister\Service\WebhookEvent;
use OCA\OpenRegister\Service\WebhookDelivery;
use OCA\OpenRegister\Service\WebhookService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * WebhooksController handles webhook management operations
 *
 * @category Controller
 * @package  OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The classes over the limit are the
 *   unsaved Webhook entity the mapping preview builds from the dialog's fields and
 *   the WebhookLog a retry reads its payload from.
 *
 * @spec openspec/specs/webhook-payload-mapping/spec.md
 */
class WebhooksController extends Controller {

	/**
	 * The webhook event types a subscription can name, with their display metadata.
	 *
	 * @var list<array{class: string, name: string, description: string, category: string, type: string, properties: list<string>}>
	 */
	private const EVENT_CATALOGUE = [
		// Object events - Before events (ing).
		[
			'class' => 'OCA\OpenRegister\Event\ObjectCreatingEvent',
			'name' => 'Object Creating',
			'description' => 'Triggered before an object is created',
			'category' => 'Object',
			'type' => 'before',
			'properties' => ['object'],
		],
		[
			'class' => 'OCA\OpenRegister\Event\ObjectUpdatingEvent',
			'name' => 'Object Updating',
			'description' => 'Triggered before an object is updated',
			'category' => 'Object',
			'type' => 'before',
			'properties' => ['newObject', 'oldObject'],
		],
		[
			'class' => 'OCA\OpenRegister\Event\ObjectDeletingEvent',
			'name' => 'Object Deleting',
			'description' => 'Triggered before an object is deleted',
			'category' => 'Object',
			'type' => 'before',
			'properties' => ['object'],
		],
		// Object events - After events (ed).
		[
			'class' => 'OCA\OpenRegister\Event\ObjectCreatedEvent',
			'name' => 'Object Created',
			'description' => 'Triggered after an object is created',
			'category' => 'Object',
			'type' => 'after',
			'properties' => ['object'],
		],
		[
			'class' => 'OCA\OpenRegister\Event\ObjectUpdatedEvent',
			'name' => 'Object Updated',
			'description' => 'Triggered after an object is updated',
			'category' => 'Object',
			'type' => 'after',
			'properties' => ['newObject', 'oldObject'],
		],
		[
			'class' => 'OCA\OpenRegister\Event\ObjectDeletedEvent',
			'name' => 'Object Deleted',
			'description' => 'Triggered after an object is deleted',
			'category' => 'Object',
			'type' => 'after',
			'properties' => ['object'],
		],
		[
			'class' => 'OCA\OpenRegister\Event\ObjectLockedEvent',
			'name' => 'Object Locked',
			'description' => 'Triggered when an object is locked',
			'category' => 'Object',
			'type' => 'after',
			'properties' => ['object'],
		],
		[
			'class' => 'OCA\OpenRegister\Event\ObjectUnlockedEvent',
			'name' => 'Object Unlocked',
			'description' => 'Triggered when an object is unlocked',
			'category' => 'Object',
			'type' => 'after',
			'properties' => ['object'],
		],
		[
			'class' => 'OCA\OpenRegister\Event\ObjectRevertedEvent',
			'name' => 'Object Reverted',
			'description' => 'Triggered when an object is reverted',
			'category' => 'Object',
			'type' => 'after',
			'properties' => ['object', 'revertPoint'],
		],

		// Register events.
		[
			'class' => 'OCA\OpenRegister\Event\RegisterCreatedEvent',
			'name' => 'Register Created',
			'description' => 'Triggered after a register is created',
			'category' => 'Register',
			'type' => 'after',
			'properties' => ['register'],
		],
		[
			'class' => 'OCA\OpenRegister\Event\RegisterUpdatedEvent',
			'name' => 'Register Updated',
			'description' => 'Triggered after a register is updated',
			'category' => 'Register',
			'type' => 'after',
			'properties' => ['newRegister', 'oldRegister'],
		],
		[
			'class' => 'OCA\OpenRegister\Event\RegisterDeletedEvent',
			'name' => 'Register Deleted',
			'description' => 'Triggered after a register is deleted',
			'category' => 'Register',
			'type' => 'after',
			'properties' => ['register'],
		],

		// Schema events.
		[
			'class' => 'OCA\OpenRegister\Event\SchemaCreatedEvent',
			'name' => 'Schema Created',
			'description' => 'Triggered after a schema is created',
			'category' => 'Schema',
			'type' => 'after',
			'properties' => ['schema'],
		],
		[
			'class' => 'OCA\OpenRegister\Event\SchemaUpdatedEvent',
			'name' => 'Schema Updated',
			'description' => 'Triggered after a schema is updated',
			'category' => 'Schema',
			'type' => 'after',
			'properties' => ['newSchema', 'oldSchema'],
		],
		[
			'class' => 'OCA\OpenRegister\Event\SchemaDeletedEvent',
			'name' => 'Schema Deleted',
			'description' => 'Triggered after a schema is deleted',
			'category' => 'Schema',
			'type' => 'after',
			'properties' => ['schema'],
		],

		// Application events.
		[
			'class' => 'OCA\OpenRegister\Event\ApplicationCreatedEvent',
			'name' => 'Application Created',
			'description' => 'Triggered after an application is created',
			'category' => 'Application',
			'type' => 'after',
			'properties' => ['application'],
		],
		[
			'class' => 'OCA\OpenRegister\Event\ApplicationUpdatedEvent',
			'name' => 'Application Updated',
			'description' => 'Triggered after an application is updated',
			'category' => 'Application',
			'type' => 'after',
			'properties' => ['newApplication', 'oldApplication'],
		],
		[
			'class' => 'OCA\OpenRegister\Event\ApplicationDeletedEvent',
			'name' => 'Application Deleted',
			'description' => 'Triggered after an application is deleted',
			'category' => 'Application',
			'type' => 'after',
			'properties' => ['application'],
		],

		// Agent events.
		[
			'class' => 'OCA\OpenRegister\Event\AgentCreatedEvent',
			'name' => 'Agent Created',
			'description' => 'Triggered after an agent is created',
			'category' => 'Agent',
			'type' => 'after',
			'properties' => ['agent'],
		],
		[
			'class' => 'OCA\OpenRegister\Event\AgentUpdatedEvent',
			'name' => 'Agent Updated',
			'description' => 'Triggered after an agent is updated',
			'category' => 'Agent',
			'type' => 'after',
			'properties' => ['newAgent', 'oldAgent'],
		],
		[
			'class' => 'OCA\OpenRegister\Event\AgentDeletedEvent',
			'name' => 'Agent Deleted',
			'description' => 'Triggered after an agent is deleted',
			'category' => 'Agent',
			'type' => 'after',
			'properties' => ['agent'],
		],

		// Source events.
		[
			'class' => 'OCA\OpenRegister\Event\SourceCreatedEvent',
			'name' => 'Source Created',
			'description' => 'Triggered after a source is created',
			'category' => 'Source',
			'type' => 'after',
			'properties' => ['source'],
		],
		[
			'class' => 'OCA\OpenRegister\Event\SourceUpdatedEvent',
			'name' => 'Source Updated',
			'description' => 'Triggered after a source is updated',
			'category' => 'Source',
			'type' => 'after',
			'properties' => ['newSource', 'oldSource'],
		],
		[
			'class' => 'OCA\OpenRegister\Event\SourceDeletedEvent',
			'name' => 'Source Deleted',
			'description' => 'Triggered after a source is deleted',
			'category' => 'Source',
			'type' => 'after',
			'properties' => ['source'],
		],

		// Configuration events.
		[
			'class' => 'OCA\OpenRegister\Event\ConfigurationCreatedEvent',
			'name' => 'Configuration Created',
			'description' => 'Triggered after a configuration is created',
			'category' => 'Configuration',
			'type' => 'after',
			'properties' => ['configuration'],
		],
		[
			'class' => 'OCA\OpenRegister\Event\ConfigurationUpdatedEvent',
			'name' => 'Configuration Updated',
			'description' => 'Triggered after a configuration is updated',
			'category' => 'Configuration',
			'type' => 'after',
			'properties' => ['newConfiguration', 'oldConfiguration'],
		],
		[
			'class' => 'OCA\OpenRegister\Event\ConfigurationDeletedEvent',
			'name' => 'Configuration Deleted',
			'description' => 'Triggered after a configuration is deleted',
			'category' => 'Configuration',
			'type' => 'after',
			'properties' => ['configuration'],
		],

		// View events.
		[
			'class' => 'OCA\OpenRegister\Event\ViewCreatedEvent',
			'name' => 'View Created',
			'description' => 'Triggered after a view is created',
			'category' => 'View',
			'type' => 'after',
			'properties' => ['view'],
		],
		[
			'class' => 'OCA\OpenRegister\Event\ViewUpdatedEvent',
			'name' => 'View Updated',
			'description' => 'Triggered after a view is updated',
			'category' => 'View',
			'type' => 'after',
			'properties' => ['newView', 'oldView'],
		],
		[
			'class' => 'OCA\OpenRegister\Event\ViewDeletedEvent',
			'name' => 'View Deleted',
			'description' => 'Triggered after a view is deleted',
			'category' => 'View',
			'type' => 'after',
			'properties' => ['view'],
		],

		// Conversation events.
		[
			'class' => 'OCA\OpenRegister\Event\ConversationCreatedEvent',
			'name' => 'Conversation Created',
			'description' => 'Triggered after a conversation is created',
			'category' => 'Conversation',
			'type' => 'after',
			'properties' => ['conversation'],
		],
		[
			'class' => 'OCA\OpenRegister\Event\ConversationUpdatedEvent',
			'name' => 'Conversation Updated',
			'description' => 'Triggered after a conversation is updated',
			'category' => 'Conversation',
			'type' => 'after',
			'properties' => ['newConversation', 'oldConversation'],
		],
		[
			'class' => 'OCA\OpenRegister\Event\ConversationDeletedEvent',
			'name' => 'Conversation Deleted',
			'description' => 'Triggered after a conversation is deleted',
			'category' => 'Conversation',
			'type' => 'after',
			'properties' => ['conversation'],
		],

		// Organisation events.
		[
			'class' => 'OCA\OpenRegister\Event\OrganisationCreatedEvent',
			'name' => 'Organisation Created',
			'description' => 'Triggered after an organisation is created',
			'category' => 'Organisation',
			'type' => 'after',
			'properties' => ['organisation'],
		],
		[
			'class' => 'OCA\OpenRegister\Event\OrganisationUpdatedEvent',
			'name' => 'Organisation Updated',
			'description' => 'Triggered after an organisation is updated',
			'category' => 'Organisation',
			'type' => 'after',
			'properties' => ['newOrganisation', 'oldOrganisation'],
		],
		[
			'class' => 'OCA\OpenRegister\Event\OrganisationDeletedEvent',
			'name' => 'Organisation Deleted',
			'description' => 'Triggered after an organisation is deleted',
			'category' => 'Organisation',
			'type' => 'after',
			'properties' => ['organisation'],
		],
	];

	/**
	 * Webhook mapper
	 *
	 * @var WebhookMapperInterface
	 */
	private WebhookMapperInterface $webhookMapper;

	/**
	 * Webhook service
	 *
	 * @var WebhookService
	 */
	private WebhookService $webhookService;

	/**
	 * Webhook log mapper
	 *
	 * @var WebhookLogMapperInterface
	 */
	private WebhookLogMapperInterface $webhookLogMapper;

	/**
	 * Logger
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * User session for authorization gating on webhook write endpoints.
	 *
	 * @var IUserSession|null
	 */
	private ?IUserSession $userSession;

	/**
	 * Group manager for admin checks on webhook write endpoints.
	 *
	 * @var IGroupManager|null
	 */
	private ?IGroupManager $groupManager;

	/**
	 * Constructor
	 *
	 * @param string $appName Application name
	 * @param IRequest $request HTTP request
	 * @param WebhooksControllerServices $services The webhooks controller services.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		WebhooksControllerServices $services,
	) {
		parent::__construct(appName: $appName, request: $request);
		$this->webhookMapper = $services->mappers->webhookMapper;
		$this->webhookLogMapper = $services->mappers->webhookLogMapper;
		$this->webhookService = $services->mappers->webhookService;
		$this->logger = $services->mappers->logger;
		$this->userSession = $services->platform->userSession;
		$this->groupManager = $services->platform->groupManager;
	}//end __construct()

	/**
	 * Whether the current user is a Nextcloud administrator.
	 *
	 * Used to gate all webhook write endpoints (create, update, destroy, test,
	 * retry). Webhooks are an admin-only feature because they grant the holder
	 * the ability to direct outbound HTTP requests from the server — see C9 in
	 * the wave-3 security triage. The dependencies are constructor-injected as
	 * optional only so existing test scaffolding keeps working; in real DI both
	 * are always available, so the absent-dependency branch fails closed.
	 *
	 * @return bool True if the signed-in user is in the admin group.
	 */
	private function isCurrentUserAdmin(): bool {
		if ($this->userSession === null || $this->groupManager === null) {
			return false;
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		return $this->groupManager->isAdmin($user->getUID());
	}//end isCurrentUserAdmin()

	/**
	 * Build a 403 response for callers without admin rights.
	 *
	 * @return JSONResponse JSON 403 response with a generic error message.
	 */
	private function forbiddenResponse(): JSONResponse {
		return new JSONResponse(
			data: [
				'error' => 'Administrator privileges are required to manage webhooks',
			],
			statusCode: 403
		);
	}//end forbiddenResponse()

	/**
	 * The 404 answer for a webhook id that does not exist.
	 *
	 * @return JSONResponse The refusal.
	 */
	private function webhookNotFound(): JSONResponse {
		return new JSONResponse(data: ['error' => 'Webhook not found'], statusCode: 404);
	}//end webhookNotFound()

	/**
	 * Log a failed webhook action as an error and answer 500.
	 *
	 * @param string               $logMessage The error line to log.
	 * @param array<string, mixed> $context    The log context after the file and line.
	 * @param array<string, mixed> $data       The 500 response body.
	 *
	 * @return JSONResponse The 500 response.
	 */
	private function loggedFailure(string $logMessage, array $context, array $data): JSONResponse {
		$this->logger->error(message: $logMessage, context: array_merge(['file' => __FILE__, 'line' => __LINE__], $context));

		return new JSONResponse(data: $data, statusCode: 500);
	}//end loggedFailure()

	/**
	 * List all webhooks
	 *
	 * Returns a JSON response containing an array of all webhooks in the system.
	 * Supports pagination, filtering, and extended properties.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @psalm-return JSONResponse<
	 *     200|500,
	 *     array{
	 *         error?: 'Failed to list webhooks',
	 *         results?: array<array<string, mixed>>,
	 *         total?: int<0, max>
	 *     },
	 *     array<never, never>
	 * >
	 *
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Complex request parameter handling for flexible API
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): JSONResponse {
		// Webhook config listing is admin-only (matches the write endpoints).
		if ($this->isCurrentUserAdmin() === false) {
			return $this->forbiddenResponse();
		}

		try {
			// Get request parameters for filtering and searching.
			$params = $this->request->getParams();

			// Extract pagination and search parameters.
			$limit = null;
			if (isset($params['_limit']) === true) {
				$limit = (int)$params['_limit'];
			}

			$offset = null;
			if (isset($params['_offset']) === true) {
				$offset = (int)$params['_offset'];
			}

			$page = null;
			if (isset($params['_page']) === true) {
				$page = (int)$params['_page'];
			}

			// Extract extend parameter for additional properties.
			$extend = $params['_extend'] ?? [];
			if (is_string($extend) === true) {
				$extend = [$extend];
			}

			// Convert page to offset if provided (page-based pagination).
			if ($page !== null && $limit !== null) {
				$offset = ($page - 1) * $limit;
			}

			// Extract filters from request parameters.
			$filters = $params['filters'] ?? [];

			// Retrieve webhooks using mapper with pagination and filters.
			$webhooks = $this->webhookMapper->findAll(
				limit: $limit,
				offset: $offset,
				filters: $filters
			);

			// Get total count for pagination (without limit/offset but with filters).
			$allWebhooks = $this->webhookMapper->findAll(
				limit: null,
				offset: null,
				filters: $filters
			);
			$total = count($allWebhooks);

			// Serialize webhooks to arrays.
			$webhooksArr = array_map(
				function ($webhook) {
					return $webhook->jsonSerialize();
				},
				$webhooks
			);

			return new JSONResponse(
				data: [
					'results' => $webhooksArr,
					'total' => $total,
				],
				statusCode: 200
			);
		} catch (\Exception $e) {
			return $this->loggedFailure(
				logMessage: '[WebhooksController] Error listing webhooks: ' . $e->getMessage(),
				context: [
					'trace' => $e->getTraceAsString(),
				],
				data: [
					'error' => 'Failed to list webhooks',
				]
			);
		}//end try
	}//end index()

	/**
	 * Get a single webhook
	 *
	 * @param int $id Webhook ID
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @psalm-return JSONResponse<
	 *     200,
	 *     \OCA\OpenRegister\Db\Webhook,
	 *     array<never, never>
	 * >|JSONResponse<
	 *     404|500,
	 *     array{error: 'Failed to retrieve webhook'|'Webhook not found'},
	 *     array<never, never>
	 * >
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(int $id): JSONResponse {
		// Reading a webhook's config (target URL, org, filters) is admin-only.
		if ($this->isCurrentUserAdmin() === false) {
			return $this->forbiddenResponse();
		}

		try {
			$webhook = $this->webhookMapper->find($id);

			return new JSONResponse(data: $webhook);
		} catch (DoesNotExistException $e) {
			return $this->webhookNotFound();
		} catch (\Exception $e) {
			return $this->loggedFailure(
				logMessage: '[WebhooksController] Error retrieving webhook: ' . $e->getMessage(),
				context: [
					'id' => $id,
					'trace' => $e->getTraceAsString(),
				],
				data: [
					'error' => 'Failed to retrieve webhook',
				]
			);
		}//end try
	}//end show()

	/**
	 * Create a new webhook
	 *
	 * @return JSONResponse JSON response with created webhook
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function create(): JSONResponse {
		// Webhooks let the holder direct outbound HTTP from the server,
		// so write access is admin-only (wave-3 C10).
		if ($this->isCurrentUserAdmin() === false) {
			return $this->forbiddenResponse();
		}

		try {
			$data = $this->request->getParams();

			// Remove internal parameters (starting with '_').
			foreach (array_keys($data) as $key) {
				if (str_starts_with($key, '_') === true) {
					unset($data[$key]);
				}
			}

			// Remove ID if present to ensure a new record is created.
			if (($data['id'] ?? null) !== null) {
				unset($data['id']);
			}

			// Remove organisation from request data - it will be set automatically
			// by setOrganisationOnCreate() in the mapper based on the active session.
			// This prevents frontend from overriding the organisation assignment.
			unset($data['organisation']);

			// Validate required fields.
			if (empty($data['name']) === true || empty($data['url']) === true) {
				return new JSONResponse(
					data: [
						'error' => 'Name and URL are required',
					],
					statusCode: 400
				);
			}

			$webhook = $this->webhookMapper->createFromArray($data);

			$this->logger->info(
				message: '[WebhooksController] Webhook created',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'id' => $webhook->getId(),
					'name' => $webhook->getName(),
					'url' => $webhook->getUrl(),
				]
			);

			return new JSONResponse(data: $webhook, statusCode: 201);
		} catch (\Exception $e) {
			return $this->loggedFailure(
				logMessage: '[WebhooksController] Error creating webhook: ' . $e->getMessage(),
				context: [
					'fields' => array_keys($this->request->getParams()),
					'trace' => $e->getTraceAsString(),
				],
				data: [
					'error' => 'Failed to create webhook: ' . $e->getMessage(),
				]
			);
		}//end try
	}//end create()

	/**
	 * Update an existing webhook
	 *
	 * @param int $id Webhook ID
	 *
	 * @return JSONResponse JSON response with updated webhook
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function update(int $id): JSONResponse {
		// Webhook reconfiguration is admin-only (wave-3 C10).
		if ($this->isCurrentUserAdmin() === false) {
			return $this->forbiddenResponse();
		}

		try {
			$data = $this->request->getParams();

			// Remove ID from data if present.
			unset($data['id']);

			$webhook = $this->webhookMapper->updateFromArray(id: $id, data: $data);

			$this->logger->info(
				message: '[WebhooksController] Webhook updated',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'id' => $webhook->getId(),
					'name' => $webhook->getName(),
				]
			);

			return new JSONResponse(data: $webhook);
		} catch (DoesNotExistException $e) {
			return $this->webhookNotFound();
		} catch (\Exception $e) {
			return $this->loggedFailure(
				logMessage: '[WebhooksController] Error updating webhook: ' . $e->getMessage(),
				context: [
					'id' => $id,
					'fields' => array_keys($this->request->getParams()),
					'trace' => $e->getTraceAsString(),
				],
				data: [
					'error' => 'Failed to update webhook: ' . $e->getMessage(),
				]
			);
		}//end try
	}//end update()

	/**
	 * Delete a webhook
	 *
	 * @param int $id Webhook ID
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @psalm-return JSONResponse<
	 *     204,
	 *     null,
	 *     array<never, never>
	 * >|JSONResponse<
	 *     404|500,
	 *     array{error: 'Failed to delete webhook'|'Webhook not found'},
	 *     array<never, never>
	 * >
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function destroy(int $id): JSONResponse {
		// Webhook deletion is admin-only (wave-3 C10).
		if ($this->isCurrentUserAdmin() === false) {
			return $this->forbiddenResponse();
		}

		try {
			$webhook = $this->webhookMapper->find($id);
			$this->webhookMapper->delete($webhook);

			$this->logger->info(
				message: '[WebhooksController] Webhook deleted',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'id' => $webhook->getId(),
					'name' => $webhook->getName(),
				]
			);

			return new JSONResponse(data: null, statusCode: 204);
		} catch (DoesNotExistException $e) {
			return $this->webhookNotFound();
		} catch (\Exception $e) {
			return $this->loggedFailure(
				logMessage: '[WebhooksController] Error deleting webhook: ' . $e->getMessage(),
				context: [
					'id' => $id,
					'trace' => $e->getTraceAsString(),
				],
				data: [
					'error' => 'Failed to delete webhook',
				]
			);
		}//end try
	}//end destroy()

	/**
	 * Preview the payload a webhook would send, with the mapping the dialog holds
	 *
	 * Body: the webhook fields as the dialog holds them (`mapping`,
	 * `configuration`), `event` (an event class, default object created) and
	 * `payload` (default a sample created object). Nothing is saved or sent.
	 *
	 * @return JSONResponse `{payload, mapped, event}`
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function preview(): JSONResponse {
		// A mapping can read any field of the event, so the preview is as
		// privileged as editing the webhook.
		if ($this->isCurrentUserAdmin() === false) {
			return $this->forbiddenResponse();
		}

		$data = $this->request->getParams();
		$eventName = (string)($data['event'] ?? 'OCA\OpenRegister\Event\ObjectCreatedEvent');
		$payload = $data['payload'] ?? null;
		if (is_array($payload) === false) {
			$payload = $this->samplePayload();
		}

		$webhook = new Webhook();
		$webhook->hydrate(
			[
				'mapping' => ($data['mapping'] ?? null),
				'configuration' => ($data['configuration'] ?? []),
			]
		);

		$preview = $this->webhookService->previewPayload(webhook: $webhook, event: new WebhookEvent(name: $eventName, payload: $payload));

		return new JSONResponse(data: $preview + ['event' => $eventName]);
	}//end preview()

	/**
	 * A created object, in the shape the webhook listener hands a delivery
	 *
	 * @return array
	 */
	private function samplePayload(): array {
		$uuid = '00000000-0000-4000-8000-000000000000';

		return [
			'objectType' => 'object',
			'action' => 'create',
			'object' => ['id' => $uuid, 'title' => 'Sample object'],
			'objectUuid' => $uuid,
			'register' => null,
			'schema' => null,
			'timestamp' => date('c'),
		];
	}//end samplePayload()

	/**
	 * Test a webhook by sending a test payload
	 *
	 * @param int $id Webhook ID
	 *
	 * @return JSONResponse JSON response with test result
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function test(int $id): JSONResponse {
		// The /test endpoint actually fires an outbound HTTP request to the
		// configured URL, so it triggers the same SSRF surface as a normal
		// delivery. Gate it identically to the other write endpoints
		// (wave-3 C10).
		if ($this->isCurrentUserAdmin() === false) {
			return $this->forbiddenResponse();
		}

		try {
			$webhook = $this->webhookMapper->find($id);

			$testPayload = [
				'test' => true,
				'message' => 'This is a test webhook from OpenRegister',
				'timestamp' => date('c'),
			];

			$success = $this->webhookService->deliverWebhook(
				new WebhookDelivery(webhook: $webhook, event: new WebhookEvent(name: 'OCA\OpenRegister\Event\TestEvent', payload: $testPayload), attempt: 1)
			);

			if ($success === true) {
				return new JSONResponse(
					data: [
						'success' => true,
						'message' => 'Test webhook delivered successfully',
					]
				);
			}

			return $this->failedDeliveryResponse(webhookId: $id, message: 'Test webhook delivery failed');
		} catch (DoesNotExistException $e) {
			return $this->webhookNotFound();
		} catch (GuzzleException $e) {
			return $this->loggedFailure(
				logMessage: '[WebhooksController] Error testing webhook: ' . $e->getMessage(),
				context: [
					'id' => $id,
					'trace' => $e->getTraceAsString(),
				],
				data: [
					'success' => false,
					'message' => 'Test webhook delivery failed: ' . $e->getMessage(),
				]
			);
		} catch (\Exception $e) {
			return $this->loggedFailure(
				logMessage: '[WebhooksController] Error testing webhook: ' . $e->getMessage(),
				context: [
					'id' => $id,
					'trace' => $e->getTraceAsString(),
				],
				data: [
					'error' => 'Failed to test webhook: ' . $e->getMessage(),
				]
			);
		}//end try
	}//end test()

	/**
	 * List available events with metadata
	 *
	 * @return JSONResponse JSON response with available events
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 * @no-admin-idor-exempt No per-object resource: returns the static catalogue of available webhook event-type definitions
	 *   (identical for every install); no tenant data.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function events(): JSONResponse {
		$events = self::EVENT_CATALOGUE;

		return new JSONResponse(
			data: [
				'events' => $events,
				'total' => count($events),
			]
		);
	}//end events()

	/**
	 * Get logs for a specific webhook
	 *
	 * @param int $id Webhook ID
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @psalm-return JSONResponse<
	 *     200|404|500,
	 *     array{
	 *         error?: 'Failed to retrieve webhook logs'|'Webhook not found',
	 *         results?: list<\OCA\OpenRegister\Db\WebhookLog>,
	 *         total?: int<0, max>
	 *     },
	 *     array<never, never>
	 * >
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function logs(int $id): JSONResponse {
		// Delivery logs carry unmasked request/response bodies — admin-only.
		if ($this->isCurrentUserAdmin() === false) {
			return $this->forbiddenResponse();
		}

		try {
			// Validate webhook exists by attempting to find it.
			$this->webhookMapper->find($id);

			$limit = (int)($this->request->getParam('limit') ?? 50);
			$offset = (int)($this->request->getParam('offset') ?? 0);

			$logs = $this->webhookLogMapper->findByWebhook(webhookId: $id, page: new ListQuery(limit: $limit, offset: $offset));

			return new JSONResponse(
				data: [
					'results' => $logs,
					'total' => count($logs),
				],
				statusCode: 200
			);
		} catch (DoesNotExistException $e) {
			return $this->webhookNotFound();
		} catch (\Exception $e) {
			return $this->loggedFailure(
				logMessage: '[WebhooksController] Error retrieving webhook logs: ' . $e->getMessage(),
				context: [
					'id' => $id,
					'trace' => $e->getTraceAsString(),
				],
				data: [
					'error' => 'Failed to retrieve webhook logs',
				]
			);
		}//end try
	}//end logs()

	/**
	 * Get statistics for a specific webhook
	 *
	 * @param int $id Webhook ID
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @psalm-return JSONResponse<200|404|500,
	 *     array{error?: 'Failed to retrieve webhook log statistics'|
	 *     'Webhook not found', total?: int, successful?: int, failed?: int,
	 *     pendingRetries?: int<0, max>}, array<never, never>>
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function logStats(int $id): JSONResponse {
		// Delivery-log statistics are admin-only.
		if ($this->isCurrentUserAdmin() === false) {
			return $this->forbiddenResponse();
		}

		try {
			// Validate webhook exists by attempting to find it.
			$this->webhookMapper->find($id);
			$stats = $this->webhookLogMapper->getStatistics($id);

			// Count pending retries.
			$now = new DateTime();
			$pendingRetries = count($this->webhookLogMapper->findFailedForRetry($now));

			$stats['pendingRetries'] = $pendingRetries;

			return new JSONResponse(data: $stats);
		} catch (DoesNotExistException $e) {
			return $this->webhookNotFound();
		} catch (\Exception $e) {
			return $this->loggedFailure(
				logMessage: '[WebhooksController] Error retrieving webhook log statistics: ' . $e->getMessage(),
				context: [
					'id' => $id,
					'trace' => $e->getTraceAsString(),
				],
				data: [
					'error' => 'Failed to retrieve webhook log statistics',
				]
			);
		}//end try
	}//end logStats()

	/**
	 * Get all webhook logs with optional filtering
	 *
	 * @return JSONResponse JSON response with webhook logs
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function allLogs(): JSONResponse {
		// Instance-wide delivery logs are admin-only.
		if ($this->isCurrentUserAdmin() === false) {
			return $this->forbiddenResponse();
		}

		try {
			return new JSONResponse(data: $this->logPage(query: $this->readLogQuery()), statusCode: 200);
		} catch (\Exception $e) {
			return $this->logsFailed(e: $e);
		}
	}//end allLogs()

	/**
	 * Read the webhook, page and outcome filters of a log listing.
	 *
	 * A webhook id of null, '' or '0' lists every webhook; an outcome other
	 * than 'true', '1', 'false' or '0' lists both outcomes.
	 *
	 * @return WebhookLogQuery The query.
	 */
	private function readLogQuery(): WebhookLogQuery {
		$query     = new WebhookLogQuery();
		$webhookId = $this->request->getParam('webhook_id');
		if ($webhookId !== null && $webhookId !== '' && $webhookId !== '0') {
			$query->webhookId = (int)$webhookId;
		}

		$query->limit  = (int)($this->request->getParam('limit') ?? 50);
		$query->offset = (int)($this->request->getParam('offset') ?? 0);
		$success       = $this->request->getParam('success');
		if (in_array($success, ['true', '1', 'false', '0'], true) === true) {
			$query->success = $success === 'true' || $success === '1';
		}

		return $query;
	}//end readLogQuery()

	/**
	 * One page of delivery logs and the total the filters match.
	 *
	 * @param WebhookLogQuery $query The query.
	 *
	 * @return array{results: array, total: int} The page.
	 */
	private function logPage(WebhookLogQuery $query): array {
		$logs  = $this->webhookLogMapper->findAll(limit: $query->limit, offset: $query->offset);
		$total = count($this->webhookLogMapper->findAll(limit: null, offset: null));
		if ($query->webhookId !== null) {
			$logs  = $this->webhookLogMapper->findByWebhook(webhookId: $query->webhookId, page: new ListQuery(limit: $query->limit, offset: $query->offset));
			$total = count($this->webhookLogMapper->findByWebhook(webhookId: $query->webhookId, page: ListQuery::unbounded()));
		}

		if ($query->success !== null) {
			$logs  = $this->withOutcome(logs: $logs, success: $query->success);
			$total = $this->outcomeTotal(query: $query);
		}

		return ['results' => $logs, 'total' => $total];
	}//end logPage()

	/**
	 * The number of logs, of every webhook or the query's one, with the query's outcome.
	 *
	 * @param WebhookLogQuery $query The query; its outcome is set.
	 *
	 * @return int The total.
	 */
	private function outcomeTotal(WebhookLogQuery $query): int {
		$total = count($this->withOutcome(logs: $this->webhookLogMapper->findAll(limit: null, offset: null), success: $query->success));
		if ($query->webhookId !== null) {
			$all   = $this->webhookLogMapper->findByWebhook(webhookId: $query->webhookId, page: ListQuery::unbounded());
			$total = count($this->withOutcome(logs: $all, success: $query->success));
		}

		return $total;
	}//end outcomeTotal()

	/**
	 * The logs with the given outcome, re-indexed.
	 *
	 * @param array $logs    The logs.
	 * @param bool  $success The outcome.
	 *
	 * @return array The matching logs.
	 */
	private function withOutcome(array $logs, bool $success): array {
		return array_values(array_filter($logs, fn ($log) => $log->getSuccess() === $success));
	}//end withOutcome()

	/**
	 * Log a failed listing and answer 500.
	 *
	 * @param \Exception $e The failure.
	 *
	 * @return JSONResponse The 500 response.
	 */
	private function logsFailed(\Exception $e): JSONResponse {
		return $this->loggedFailure(
			logMessage: '[WebhooksController] Error retrieving webhook logs: ' . $e->getMessage(),
			context: ['trace' => $e->getTraceAsString()],
			data: ['error' => 'Failed to retrieve webhook logs: ' . $e->getMessage()]
		);
	}//end logsFailed()

	/**
	 * Retry a failed webhook delivery
	 *
	 * @param int $logId Log entry ID
	 *
	 * @return JSONResponse JSON response with retry result
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function retry(int $logId): JSONResponse {
		// The retry() endpoint re-fires an outbound webhook delivery, so it
		// shares the outbound-HTTP surface of test(). Admin-only (wave-3 C10).
		if ($this->isCurrentUserAdmin() === false) {
			return $this->forbiddenResponse();
		}

		try {
			return $this->retryLog(logId: $logId);
		} catch (DoesNotExistException $e) {
			$this->logger->error(
				message: '[WebhooksController] Webhook log not found for retry: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'log_id' => $logId, 'trace' => $e->getTraceAsString()]
			);
			return new JSONResponse(data: ['error' => 'Webhook log not found'], statusCode: 404);
		} catch (\Exception $e) {
			return $this->loggedFailure(
				logMessage: '[WebhooksController] Error retrying webhook: ' . $e->getMessage(),
				context: ['log_id' => $logId, 'trace' => $e->getTraceAsString()],
				data: ['error' => 'Failed to retry webhook']
			);
		}
	}//end retry()

	/**
	 * Retry the delivery a failed log entry records.
	 *
	 * The log entity's property is named `webhook`, so its id is read with getWebhook().
	 *
	 * @param int $logId Log entry ID
	 *
	 * @return JSONResponse The retry result, or 400 for a successful delivery
	 *
	 * @throws DoesNotExistException When no log entry has this id; retry() answers 404.
	 */
	private function retryLog(int $logId): JSONResponse {
		$log = $this->webhookLogMapper->find($logId);
		if ($log->getSuccess() === true) {
			return new JSONResponse(data: ['error' => 'Cannot retry a successful webhook delivery'], statusCode: 400);
		}

		$webhook = $this->webhookMapper->find($log->getWebhook());
		return $this->redeliver(log: $log, webhook: $webhook);
	}//end retryLog()

	/**
	 * Deliver a logged payload again, as the next attempt; the original event data when the payload wraps it.
	 *
	 * @param WebhookLog $log     The failed delivery's log entry.
	 * @param Webhook    $webhook The webhook it was delivered to.
	 *
	 * @return JSONResponse The retry result, or 400 when the log holds no payload
	 */
	private function redeliver(WebhookLog $log, Webhook $webhook): JSONResponse {
		$payload = $this->retryPayload(log: $log);
		if (empty($payload) === true) {
			return new JSONResponse(data: ['error' => 'No payload available for retry'], statusCode: 400);
		}

		$success = $this->webhookService->deliverWebhook(
			new WebhookDelivery(
				webhook: $webhook,
				event: new WebhookEvent(name: $log->getEventClass(), payload: $payload['data'] ?? $payload),
				attempt: $log->getAttempt() + 1
			)
		);
		if ($success === true) {
			return new JSONResponse(data: ['success' => true, 'message' => 'Webhook retry delivered successfully']);
		}

		return $this->failedDeliveryResponse(webhookId: $webhook->getId(), message: 'Webhook retry delivery failed');
	}//end redeliver()

	/**
	 * The payload a failed delivery is retried with: the logged request body, else the stored payload.
	 *
	 * @param WebhookLog $log The failed delivery's log entry.
	 *
	 * @return array<mixed> The payload; empty when the log holds none.
	 */
	private function retryPayload(WebhookLog $log): array {
		if ($log->getRequestBody() !== null) {
			$decoded = json_decode($log->getRequestBody() ?? '{}', true);
			if (is_array($decoded) === true) {
				return $decoded;
			}

			return [];
		}

		if ($log->getPayload() !== null) {
			return $log->getPayloadArray();
		}

		return [];
	}//end retryPayload()

	/**
	 * The 500 response for a delivery that failed, with the error the webhook's newest log entry recorded.
	 *
	 * @param int    $webhookId The webhook that was delivered.
	 * @param string $message   The message when the log entry records none.
	 *
	 * @return JSONResponse The error response.
	 */
	private function failedDeliveryResponse(int $webhookId, string $message): JSONResponse {
		$latestLogs = $this->webhookLogMapper->findByWebhook(webhookId: $webhookId, page: new ListQuery(limit: 1, offset: 0));
		$responseData = [
			'success' => false,
			'message' => $message,
		];

		if (empty($latestLogs) === false) {
			$latestLog = $latestLogs[0];
			if ($latestLog->getErrorMessage() !== null) {
				$responseData['message'] = $latestLog->getErrorMessage();
			}

			if ($latestLog->getStatusCode() !== null) {
				$responseData['error_details'] = [
					'status_code' => $latestLog->getStatusCode(),
					'response_body' => $latestLog->getResponseBody(),
				];
			}
		}

		return new JSONResponse(
			data: $responseData,
			statusCode: 500
		);
	}//end failedDeliveryResponse()
}//end class
