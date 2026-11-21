<?php

/**
 * Groups the MCP opted-in schemas by the app that owns them.
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

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use Psr\Log\LoggerInterface;

/**
 * Resolves which app owns each schema with a validated
 * `x-openregister-mcp.enabled:true` block, for the schema-derived providers.
 *
 * @spec openspec/specs/ai-mcp/spec.md
 */
final class McpSchemaOwnership {

	/**
	 * Register mapper (owning-app + owning-register resolution).
	 *
	 * @var RegisterMapperInterface
	 */
	private readonly RegisterMapperInterface $registerMapper;

	/**
	 * PSR logger.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Bind the register lookup of a discovery.
	 *
	 * @param McpDiscoveryRun $run The discovery: its container serves the register mapper.
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 */
	public function __construct(McpDiscoveryRun $run) {
		$this->registerMapper = $run->container->get(RegisterMapperInterface::class);
		$this->logger         = $run->logger;
	}//end __construct()

	/**
	 * Group every schema with a validated `x-openregister-mcp.enabled:true`
	 * block by its owning app id, each paired with its owning register.
	 *
	 * Owning app id resolution mirrors {@see \OCA\OpenRegister\Service\SemanticTypeResolver::owningAppId()}:
	 * the schema's own `application` field, else the owning register's
	 * `application`. A schema that resolves to no owning app is skipped —
	 * the ABI requires a concrete `getAppId()` per provider instance.
	 *
	 * @param array<int, Schema> $schemas Every schema on the instance.
	 *
	 * @return array<string, list<array{schema: Schema, register: Register|null}>> Opted-in schemas grouped by owning app id.
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 */
	public function groupOptedInSchemasByApp(array $schemas): array {
		$schemasByApp = [];

		foreach ($schemas as $schema) {
			$configuration = ($schema->getConfiguration() ?? []);
			$annotation = ($configuration['x-openregister-mcp'] ?? null);
			if (is_array($annotation) === false || ($annotation['enabled'] ?? false) !== true) {
				continue;
			}

			$register = $this->resolveSchemaRegister(schema: $schema);
			$owningApp = $this->resolveOwningAppId(schema: $schema, register: $register);
			if ($owningApp === null) {
				$this->logger->debug(
					'[Application] Opted-in schema has no resolvable owning app — skipped',
					['schemaId' => $schema->getId()]
				);
				continue;
			}

			$schemasByApp[$owningApp][] = ['schema' => $schema, 'register' => $register];
		}//end foreach

		return $schemasByApp;
	}//end groupOptedInSchemasByApp()

	/**
	 * Resolve a schema's owning register (the first register whose
	 * `schemas` id-list contains it), or null when the schema is orphaned.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return Register|null The owning register, or null when none is found.
	 */
	private function resolveSchemaRegister(Schema $schema): ?Register {
		try {
			$registerId = $this->registerMapper->getFirstRegisterWithSchema(schemaId: (int)$schema->getId());
			if ($registerId === null) {
				return null;
			}

			return $this->registerMapper->find(id: $registerId, _rbac: false, _multitenancy: false);
		} catch (\Throwable $e) {
			$this->logger->debug(
				'[Application] Owning-register resolution failed for schema ' . $schema->getId() . ': ' . $e->getMessage()
			);
			return null;
		}
	}//end resolveSchemaRegister()

	/**
	 * Resolve the owning app id: the schema's own `application` field
	 * (present and reliable on real fleet schemas), else the owning
	 * register's `application`. Null when neither names an app.
	 *
	 * @param Schema $schema The candidate schema.
	 * @param Register|null $register The schema's owning register, when resolvable.
	 *
	 * @return string|null The owning app id, or null when undeclared.
	 */
	private function resolveOwningAppId(Schema $schema, ?Register $register): ?string {
		$appId = $schema->getApplication();
		if (is_string($appId) === true && $appId !== '') {
			return $appId;
		}

		if ($register !== null) {
			$registerApp = $register->getApplication();
			if (is_string($registerApp) === true && $registerApp !== '') {
				return $registerApp;
			}
		}

		return null;
	}//end resolveOwningAppId()
}//end class
