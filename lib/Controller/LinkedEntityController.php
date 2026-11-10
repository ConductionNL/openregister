<?php

/**
 * LinkedEntityController
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Controller
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 *
 * @spec openspec/specs/linked-entity-types/spec.md#requirement-reverse-lookup-across-tables
 * @spec openspec/specs/linked-entity-types/spec.md#requirement-remove-link-entities-and-mappers
 * @spec openspec/specs/linked-entity-types/spec.md
 * @spec openspec/specs/linked-entity-types/spec.md
 */

namespace OCA\OpenRegister\Controller;

use Exception;
use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Controller\Support\LinkedEntityControllerServices;
use OCA\OpenRegister\Controller\Support\LinkRemoval;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\EntityLink;
use OCA\OpenRegister\Service\LinkedEntityServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Generic controller for managing linked Nextcloud entities on objects and entities.
 *
 * Replaces per-type controllers (EmailsController, etc.) with a unified API.
 *
 * @category Controller
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/specs/linked-entity-types/spec.md
 */
class LinkedEntityController extends Controller {
	/**
	 * The linked entity service
	 *
	 * @var LinkedEntityServiceInterface
	 */
	private readonly LinkedEntityServiceInterface $linkedEntityService;

	/**
	 * Logger
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Active user session for caller identity
	 *
	 * @var IUserSession
	 */
	private readonly IUserSession $userSession;

	/**
	 * Group manager for admin checks
	 *
	 * @var IGroupManager
	 */
	private readonly IGroupManager $groupManager;

	/**
	 * Constructor for LinkedEntityController.
	 *
	 * @param IRequest $request The request object
	 * @param LinkedEntityControllerServices $services The controller's collaborators.
	 */
	public function __construct(
		IRequest $request,
		LinkedEntityControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->linkedEntityService = $services->linkedEntityService;
		$this->logger = $services->session->logger;
		$this->userSession = $services->session->userSession;
		$this->groupManager = $services->session->groupManager;
	}//end __construct()

