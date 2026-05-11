<?php

/**
 * LogDanglingLinkedTypes — surface schemas whose configuration.linkedTypes
 * references integration ids that the registry can no longer resolve.
 *
 * Per AD-5 of pluggable-integration-registry the registry validates
 * linkedTypes against either the legacy `VALID_LINKED_TYPES` set or
 * the live `IntegrationRegistry::listIds()` output. Existing schemas
 * may carry ids that are valid TODAY (because they appear in the
 * deprecated fallback) but will eventually become invalid as the
 * deprecated map is removed. This repair step scans all schemas at
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-11
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
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
        $dangling      = $this->scan($schemas, $registeredIds);

        if ($dangling === []) {
            $output->info('[OpenRegister] All schemas linkedTypes are covered by registered integrations.');
            return;
        }

        foreach ($dangling as $row) {
            $message = sprintf(
                '[OpenRegister] Schema "%s" (id=%s) declares linkedType "%s" which is not registered. '
                .'Add the matching IntegrationProvider before the deprecated VALID_LINKED_TYPES fallback is removed.',
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
     */
    private function scan(array $schemas, array $registeredIds): array
    {
        $dangling = [];
        foreach ($schemas as $schema) {
            $linkedTypes = $this->extractLinkedTypes($schema);
            if ($linkedTypes === []) {
                continue;
            }

            $slug = $this->safeStringAccessor($schema, ['getSlug', 'getName']) ?? 'unknown';
            $id   = (string) ($this->safeStringAccessor($schema, ['getId', 'getUuid']) ?? '');

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
        }

        return $dangling;
    }//end scan()

    /**
     * Pull the linkedTypes array out of a Schema entity, regardless of
     * which accessor variant the codebase exposes.
     *
     * @param mixed $schema Schema entity.
     *
     * @return array<int, mixed>
     */
    private function extractLinkedTypes($schema): array
    {
        if (is_object($schema) === false) {
            return [];
        }

        foreach (['getLinkedTypes', 'getConfiguration'] as $accessor) {
            if (method_exists($schema, $accessor) === false) {
                continue;
            }

            try {
                $value = $schema->{$accessor}();
            } catch (\Throwable $e) {
                continue;
            }

            if ($accessor === 'getLinkedTypes' && is_array($value) === true) {
                return $value;
            }

            if ($accessor === 'getConfiguration' && is_array($value) === true) {
                if (isset($value['linkedTypes']) && is_array($value['linkedTypes']) === true) {
                    return $value['linkedTypes'];
                }
            }
        }

        return [];
    }//end extractLinkedTypes()

    /**
     * Call the first available string accessor on a schema entity.
     *
     * @param mixed         $schema    Schema entity.
     * @param array<string> $accessors Ordered list of method names to try.
     *
     * @return string|null
     */
    private function safeStringAccessor($schema, array $accessors): ?string
    {
        foreach ($accessors as $method) {
            if (method_exists($schema, $method) === false) {
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

}//end class
