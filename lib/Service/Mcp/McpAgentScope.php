<?php

/**
 * MCP Agent Scope
 *
 * Holds an MCP session that declared an agent to that agent's tool grant.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Mcp;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Db\AgentMapper;
use OCA\OpenRegister\Service\Capability\ToolGrantResolver;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Binds an MCP session to an agent and holds every call on it to the agent's grant.
 *
 * A client that sends `agent: <uuid>` in `initialize` is that agent for the
 * rest of the session: `tools/list` shows only the tools `Agent.tools` grants
 * (resolved by the same ToolGrantResolver the chat uses), and `tools/call`
 * refuses any other tool, and any argument an argument-scoped grant pins,
 * BEFORE the tool runs. The grant is read from the agent on every call, so an
 * administrator who narrows an agent narrows its open sessions too.
 *
 * An agent that does not exist, is inactive or is not accessible to the
 * signed-in user is refused at `initialize`. It is never ignored: a caller that
 * asked to be narrowed must not silently get the user's full rights instead.
 *
 * A session that declares no agent keeps the signed-in user's rights, as before.
 *
 * @spec openspec/specs/agent-tool-governance/spec.md#requirement-an-agent-is-held-to-its-tool-grant-on-every-path
 */
class McpAgentScope {

	/**
	 * Same lifetime as the MCP session it belongs to.
	 */
	private const TTL = 3600;

	/**
	 * Cache of session id => agent uuid.
	 *
	 * @var ICache
	 */
	private ICache $cache;

	/**
	 * Constructor
	 *
	 * @param AgentMapper $agentMapper Agent lookup
	 * @param ToolGrantResolver $grantResolver Grant grammar (shared with the chat)
	 * @param McpToolsService $toolsService The MCP tool catalogue
	 * @param ICacheFactory $cacheFactory Cache factory
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly AgentMapper $agentMapper,
		private readonly ToolGrantResolver $grantResolver,
		private readonly McpToolsService $toolsService,
		ICacheFactory $cacheFactory,
		private readonly LoggerInterface $logger,
	) {
		$this->cache = $cacheFactory->createDistributed(prefix: 'openregister_mcp_agent');
	}//end __construct()

	/**
	 * Check that a user may act as an agent over MCP, before any session exists.
	 *
	 * @param string $agentUuid The agent the client declared
	 * @param string $userId The signed-in user
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the agent is unknown, inactive or not the user's to use
	 *
	 * @spec openspec/specs/agent-tool-governance/spec.md#requirement-an-agent-is-held-to-its-tool-grant-on-every-path
	 */
	public function assertUsable(string $agentUuid, string $userId): void {
		$this->loadAgent(agentUuid: $agentUuid, userId: $userId);
	}//end assertUsable()

	/**
	 * Bind an initialised session to an agent.
	 *
	 * @param string $sessionId The session initialize created
	 * @param string $agentUuid The agent the client declared
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-tool-governance/spec.md#requirement-an-agent-is-held-to-its-tool-grant-on-every-path
	 */
	public function bind(string $sessionId, string $agentUuid): void {
		$this->cache->set(key: $sessionId, value: $agentUuid, ttl: self::TTL);
	}//end bind()

	/**
	 * The tools/list result, narrowed to the session agent's grant.
	 *
	 * @param string $sessionId The MCP session
	 * @param string $userId The signed-in user
	 * @param array{tools: array} $listing The full tools/list result
	 *
	 * @return array{tools: array} The listing the session may see
	 *
	 * @spec openspec/specs/agent-tool-governance/spec.md#requirement-an-agent-is-held-to-its-tool-grant-on-every-path
	 */
	public function filterListing(string $sessionId, string $userId, array $listing): array {
		$agent = $this->sessionAgent(sessionId: $sessionId, userId: $userId);
		if ($agent === null) {
			return $listing;
		}

		$granted = array_flip($this->grantedIds(agent: $agent, catalog: $listing['tools']));
		$tools   = array_values(
			array_filter(
				$listing['tools'],
				static fn (array $descriptor): bool => isset($granted[(string) ($descriptor['id'] ?? '')])
			)
		);

		return ['tools' => $tools];
	}//end filterListing()