	/**
	 * Check whether the currently authenticated user is a Nextcloud administrator.
	 *
	 * Register/schema link mutations change register/schema configuration, and
	 * reverseLookup scans across all tenants (RBAC/multitenancy intentionally
	 * disabled — see LinkedEntityService, TODO #1273); both are admin-only.
	 *
	 * @return bool True if a user is signed in and belongs to the admin group.
	 */
	private function isCurrentUserAdmin(): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		return $this->groupManager->isAdmin($user->getUID());
	}//end isCurrentUserAdmin()

	// SEC-CTRL-8: CSRF protection retained — this is an SPA-called authenticated write
	// (axios sends the CSRF token); #[NoCSRFRequired] removed.

	/**
	 * Add a linked entity to an object.
	 *
	 * POST /api/objects/{uuid}/_linked/{type}
	 *
	 * @param string $uuid The object UUID
	 * @param string $type The linked entity type (mail, contacts, etc.)
	 *
	 * @return JSONResponse The updated linked IDs
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	#[NoAdminRequired]
	public function addObjectLink(string $uuid, string $type): JSONResponse {
		try {
			$body = $this->request->getParams();
			$entityId = $body['id'] ?? null;

			if ($entityId === null || $entityId === '') {
				return new JSONResponse(['error' => 'Missing required field: id'], 400);
			}

			$result = $this->linkedEntityService->addLink(objectUuid: $uuid, link: new EntityLink(type: $type, entityId: (string)$entityId));

			return new JSONResponse(['_' . $type => $result]);
		} catch (NotAuthorizedException $e) {
			// SEC-CTRL-4: write-permission denial maps to 403.
			return new JSONResponse(['error' => $e->getMessage()], 403);
		} catch (Exception $e) {
			$this->logger->error(
				'[LinkedEntityController] addObjectLink failed',
				['uuid' => $uuid, 'type' => $type, 'error' => $e->getMessage()]
			);

			return new JSONResponse(['error' => $e->getMessage()], 400);
		}//end try
	}//end addObjectLink()

	// SEC-CTRL-8: CSRF protection retained — SPA-called authenticated write; #[NoCSRFRequired] removed.

	/**
	 * Remove a linked entity from an object.
	 *
	 * DELETE /api/objects/{uuid}/_linked/{type}/{entityId}
	 *
	 * @param string $uuid The object UUID
	 * @param string $type The linked entity type
	 * @param string $entityId The entity ID to remove
	 *
	 * @return JSONResponse The updated linked IDs
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md#requirement-remove-link-entities-and-mappers
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	#[NoAdminRequired]
	public function removeObjectLink(string $uuid, string $type, string $entityId): JSONResponse {
		return $this->removeLink(removal: new LinkRemoval(objectUuid: $uuid, type: $type, entityId: $entityId));
	}//end removeObjectLink()

	/**
	 * Remove a linked entity from an object; a write denial is a 403, any other failure a logged 400.
	 *
	 * @param LinkRemoval $removal The object, the linked entity type and the entity.
	 *
	 * @return JSONResponse The updated linked IDs
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md#requirement-remove-link-entities-and-mappers
	 */
	private function removeLink(LinkRemoval $removal): JSONResponse {
		try {
			$result = $this->linkedEntityService->removeLink($removal->objectUuid, $removal->type, $removal->entityId);

			return new JSONResponse(['_' . $removal->type => $result]);
		} catch (NotAuthorizedException $e) {
			// SEC-CTRL-4: write-permission denial maps to 403.
			return new JSONResponse(['error' => $e->getMessage()], 403);
		} catch (Exception $e) {
			$this->logger->error(
				'[LinkedEntityController] removeObjectLink failed',
				['uuid' => $removal->objectUuid, 'type' => $removal->type, 'entityId' => $removal->entityId, 'error' => $e->getMessage()]
			);

			return new JSONResponse(['error' => $e->getMessage()], 400);
		}
	}//end removeLink()

	// SEC-CTRL-8: CSRF protection retained — SPA-called authenticated write; #[NoCSRFRequired] removed.

	/**
	 * Add a linked entity to a register.
	 *
	 * POST /api/registers/{uuid}/_linked/{type}
	 *
	 * @param string $uuid The register UUID
	 * @param string $type The linked entity type
	 *
	 * @return JSONResponse The updated linked IDs
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	#[NoAdminRequired]
	public function addRegisterLink(string $uuid, string $type): JSONResponse {
		// SEC-CTRL: admin-only — mutates register configuration.
		if ($this->isCurrentUserAdmin() === false) {
			return new JSONResponse(['error' => 'Admin privileges required'], 403);
		}

		try {
			$body = $this->request->getParams();
			$entityId = $body['id'] ?? null;

			if ($entityId === null || $entityId === '') {
				return new JSONResponse(['error' => 'Missing required field: id'], 400);
			}

			$result = $this->linkedEntityService->addLinkToRegister(registerUuid: $uuid, link: new EntityLink(type: $type, entityId: (string)$entityId));

			return new JSONResponse(['_' . $type => $result]);
		} catch (Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], 400);
		}
	}//end addRegisterLink()

	// SEC-CTRL-8: CSRF protection retained — SPA-called authenticated write; #[NoCSRFRequired] removed.

	/**
	 * Add a linked entity to a schema.
	 *
	 * POST /api/schemas/{uuid}/_linked/{type}
	 *
	 * @param string $uuid The schema UUID
	 * @param string $type The linked entity type
	 *
	 * @return JSONResponse The updated linked IDs
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	#[NoAdminRequired]
	public function addSchemaLink(string $uuid, string $type): JSONResponse {
		// SEC-CTRL: admin-only — mutates schema configuration.
		if ($this->isCurrentUserAdmin() === false) {
			return new JSONResponse(['error' => 'Admin privileges required'], 403);
		}

		try {
			$body = $this->request->getParams();
			$entityId = $body['id'] ?? null;

			if ($entityId === null || $entityId === '') {
				return new JSONResponse(['error' => 'Missing required field: id'], 400);
			}

			$result = $this->linkedEntityService->addLinkToSchema(schemaUuid: $uuid, link: new EntityLink(type: $type, entityId: (string)$entityId));

			return new JSONResponse(['_' . $type => $result]);
		} catch (Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], 400);
		}
	}//end addSchemaLink()

	/**
	 * Reverse lookup: find all objects and entities linked to a specific entity.
	 *
	 * GET /api/linked/{type}/{entityId}
	 *
	 * @param string $type The linked entity type (mail, contacts, etc.)
	 * @param string $entityId The entity ID to search for
	 *
	 * @return JSONResponse Array of linked objects and entities
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md#requirement-reverse-lookup-across-tables
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function reverseLookup(string $type, string $entityId): JSONResponse {
		// SEC-CTRL: admin-only — LinkedEntityService::reverseLookup scans magic
		// tables with RBAC + multitenancy intentionally disabled (cross-tenant;
		// TODO #1273). Until per-tenant scoping lands, restrict to admins so an
		// arbitrary user cannot enumerate cross-tenant object links by entity id.
		if ($this->isCurrentUserAdmin() === false) {
			return new JSONResponse(['error' => 'Admin privileges required'], 403);
		}

		try {
			$results = $this->linkedEntityService->reverseLookup($type, $entityId);

			return new JSONResponse(
				[
					'results' => $results,
					'total' => count($results),
				]
			);
		} catch (Exception $e) {
			$this->logger->error(
				'[LinkedEntityController] reverseLookup failed',
				['type' => $type, 'entityId' => $entityId, 'error' => $e->getMessage()]
			);

			return new JSONResponse(['error' => $e->getMessage()], 400);
		}
	}//end reverseLookup()
}//end class
