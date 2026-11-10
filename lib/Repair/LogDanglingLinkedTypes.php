<?php

/**
 * LogDanglingLinkedTypes — surface schemas whose configuration.linkedTypes
 * references integration ids that the registry can no longer resolve.
 *
 * Per AD-5 of pluggable-integration-registry the registry validates
 * linkedTypes against either the legacy private allow-list (see
 * `Schema::legacyLinkedTypeIds()`) or the live
 * `IntegrationRegistry::listIds()` output. Existing schemas may carry
 * ids that are valid TODAY (because they appear in the legacy
 * fallback) but will eventually become invalid as the legacy
 * fallback is removed. This repair step scans all schemas at
 * install / post-migration time and logs WARNING entries for any
 * linkedTypes value not registered with the registry.
 *
 * Strictly informational — never throws, never modifies data. The
 * goal is operational visibility so admins can plan provider
 * installation before the deprecated fallback disappears.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-11
 * @spec openspec/specs/linked-entity-types/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Service\Integration\IntegrationIdIndexInterface;
use OCP\AppFramework\Db\Entity;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Repair step: log schemas with dangling linkedTypes values.
 */
class LogDanglingLinkedTypes implements IRepairStep {
	/**
	 * Constructor.
	 *
	 * @param IntegrationIdIndexInterface $registry Integration registry.
	 * @param ContainerInterface $container DI container — used to
	 *                                      lazily resolve SchemaMapper
	 *                                      since hard-binding it
	 *                                      creates a circular dep at
	 *                                      app boot.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private IntegrationIdIndexInterface $registry,
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Human-readable step name surfaced in occ + admin UI.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function getName(): string {
		return 'Log schemas with linkedTypes referencing unregistered integrations';
	}//end getName()

	/**
	 * Run the scan.
	 *
	 * @param IOutput $output Migration output handle.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function run(IOutput $output): void {
		$output->info('[OpenRegister] Scanning schemas for dangling linkedTypes...');

		$schemas = $this->loadSchemas();
		if ($schemas === null) {
			$output->info('[OpenRegister] Schema mapper unavailable — scan skipped (this is normal on first install).');
			return;
		}

		$this->report(
			dangling: $this->scan(schemas: $schemas, registeredIds: $this->registry->listIds()),
			output: $output
		);
	}//end run()

	/**
	 * Log and print one warning per dangling linkedType, or say that there are none.
	 *
	 * @param array<int, array{slug: string, id: string, danglingType: string}> $dangling The dangling rows.
	 * @param IOutput                                                           $output   Repair output.
	 *
	 * @return void
	 */
	private function report(array $dangling, IOutput $output): void {
		if ($dangling === []) {
			$output->info('[OpenRegister] All schemas linkedTypes are covered by registered integrations.');
			return;
		}

		foreach ($dangling as $row) {
			$message = $this->danglingMessage(row: $row);
			$this->logger->warning($message);
			$output->warning($message);
		}
	}//end report()

	/**
	 * The warning text for one dangling linkedType.
	 *
	 * @param array{slug: string, id: string, danglingType: string} $row The dangling row.
	 *
	 * @return string
	 */
	private function danglingMessage(array $row): string {
		$template  = '[OpenRegister] Schema "%s" (id=%s) declares linkedType "%s"';
		$template .= ' which is not registered. Add the matching IntegrationProvider';
		$template .= ' before the legacy linked-type fallback is removed.';

		return sprintf($template, $row['slug'], $row['id'], $row['danglingType']);
	}//end danglingMessage()

	/**
	 * Load every schema entity via the SchemaMapper.
	 *
	 * Returns null when the mapper cannot be resolved (e.g. during
	 * first-install when the DB is being prepared and the service
	 * isn't wired yet). Callers treat null as "scan skipped".
	 *
	 * @return array<int, mixed>|null
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function loadSchemas(): ?array {
		try {
			return $this->findAllSchemas();
		} catch (\Throwable $e) {
			$this->logger->debug(
				'[OpenRegister] LogDanglingLinkedTypes could not load schemas — skipping scan',
				['exception' => $e]
			);
		}

		return null;
	}//end loadSchemas()

	/**
	 * All schemas from the SchemaMapper, or null when it cannot list them.
	 *
	 * @return array<int, mixed>|null
	 */
	private function findAllSchemas(): ?array {
		$mapper = $this->container->get('OCA\\OpenRegister\\Db\\SchemaMapper');
		if (method_exists($mapper, 'findAll') === false) {
			return null;
		}

		$result = $mapper->findAll();
		if (is_array($result) === false) {
			return null;
		}

		return $result;
	}//end findAllSchemas()

	/**
	 * Walk every schema and collect linkedTypes that aren't registered.
	 *
	 * @param array<int, mixed> $schemas Schema entities.
	 * @param array<int,string> $registeredIds Ids known to the registry.
	 *
	 * @return array<int, array{slug: string, id: string, danglingType: string}>
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function scan(array $schemas, array $registeredIds): array {
		$dangling = [];
		foreach ($schemas as $schema) {
			$dangling = array_merge($dangling, $this->danglingOf(schema: $schema, registeredIds: $registeredIds));
		}

		return $dangling;
	}//end scan()

	/**
	 * The dangling linkedTypes entries of one schema.
	 *
	 * @param mixed              $schema        Schema entity (or test stub).
	 * @param array<int, string> $registeredIds Live registry ids.
	 *
	 * @return array<int, array{slug: string, id: string, danglingType: string}>
	 */
	private function danglingOf(mixed $schema, array $registeredIds): array {
		$linkedTypes = $this->extractLinkedTypes(schema: $schema);
		if ($linkedTypes === []) {
			return [];
		}

		$slug = $this->safeStringAccessor(schema: $schema, accessors: ['getSlug', 'getName']) ?? 'unknown';
		$id   = (string) ($this->safeStringAccessor(schema: $schema, accessors: ['getId', 'getUuid']) ?? '');

		return array_map(
			static fn (string $type): array => ['slug' => $slug, 'id' => $id, 'danglingType' => $type],
			$this->unregistered(types: $linkedTypes, registeredIds: $registeredIds)
		);
	}//end danglingOf()