	/**
	 * Refuse a tools/call the session agent's grant does not cover.
	 *
	 * @param string $sessionId The MCP session
	 * @param string $userId The signed-in user
	 * @param string $name The tool as the client named it (id or short name)
	 * @param array<string, mixed> $arguments The call's arguments
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException Naming the tool (and argument) the grant does not cover
	 *
	 * @spec openspec/specs/agent-tool-governance/spec.md#requirement-an-agent-is-held-to-its-tool-grant-on-every-path
	 */
	public function assertMayCall(string $sessionId, string $userId, string $name, array $arguments): void {
		$agent = $this->sessionAgent(sessionId: $sessionId, userId: $userId);
		if ($agent === null) {
			return;
		}

		$catalog = $this->toolsService->listTools()['tools'];
		$toolId  = $name;
		foreach ($catalog as $descriptor) {
			if (($descriptor['id'] ?? null) === $name || ($descriptor['name'] ?? null) === $name) {
				$toolId = (string) $descriptor['id'];
				break;
			}
		}

		if (in_array($toolId, $this->grantedIds(agent: $agent, catalog: $catalog), true) === false) {
			$this->logger->info(
				message: '[MCP] Tool call refused: not in the agent grant',
				context: ['tool' => $toolId, 'agent' => $agent->getUuid()]
			);
			throw new InvalidArgumentException(
				message: 'Tool ' . $toolId . ' is not in the grant of agent ' . (string) $agent->getName()
			);
		}

		$constraints = $this->grantResolver->argumentConstraints(grants: (array) $agent->getTools());
		$violation   = $this->grantResolver::violationFor(
			constraintSets: ($constraints[$toolId] ?? []),
			arguments: $arguments
		);
		if ($violation !== null) {
			$relation = '= ';
			if ($violation['mode'] === ToolGrantResolver::CONSTRAINT_MODE_SET) {
				$relation = 'in ';
			}

			throw new InvalidArgumentException(
				message: 'Tool ' . $toolId . ' is granted to agent ' . (string) $agent->getName()
					. ' only with argument ' . $violation['argument'] . ' ' . $relation
					. implode(', ', $violation['values'])
			);
		}
	}//end assertMayCall()

	/**
	 * The agent a session is bound to, re-read so grant edits apply at once.
	 *
	 * @param string $sessionId The MCP session
	 * @param string $userId The signed-in user
	 *
	 * @return Agent|null Null when the session declared no agent
	 *
	 * @throws InvalidArgumentException When the bound agent is gone, inactive or no longer the user's
	 */
	private function sessionAgent(string $sessionId, string $userId): ?Agent {
		$agentUuid = $this->cache->get(key: $sessionId);
		if (is_string($agentUuid) === false || $agentUuid === '') {
			return null;
		}

		return $this->loadAgent(agentUuid: $agentUuid, userId: $userId);
	}//end sessionAgent()

	/**
	 * Load an agent the user may act as.
	 *
	 * @param string $agentUuid The agent uuid
	 * @param string $userId The signed-in user
	 *
	 * @return Agent The agent
	 *
	 * @throws InvalidArgumentException When the agent is unknown, inactive or not accessible
	 */
	private function loadAgent(string $agentUuid, string $userId): Agent {
		try {
			$agent = $this->agentMapper->findByUuid(uuid: $agentUuid);
		} catch (Throwable $e) {
			throw new InvalidArgumentException(message: 'Unknown agent: ' . $agentUuid);
		}

		if ($agent->getActive() === false
			|| $this->agentMapper->canUserAccessAgent(agent: $agent, userId: $userId) === false
		) {
			throw new InvalidArgumentException(message: 'Agent ' . $agentUuid . ' is not available to this user');
		}

		return $agent;
	}//end loadAgent()

	/**
	 * The catalogue ids an agent's grant resolves to.
	 *
	 * @param Agent $agent The agent
	 * @param array<int, array<string, mixed>> $catalog The tools/list descriptors
	 *
	 * @return array<int, string> Granted tool ids
	 */
	private function grantedIds(Agent $agent, array $catalog): array {
		// The MCP descriptor carries its dotted id as `id`; the resolver reads it as `mcpId`.
		$forResolver = array_map(
			static fn (array $descriptor): array => $descriptor + ['mcpId' => ($descriptor['id'] ?? null)],
			$catalog
		);

		return $this->grantResolver->resolve(grants: (array) ($agent->getTools() ?? []), catalog: $forResolver);
	}//end grantedIds()
}//end class
