<?php

/**
 * Backfills already-adopted flows from the retired object stores.
 *
 * Moving flow storage to `oc_openregister_flows` pointed the engine at a table
 * that starts empty. Flows an operator had already adopted — enabled, owned, on
 * a cron — therefore stop dispatching the moment the new store goes live, and a
 * flow that silently stops is indistinguishable from one that never fired. This
 * step carries those definitions across so the cutover is not an outage.
 *
 * Two things make this a migration rather than an import:
 *
 * - It preserves `enabled` and `owner`. A *declared* flow (`x-openregister-flows`)
 *   lands disabled and ownerless because nobody has adopted it yet. These rows
 *   were adopted, by a human, deliberately — resetting them would be data loss
 *   dressed up as safety.
 * - It is one-way and idempotent. Re-running never resurrects a flow an operator
 *   has since disabled, because a uuid already present is skipped outright.
 *
 * The guard that matters: a flow is carried across DISABLED, regardless of its
 * source state, when its cron fires more often than every five minutes. A stale
 * every-minute flow is exactly the kind of residue a test run leaves behind, and
 * an indiscriminate backfill would start firing it against production data on
 * the next cron tick. Landing it disabled keeps the definition and withholds the
 * dispatch; `$output` names each one so the operator can re-enable knowingly.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use DateTime;
use OCP\DB\ISchemaWrapper;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Copies live flow objects into the native flow table.
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
 */
class Version1Date20260803000200 extends SimpleMigrationStep
{

    /**
     * The columns a per-schema object table must have to BE a flow store.
     *
     * Identifying the source by column signature rather than by a configured
     * register/schema id is deliberate: `flow_register`/`flow_schema` were
     * optional app-config, so on most instances they are unset and every app
     * that owned flows picked its own register. A table carrying all of these
     * columns is a flow store no matter which register it belongs to.
     *
     * @var array<int, string>
     */
    private const FLOW_COLUMNS = ['name', 'trigger', 'enabled', 'nodes', 'edges', 'owner'];

    /**
     * Crons at or below this many minutes are not carried across enabled.
     *
     * @var integer
     */
    private const MIN_SAFE_CRON_MINUTES = 5;

    /**
     * The database connection.
     *
     * @var IDBConnection
     */
    private IDBConnection $db;

    /**
     * The system configuration, read for the table prefix.
     *
     * @var IConfig
     */
    private IConfig $config;

    /**
     * Constructor.
     *
     * @param IDBConnection $db     The database connection.
     * @param IConfig       $config The system configuration.
     */
    public function __construct(IDBConnection $db, IConfig $config)
    {
        $this->db     = $db;
        $this->config = $config;

    }//end __construct()

    /**
     * Copy adopted flows out of the object stores and into the native table.
     *
     * Runs after the schema change so `openregister_flows` is guaranteed to
     * exist. Raw SQL rather than ObjectService throughout: a migration has no
     * user session, so anything that consults RBAC would read as "denied" and
     * copy nothing — a silent, total no-op that still reports success.
     *
     * @param IOutput $output        Migration output.
     * @param Closure $schemaClosure Schema closure returning an ISchemaWrapper.
     * @param array   $options       Migration options.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        $sources = $this->findFlowTables(schema: $schemaClosure());
        if (empty($sources) === true) {
            $output->info('No legacy flow object stores found; nothing to backfill.');
            return;
        }

        $copied  = 0;
        $skipped = 0;
        $held    = 0;

        foreach ($sources as $table => $app) {
            foreach ($this->readLiveFlows(table: $table) as $row) {
                $uuid = ($row['_uuid'] ?? null);
                if ($uuid === null || $uuid === '' || $this->alreadyPresent(uuid: $uuid) === true) {
                    $skipped++;
                    continue;
                }

                $cron    = ($row['cron'] ?? null);
                $enabled = $this->isTruthy(value: ($row['enabled'] ?? null));
                if ($enabled === true && $this->cronIsTooFrequent(cron: $cron) === true) {
                    $enabled = false;
                    $held++;
                    $output->warning(
                        sprintf(
                            'Flow "%s" carried across DISABLED: cron "%s" fires more often than every %d minutes.',
                            (string) ($row['name'] ?? $uuid),
                            (string) $cron,
                            self::MIN_SAFE_CRON_MINUTES
                        )
                    );
                }

                $this->insertFlow(row: $row, app: $app, enabled: $enabled);
                $copied++;
            }//end foreach
        }//end foreach

        $output->info(
            sprintf(
                'Flow backfill: %d copied, %d skipped (already present or unusable), %d held disabled.',
                $copied,
                $skipped,
                $held
            )
        );

    }//end postSchemaChange()

    /**
     * Every per-schema object table that carries the flow column signature.
     *
     * @param ISchemaWrapper $schema The live schema.
     *
     * @return array<string, string> Table name mapped to the owning app id.
     *
     * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
     */
    private function findFlowTables(ISchemaWrapper $schema): array
    {
        $prefix = $this->config->getSystemValueString('dbtableprefix', 'oc_');
        $found  = [];

        foreach ($schema->getTables() as $table) {
            $name = $table->getName();
            if (str_starts_with($name, $prefix.'openregister_table_') === false) {
                continue;
            }

            $missing = false;
            foreach (self::FLOW_COLUMNS as $column) {
                if ($table->hasColumn($column) === false) {
                    $missing = true;
                    break;
                }
            }

            if ($missing === true) {
                continue;
            }

            $found[$name] = $this->appForTable(table: $name, prefix: $prefix);
        }//end foreach

        return $found;

    }//end findFlowTables()

