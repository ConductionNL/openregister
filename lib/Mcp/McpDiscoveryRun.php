<?php

/**
 * One McpToolsService build: the container, the logger and the providers collected so far.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Mcp
 * @package  OCA\OpenRegister\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp;

use OCA\OpenRegister\Mcp\BuiltIn\SchemaDerivedToolProvider;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The state of one provider discovery, built once by McpProviderDiscovery's
 * factory and handed to every collection step in order.
 *
 * Each step appends to the provider list; the attributed step asks it which
 * tool ids an app already has, so its collision policy lives here, next to
 * the list it reads.
 *
 * @spec openspec/specs/chat-ai/spec.md#requirement-mcptoolsservice-provider-discovery-refactor
 */
final class McpDiscoveryRun {

	/**
	 * The providers collected so far, in discovery order.
	 *
	 * @var list<IMcpToolProvider>
	 */
	private array $providers;

	/**
	 * Start a discovery with no providers.
	 *
	 * @param ContainerInterface $container The DI container.
	 * @param LoggerInterface    $logger    PSR logger.
	 *
	 * @spec openspec/specs/chat-ai/spec.md#requirement-mcptoolsservice-provider-discovery-refactor
	 */
	public function __construct(
		public readonly ContainerInterface $container,
		public readonly LoggerInterface $logger,
	) {
		$this->providers = [];
	}//end __construct()

	/**
	 * The providers collected so far, in discovery order.
	 *
	 * @return list<IMcpToolProvider>
	 *
	 * @spec openspec/specs/chat-ai/spec.md#requirement-mcptoolsservice-provider-discovery-refactor
	 */
	public function providers(): array {
		return $this->providers;
	}//end providers()

	/**
	 * Append one provider.
	 *
	 * @param IMcpToolProvider $provider The provider.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/chat-ai/spec.md#requirement-mcptoolsservice-provider-discovery-refactor
	 */
	public function add(IMcpToolProvider $provider): void {
		$this->providers[] = $provider;
	}//end add()

	/**
	 * Append providers, in order.
	 *
	 * @param array<int, IMcpToolProvider> $providers The providers.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/chat-ai/spec.md#requirement-mcptoolsservice-provider-discovery-refactor
	 */
	public function addAll(array $providers): void {
		$this->providers = [...$this->providers, ...array_values($providers)];
	}//end addAll()

	/**
	 * Drop the scanned descriptors whose tool id collides with a tool the
	 * same app already provides.
	 *
	 * An id colliding with a schema-derived id is a discovery-time error:
	 * logged and rejected (REQ-ATTR-002). An id colliding with a hand-written
	 * id is silently suppressed (hand-written wins, as REQ-DERIVED-003).
	 *
	 * @param string                           $appId       The owning app id.
	 * @param array<int, array<string, mixed>> $descriptors Scanned descriptors (see AttributeToolScanner).
	 *
	 * @return list<array<string, mixed>> The descriptors that survive the collision policy.
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 */
	public function withoutCollidingDescriptors(string $appId, array $descriptors): array {
		[$derivedIds, $otherIds] = $this->appToolIds(appId: $appId);

		$kept = [];

		foreach ($descriptors as $descriptor) {
			$id = (string)$descriptor['id'];

			if (in_array($id, $derivedIds, true) === true) {
				$this->logger->error(
					'[Application] Attributed tool id collides with a schema-derived tool id — rejected',
					['appId' => $appId, 'toolId' => $id]
				);
				continue;
			}

			if (in_array($id, $otherIds, true) === true) {
				// Hand-written-wins precedence — silent self-suppression,
				// same as SchemaDerivedToolProvider's REQ-DERIVED-003.
				continue;
			}

			$kept[] = $descriptor;
		}//end foreach

		return $kept;
	}//end withoutCollidingDescriptors()

	/**
	 * The tool ids an app already has: its schema-derived ids and its other (hand-written) ids.
	 *
	 * @param string $appId The app.
	 *
	 * @return array{0: list<string>, 1: list<string>} Derived ids, other ids.
	 */
	private function appToolIds(string $appId): array {
		$derivedIds = [];
		$otherIds = [];

		foreach ($this->providers as $provider) {
			if ($provider->getAppId() !== $appId) {
				continue;
			}

			$ids = array_column($provider->getTools(), 'id');
			if ($provider instanceof SchemaDerivedToolProvider) {
				$derivedIds = array_merge($derivedIds, $ids);
				continue;
			}

			$otherIds = array_merge($otherIds, $ids);
		}

		return [$derivedIds, $otherIds];
	}//end appToolIds()
}//end class
