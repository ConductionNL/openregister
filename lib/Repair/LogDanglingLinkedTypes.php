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

use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCP\AppFramework\Db\Entity;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Repair step: log schemas with dangling linkedTypes values.
 */
class LogDanglingLinkedTypes implements IRepairStep
{
    /**
     * Constructor.
     *
     * @param IntegrationRegistry $registry  Integration registry.
     * @param ContainerInterface  $container DI container — used to
     *                                       lazily resolve SchemaMapper
     *                                       since hard-binding it
     *                                       creates a circular dep at
     *                                       app boot.
     * @param LoggerInterface     $logger    Logger.
     *
     * @return void
     */
    public function __construct(
        private IntegrationRegistry $registry,
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
    public function getName(): string
    {
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
    public function run(IOutput $output): void
    {
        $output->info('[OpenRegister] Scanning schemas for dangling linkedTypes...');

        $schemas = $this->loadSchemas();
        if ($schemas === null) {
            $output->info('[OpenRegister] Schema mapper unavailable — scan skipped (this is normal on first install).');
            return;
        }

        $registeredIds = $this->registry->listIds();
        $dangling      = $this->scan(schemas: $schemas, registeredIds: $registeredIds);

        if ($dangling === []) {
            $output->info('[OpenRegister] All schemas linkedTypes are covered by registered integrations.');
            return;
        }

        foreach ($dangling as $row) {
            $template  = '[OpenRegister] Schema "%s" (id=%s) declares linkedType "%s"';
            $template .= ' which is not registered. Add the matching IntegrationProvider';
            $template .= ' before the legacy linked-type fallback is removed.';
            $message   = sprintf(
                $template,
                $row['slug'],
                $row['id'],
                $row['danglingType']
            );
            $this->logger->warning($message);
            $output->warning($message);
        }
    }//end run()

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
    private function loadSchemas(): ?array
    {
        try {
            $mapper = $this->container->get('OCA\\OpenRegister\\Db\\SchemaMapper');
            if (method_exists($mapper, 'findAll') === true) {
                $result = $mapper->findAll();
                if (is_array($result) === true) {
                    return $result;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->debug(
                '[OpenRegister] LogDanglingLinkedTypes could not load schemas — skipping scan',
                ['exception' => $e]
            );
        }

        return null;
    }//end loadSchemas()

    /**
     * Walk every schema and collect linkedTypes that aren't registered.
     *
     * @param array<int, mixed> $schemas       Schema entities.
     * @param array<int,string> $registeredIds Ids known to the registry.
     *
     * @return array<int, array{slug: string, id: string, danglingType: string}>
     *
     * @spec openspec/specs/linked-entity-types/spec.md
     */
    private function scan(array $schemas, array $registeredIds): array
    {
        $dangling = [];
        foreach ($schemas as $schema) {
            $linkedTypes = $this->extractLinkedTypes(schema: $schema);
            if ($linkedTypes === []) {
                continue;
            }

            $slug = $this->safeStringAccessor(schema: $schema, accessors: ['getSlug', 'getName']) ?? 'unknown';
            $id   = (string) ($this->safeStringAccessor(schema: $schema, accessors: ['getId', 'getUuid']) ?? '');

            foreach ($linkedTypes as $type) {
                if (is_string($type) === false) {
                    continue;
                }

                if (in_array($type, $registeredIds, true) === true) {
                    continue;
                }

                $dangling[] = [
                    'slug'         => $slug,
                    'id'           => $id,
                    'danglingType' => $type,
                ];
            }
        }//end foreach

        return $dangling;
    }//end scan()

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
    private function extractLinkedTypes($schema): array
    {
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
    private function extractViaGetLinkedTypes(object $schema): ?array
    {
        if (method_exists($schema, 'getLinkedTypes') === false) {
            return null;
        }

        try {
            $value = $schema->getLinkedTypes();
        } catch (\Throwable $e) {
            return null;
        }

        if (is_array($value) === true) {
            return $value;
        }

        return null;
    }//end extractViaGetLinkedTypes()

    /**
     * Try to read linkedTypes via getConfiguration() accessor.
     *
     * @param object $schema Schema entity.
     *
     * @return array<int,mixed>|null Array when found, null when not available.
     */
    private function extractViaGetConfiguration(object $schema): ?array
    {
        if (method_exists($schema, 'getConfiguration') === false) {
            return null;
        }

        try {
            $value = $schema->getConfiguration();
        } catch (\Throwable $e) {
            return null;
        }

        if (is_array($value) === true
            && isset($value['linkedTypes']) === true
            && is_array($value['linkedTypes']) === true
        ) {
            return $value['linkedTypes'];
        }

        return null;
    }//end extractViaGetConfiguration()

    /**
     * Call the first available string accessor on a schema entity.
     *
     * @param mixed         $schema    Schema entity.
     * @param array<string> $accessors Ordered list of method names to try.
     *
     * @return string|null
     *
     * @spec openspec/specs/linked-entity-types/spec.md
     */
    private function safeStringAccessor($schema, array $accessors): ?string
    {
        foreach ($accessors as $method) {
            if ($this->accessorIsAvailable(schema: $schema, method: $method) === false) {
                continue;
            }

            try {
                $value = $schema->{$method}();
            } catch (\Throwable $e) {
                continue;
            }

            if (is_string($value) === true && $value !== '') {
                return $value;
            }

            if (is_int($value) === true) {
                return (string) $value;
            }
        }

        return null;
    }//end safeStringAccessor()

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
     * @param mixed  $schema Schema entity.
     * @param string $method Accessor name to probe.
     *
     * @return bool True when calling $method on $schema will resolve.
     *
     * @spec openspec/specs/linked-entity-types/spec.md
     */
    private function accessorIsAvailable($schema, string $method): bool
    {
        if (is_object($schema) === false) {
            return false;
        }

        if (method_exists($schema, $method) === true) {
            return true;
        }

        if (($schema instanceof Entity) === false || str_starts_with($method, 'get') === false) {
            return false;
        }

        // The exact derivation Entity::__call() performs before handing the name
        // to Entity::getter(): lcfirst(substr($methodName, 3)).
        return property_exists($schema, lcfirst(substr($method, 3)));
    }//end accessorIsAvailable()
}//end class