    /**
     * The app that owns a per-schema table, via its register's slug.
     *
     * Falls back to `openregister` rather than guessing: a flow with a wrong
     * `app` would be filtered out of the owning app's list and look deleted.
     *
     * @param string $table  The full table name.
     * @param string $prefix The database table prefix.
     *
     * @return string The owning app id.
     *
     * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
     */
    private function appForTable(string $table, string $prefix): string
    {
        $suffix = substr($table, strlen($prefix.'openregister_table_'));
        $parts  = explode('_', $suffix);
        if (count($parts) < 2 || ctype_digit($parts[0]) === false) {
            return 'openregister';
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('slug')
            ->from('openregister_registers')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter((int) $parts[0])));

        $result = $qb->executeQuery();
        $slug   = $result->fetchOne();
        $result->closeCursor();

        if ($slug === false || $slug === null || $slug === '') {
            return 'openregister';
        }

        return (string) $slug;

    }//end appForTable()

    /**
     * The undeleted rows of one legacy flow table.
     *
     * `_deleted is null` is load-bearing: OpenRegister deletes are soft, so
     * without it a backfill resurrects every flow anyone has ever removed.
     *
     * @param string $table The source table name.
     *
     * @return array<int, array> The live rows.
     *
     * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
     */
    private function readLiveFlows(string $table): array
    {
        $sql    = 'SELECT * FROM `'.$table.'` WHERE `_deleted` IS NULL';
        $result = $this->db->executeQuery($sql);
        $rows   = $result->fetchAll();
        $result->closeCursor();

        return $rows;

    }//end readLiveFlows()

    /**
     * Whether a uuid is already in the native store.
     *
     * @param string $uuid The flow uuid.
     *
     * @return boolean True when the flow is already present.
     *
     * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
     */
    private function alreadyPresent(string $uuid): bool
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from('openregister_flows')
            ->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $found  = $result->fetchOne();
        $result->closeCursor();

        return $found !== false && $found !== null;

    }//end alreadyPresent()

    /**
     * Whether a cron expression fires more often than the safe floor.
     *
     * Only the minute field is inspected, and only for the two forms that can
     * produce sub-five-minute firing: a bare wildcard, and a wildcard with a
     * step value. Anything this cannot parse
     * is treated as safe — a backfill that disabled flows on a cron it merely
     * failed to understand would be its own outage.
     *
     * @param string|null $cron The cron expression.
     *
     * @return boolean True when the cron fires too often to auto-enable.
     *
     * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
     */
    private function cronIsTooFrequent(?string $cron): bool
    {
        if ($cron === null || trim($cron) === '') {
            return false;
        }

        $fields = preg_split('/\s+/', trim($cron));
        if ($fields === false || count($fields) < 5) {
            return false;
        }

        $minute = $fields[0];
        if ($minute === '*') {
            return true;
        }

        if (preg_match('#^\*/(\d+)$#', $minute, $matches) === 1) {
            return ((int) $matches[1]) < self::MIN_SAFE_CRON_MINUTES;
        }

        return false;

    }//end cronIsTooFrequent()

    /**
     * Read a stored boolean that may be a bool, an int or a string.
     *
     * The object stores are backed by different database platforms, so the same
     * `enabled` value arrives as `true`, `1`, `'1'` or `'t'` depending on where
     * it is read from.
     *
     * @param mixed $value The stored value.
     *
     * @return boolean The value as a boolean.
     *
     * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
     */
    private function isTruthy($value): bool
    {
        if (is_bool($value) === true) {
            return $value;
        }

        if (is_int($value) === true) {
            return $value === 1;
        }

        if (is_string($value) === true) {
            return in_array(strtolower($value), ['1', 't', 'true', 'yes'], true);
        }

        return false;

    }//end isTruthy()

    /**
     * Write one legacy row into the native flow table.
     *
     * `retention_days`, `audit_enabled` and `oversight_enabled` are left NULL on
     * purpose — NULL means "inherit the instance default", so a migrated flow
     * follows admin settings instead of freezing today's values into every row.
     *
     * @param array   $row     The legacy row.
     * @param string  $app     The owning app id.
     * @param boolean $enabled The enabled state to write.
     *
     * @return void
     *
     * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
     */
    private function insertFlow(array $row, string $app, bool $enabled): void
    {
        $now = new DateTime();
        $qb  = $this->db->getQueryBuilder();

        $qb->insert('openregister_flows')
            ->values(
                [
                    'uuid'             => $qb->createNamedParameter((string) $row['_uuid']),
                    'name'             => $qb->createNamedParameter($this->str(value: ($row['name'] ?? null))),
                    'description'      => $qb->createNamedParameter($this->str(value: ($row['description'] ?? null))),
                    'app'              => $qb->createNamedParameter($app),
                    'enabled'          => $qb->createNamedParameter($enabled, 'boolean'),
                    'trigger'          => $qb->createNamedParameter($this->str(value: ($row['trigger'] ?? null))),
                    'trigger_register' => $qb->createNamedParameter($this->str(value: ($row['trigger_register'] ?? null))),
                    'trigger_schema'   => $qb->createNamedParameter($this->str(value: ($row['trigger_schema'] ?? null))),
                    'cron'             => $qb->createNamedParameter($this->str(value: ($row['cron'] ?? null))),
                    'nodes'            => $qb->createNamedParameter($this->json(value: ($row['nodes'] ?? null))),
                    'edges'            => $qb->createNamedParameter($this->json(value: ($row['edges'] ?? null))),
                    'limits'           => $qb->createNamedParameter($this->json(value: ($row['limits'] ?? null))),
                    'notes'            => $qb->createNamedParameter($this->str(value: ($row['notes'] ?? null))),
                    'owner'            => $qb->createNamedParameter($this->str(value: ($row['owner'] ?? ($row['_owner'] ?? null)))),
                    'organisation'     => $qb->createNamedParameter($this->str(value: ($row['_organisation'] ?? null))),
                    'created'          => $qb->createNamedParameter($now, 'datetime'),
                    'updated'          => $qb->createNamedParameter($now, 'datetime'),
                ]
            );

        $qb->executeStatement();

    }//end insertFlow()

    /**
     * Normalise a stored scalar to a string or null.
     *
     * @param mixed $value The stored value.
     *
     * @return string|null The value as a string, or null when absent.
     *
     * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
     */
    private function str($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;

    }//end str()

    /**
     * Normalise a stored JSON column to a JSON string.
     *
     * Legacy rows store these as JSON text already; re-encoding an array covers
     * the platforms that hand back a decoded value.
     *
     * @param mixed $value The stored value.
     *
     * @return string|null The value as JSON text, or null when absent.
     *
     * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
     */
    private function json($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value) === true) {
            return $value;
        }

        $encoded = json_encode($value);
        if ($encoded === false) {
            return null;
        }

        return $encoded;

    }//end json()
}//end class