	/**
	 * The string linkedTypes the registry does not know, in their original order.
	 *
	 * @param array<int|string, mixed> $types         The declared linkedTypes.
	 * @param array<int, string>       $registeredIds Live registry ids.
	 *
	 * @return array<int, string>
	 */
	private function unregistered(array $types, array $registeredIds): array {
		return array_values(
			array_filter(
				$types,
				static fn (mixed $type): bool => is_string($type) === true && in_array($type, $registeredIds, true) === false
			)
		);
	}//end unregistered()

	/**
	 * Pull the linkedTypes array out of a Schema entity, regardless of
	 * which accessor variant the codebase exposes.
	 *
	 * @param mixed $schema Schema entity.
	 *
	 * @return array<int, mixed>
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function extractLinkedTypes($schema): array {
		if (is_object($schema) === false) {
			return [];
		}

		$direct = $this->extractViaGetLinkedTypes(schema: $schema);
		if ($direct !== null) {
			return $direct;
		}

		return $this->extractViaGetConfiguration(schema: $schema) ?? [];
	}//end extractLinkedTypes()

	/**
	 * Try to read linkedTypes via getLinkedTypes() accessor.
	 *
	 * @param object $schema Schema entity.
	 *
	 * @return array<int,mixed>|null Array when found, null when not available.
	 */
	private function extractViaGetLinkedTypes(object $schema): ?array {
		$value = $this->callDeclared(schema: $schema, method: 'getLinkedTypes');
		if (is_array($value) === false) {
			return null;
		}

		return $value;
	}//end extractViaGetLinkedTypes()

	/**
	 * Try to read linkedTypes via getConfiguration() accessor.
	 *
	 * @param object $schema Schema entity.
	 *
	 * @return array<int,mixed>|null Array when found, null when not available.
	 */
	private function extractViaGetConfiguration(object $schema): ?array {
		$value = $this->callDeclared(schema: $schema, method: 'getConfiguration');
		if (is_array($value) === false || is_array($value['linkedTypes'] ?? null) === false) {
			return null;
		}

		return $value['linkedTypes'];
	}//end extractViaGetConfiguration()

	/**
	 * Call a declared method, or null when it is not declared or throws.
	 *
	 * @param object $schema Schema entity.
	 * @param string $method The method name.
	 *
	 * @return mixed
	 */
	private function callDeclared(object $schema, string $method): mixed {
		if (method_exists($schema, $method) === false) {
			return null;
		}

		try {
			return $schema->{$method}();
		} catch (\Throwable $e) {
			return null;
		}
	}//end callDeclared()

	/**
	 * Call the first available string accessor on a schema entity.
	 *
	 * @param mixed $schema Schema entity.
	 * @param array<string> $accessors Ordered list of method names to try.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function safeStringAccessor($schema, array $accessors): ?string {
		foreach ($accessors as $method) {
			$value = $this->readString(schema: $schema, method: $method);
			if ($value !== null) {
				return $value;
			}
		}

		return null;
	}//end safeStringAccessor()

	/**
	 * One accessor's value as a non-empty string, or null.
	 *
	 * @param mixed  $schema Schema entity.
	 * @param string $method The accessor name.
	 *
	 * @return string|null
	 */
	private function readString($schema, string $method): ?string {
		if ($this->accessorIsAvailable(schema: $schema, method: $method) === false) {
			return null;
		}

		try {
			return $this->asString(value: $schema->{$method}());
		} catch (\Throwable $e) {
			return null;
		}
	}//end readString()

	/**
	 * A non-empty string or an int as a string; null for anything else.
	 *
	 * @param mixed $value The accessor result.
	 *
	 * @return string|null
	 */
	private function asString(mixed $value): ?string {
		if (is_int($value) === true) {
			return (string) $value;
		}

		if (is_string($value) === true && $value !== '') {
			return $value;
		}

		return null;
	}//end asString()

	/**
	 * Decide whether an accessor can actually be invoked on a schema entity.
	 *
	 * Probing with method_exists() alone is the wrong instrument here. Nextcloud's Entity
	 * serves get*() through __call(), declaring the accessors only as `@method`,
	 * so method_exists() is FALSE for every one of them. Db\Schema declares no
	 * concrete getSlug()/getUuid() and inherits getId() as a docblock from
	 * Entity, so the old probe rejected every candidate this class passes in and
	 * scan() logged `slug: 'unknown', id: ''` for 100% of the rows — defeating
	 * the entire purpose of the report.
	 *
	 * Entity::getter() resolves the name with property_exists() and throws
	 * BadFunctionCallException otherwise, so mirroring that derivation here is a
	 * genuine membership test rather than the always-true answer is_callable()
	 * would give on a __call class.
	 *
	 * @param mixed $schema Schema entity.
	 * @param string $method Accessor name to probe.
	 *
	 * @return bool True when calling $method on $schema will resolve.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function accessorIsAvailable($schema, string $method): bool {
		if (is_object($schema) === false) {
			return false;
		}

		if (method_exists($schema, $method) === true) {
			return true;
		}

		// The exact derivation Entity::__call() performs before handing the name
		// to Entity::getter(): lcfirst(substr($methodName, 3)).
		return $schema instanceof Entity && str_starts_with($method, 'get') === true
			&& property_exists($schema, lcfirst(substr($method, 3))) === true;
	}//end accessorIsAvailable()
}//end class
