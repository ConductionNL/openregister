<?php

/**
 * Class McpController
 *
 * Controller for MCP (Model Context Protocol) discovery endpoints.
 * Provides AI agents with tiered API discovery for OpenRegister.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/mcp-discovery/spec.md#requirement-tier-1-discovery-catalog
 * @spec openspec/specs/mcp-discovery/spec.md#requirement-tier-2-capability-detail-with-live-data
 * @spec openspec/specs/mcp-discovery/spec.md
 * @spec openspec/specs/mcp-discovery/spec.md
 */

namespace OCA\OpenRegister\Controller;

use Exception;
use OCA\OpenRegister\Service\Authorization\GrantableRightsIndex;
use OCA\OpenRegister\Service\McpDiscoveryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * McpController provides tiered API discovery for AI agents
 *
 * Tier 1 (discover): Public, compact capability catalog.
 * Tier 2 (discoverCapability): Authenticated, detailed endpoint docs with live data.
 *
 * @psalm-suppress UnusedClass - Registered via routes.php
 */
class McpController extends Controller {

	/**
	 * MCP discovery service instance
	 *
	 * @var McpDiscoveryService
	 */
	private readonly McpDiscoveryService $mcpDiscoveryService;

	/**
	 * McpController constructor
	 *
	 * @param string $appName Application name
	 * @param IRequest $request Request object
	 * @param McpDiscoveryService  $mcpDiscoveryService  MCP discovery service
	 * @param GrantableRightsIndex $grantableRightsIndex The cached menu of rights that may be offered
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		McpDiscoveryService $mcpDiscoveryService,
		private readonly GrantableRightsIndex $grantableRightsIndex,
	) {
		parent::__construct(appName: $appName, request: $request);
		$this->mcpDiscoveryService = $mcpDiscoveryService;
	}//end __construct()

	/**
	 * Tier 1: Public discovery catalog
	 *
	 * Returns a compact overview of all capability areas with drill-down URLs.
	 * No authentication required.
	 *
	 * @return JSONResponse The discovery catalog
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @PublicPage
	 *
	 * @CORS
	 *
	 * @spec openspec/specs/mcp-discovery/spec.md#requirement-tier-1-discovery-catalog
	 * @spec openspec/specs/mcp-discovery/spec.md
	 */
	#[AnonRateLimit(limit: 60, period: 60)]
	public function discover(): JSONResponse {
		try {
			$catalog = $this->mcpDiscoveryService->getCatalog();
			return new JSONResponse(data: $catalog);
		} catch (Exception $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
		}
	}//end discover()

	/**
	 * Tier 2: Detailed capability discovery with live data
	 *
	 * Returns endpoint documentation and live context for a specific capability area.
	 * Requires authentication since it exposes live data.
	 *
	 * @param string $capability The capability ID to get details for
	 *
	 * @return JSONResponse The capability detail or 404 error
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 * @no-admin-idor-exempt Closed enum, no object: McpDiscoveryService::getCapabilityDetail maps the capability
	 *   name against a fixed hardcoded allowlist and returns 404 otherwise; no OpenRegister object lookup.
	 *
	 * @CORS
	 *
	 * @spec openspec/specs/mcp-discovery/spec.md#requirement-tier-2-capability-detail-with-live-data
	 * @spec openspec/specs/mcp-discovery/spec.md
	 */
	public function discoverCapability(string $capability): JSONResponse {
		try {
			$detail = $this->mcpDiscoveryService->getCapabilityDetail(capability: $capability);

			if ($detail === null) {
				return new JSONResponse(
					data: [
						'error' => 'Unknown capability: ' . $capability,
						'available' => $this->mcpDiscoveryService->getCapabilityIds(),
					],
					statusCode: 404
				);
			}

			return new JSONResponse(data: $detail);
		} catch (Exception $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
		}
	}//end discoverCapability()

	/**
	 * The menu of rights that exist to give.
	 *
	 * Every `(register, schema, action, source)` that MAY be offered to an
	 * agent, across every register and schema. Hermiq reads this when a person
	 * opens a permission screen, so the list is the set of rights they can pick
	 * from.
	 *
	 * 🔴 It lists what may be OFFERED, never what is HELD. An entry is a right
	 * that could be granted, not one anybody has — whether a specific agent
	 * holds it is resolved by Hermiq against that agent's own grants. Reading
	 * this endpoint therefore confers nothing.
	 *
	 * Authenticated, deliberately NOT public: it enumerates the agent-facing
	 * surface of every schema on the instance, which is structural metadata
	 * worth having before attacking one.
	 *
	 * @return JSONResponse The grantable rights.
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 * @no-admin-idor-exempt No object lookup: returns instance-wide schema metadata, not per-object data.
	 *
	 * @spec openspec/changes/declared-actions-and-mcp-scope/specs/declared-actions/spec.md
	 */
	public function grantableRights(): JSONResponse {
		try {
			$rights = $this->grantableRightsIndex->getIndex();

			return new JSONResponse(
				data: [
					'total' => count($rights),
					'results' => $rights,
				]
			);
		} catch (Exception $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
		}
	}//end grantableRights()
}//end class
