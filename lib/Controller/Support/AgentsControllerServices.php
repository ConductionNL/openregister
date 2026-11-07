<?php

/**
 * OpenRegister agents controller services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Kernel\Contracts\AgentMapperInterface;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCA\OpenRegister\Mcp\Service\ToolRegistryInterface;
use Psr\Log\LoggerInterface;

/**
 * The services of the agents API.
 *
 * @spec openspec/specs/chat-ai/spec.md
 */
final class AgentsControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param AgentMapperInterface         $agentMapper         Agent mapper for database operations
	 * @param OrganisationServiceInterface $organisationService Organisation service for multi-tenancy
	 * @param ToolRegistryInterface        $toolRegistry        Tool registry for available tools
	 * @param LoggerInterface     $logger              Logger for error tracking
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function __construct(
		public readonly AgentMapperInterface $agentMapper,
		public readonly OrganisationServiceInterface $organisationService,
		public readonly ToolRegistryInterface $toolRegistry,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
