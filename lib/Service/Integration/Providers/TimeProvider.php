<?php

/**
 * TimeProvider — exposes NC Time Manager entities (clients + tasks)
 * linked to an OpenRegister object via the IntegrationProvider
 * contract.
 *
 * Linking convention until a dedicated `openregister_time_links`
 * link-table ships: an OR-aware author seeds the client's or task's
 * `note` field with the substring `[or:{objectUuid}]`. The provider
 * resolves clients matching that marker via NC Time Manager's own
 * `ClientMapper`, then lists tasks whose `note` carries the same
 * marker via `TaskMapper`, so the registry tab can show both linked
 * clients and linked tasks for the object. Mapper lookups are
 * resolved lazily through the NC server container so the file loads
 * even when the Time Manager app is not installed
 * (AD-23: graceful degradation).
 *
 * Storage strategy is `link-table` — the link lives in the upstream
 * app's tables (`timemanager_client.note` + `timemanager_task.note`),
 * not in OR. The marker-in-note convention is the bridge until the
 * bespoke `openregister_time_links` table + `TimeEntryService` +
 * `TimeController` land (tracked in tasks.md; out of scope for this
 * Bucket-A stub completion — the dedicated link table, denormalized
 * object total, and `occ openregister:time:reconcile` command are
 * deferred to a follow-up change).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\Providers
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
 * @spec openspec/changes/integration-time-tracker/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- self-documenting IntegrationProvider metadata getters mirror the contract in the interface.

use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\Server;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Throwable;

/**
 * Time Manager (NC Time Manager) integration provider.
 *
 * Always-on metadata: id='time-tracker', group='workflow',
 * requiredApp='timemanager', storage='link-table'. The provider is
 * list-only — mutation (creating clients/tasks, logging hours) belongs
 * to NC Time Manager itself; the registry's job is to surface what's
 * already there.
 */
class TimeProvider extends AbstractIntegrationProvider
{

    /**
     * NC app id required for this integration. Configurable via the
     * admin setting `time-tracker.backend` in the follow-up; the
     * Bucket-A stub completion hard-codes the default.
     *
     * @var string
     */
    private const REQUIRED_APP = 'timemanager';

    /**
     * Marker prefix seeded into a client's / task's `note` field by
     * the OR-aware author to link the entity to an OR object. Full
     * marker shape: `[or:{objectUuid}]`.
     *
     * @var string
     */
    private const MARKER_PREFIX = '[or:';

    /**
     * Maximum number of tasks to surface alongside the linked
     * clients. The registry tab is a quick-look surface; the Time
     * Manager UI handles the long tail.
     *
     * @var int
     */
    private const TASKS_PER_OBJECT = 50;

    /**
     * Optional server container override. Defaults to NC's global
     * `\OCP\Server` container — tests inject a mock so the
     * ClientMapper / TaskMapper lookups are exerciseable without the
     * Time Manager app on the classpath.
     *
     * @var ContainerInterface|null
     */
    private ?ContainerInterface $container;

    /**
     * Constructor.
     *
     * Required args mirror the greenfield-provider DI signature
     * (`db, appManager, l10n`) so the shared `Application.php`
     * registration block still wires this provider correctly. The
     * optional container override is for unit tests only; production
     * uses `\OCP\Server::get(...)` via {@see lookup()}.
     *
     * @param IDBConnection           $db         NC DB connection — used directly for marker
     *                                            LIKE queries against the upstream tables
     *                                            (ClientMapper/TaskMapper don't expose
     *                                            marker-aware finders, so we run the LIKE
     *                                            via the public `getTableName()` accessor).
     * @param IAppManager             $appManager NC app manager — drives `isEnabled()`.
     * @param IL10N                   $l10n       Localisation.
     * @param ContainerInterface|null $container  Optional server-container override
     *                                            (tests only).
     *
     * @return void
     */
    public function __construct(
        private IDBConnection $db,
        private IAppManager $appManager,
        private IL10N $l10n,
        ?ContainerInterface $container=null,
    ) {
        $this->container = $container;
    }//end __construct()

    public function getId(): string
    {
        return 'time-tracker';
    }//end getId()

    public function getLabel(): string
    {
        return $this->l10n->t('Time');
    }//end getLabel()

    public function getIcon(): string
    {
        return 'Clock';
    }//end getIcon()

    public function getGroup(): ?string
    {
        return 'workflow';
    }//end getGroup()

    public function getRequiredApp(): ?string
    {
        return self::REQUIRED_APP;
    }//end getRequiredApp()

    public function getStorageStrategy(): string
    {
        return 'link-table';
    }//end getStorageStrategy()

    public function isEnabled(): bool
    {
        return $this->appManager->isInstalled(self::REQUIRED_APP);
    }//end isEnabled()

    /**
     * List NC Time Manager clients + tasks linked to an OR object.
     *
     * Flow:
     *   1. Return `[]` immediately when the Time Manager app isn't installed.
     *   2. Look up `OCA\TimeManager\Db\ClientMapper` from the server container.
     *      The class only exists when the Time Manager app is enabled, so the
     *      lookup is wrapped in a `Throwable` catch — schema mismatches and
     *      classpath misses both degrade to an empty list (AD-23).
     *   3. Pull every client whose `note` contains the `[or:{objectId}]`
     *      marker via a direct LIKE query against the table name returned by
     *      `ClientMapper::getTableName()`.
     *   4. Pull every task whose `note` contains the same marker (capped at
     *      {@see TASKS_PER_OBJECT}) via the lazily-resolved `TaskMapper`.
     *   5. Flatten both into the leaf-row contract.
     *
     * Row shape:
     *   - `type`        — `'client'` or `'task'`
     *   - `id`          — client uuid or task uuid (strings; Time Manager uses
     *                     uuids as the public id)
     *   - `title`       — entity `name`
     *   - `description` — entity `note` with marker stripped
     *   - `url`         — `/index.php/apps/timemanager/clients/{uuid}` for
     *                     clients or `/index.php/apps/timemanager/tasks/{uuid}`
     *                     for tasks
     *   - `data`        — the raw upstream row, useful for the widget
     *
     * @param string              $register Register slug or numeric id (unused — link convention
     *                                      is per-object).
     * @param string              $schema   Schema slug or numeric id (unused).
     * @param string              $objectId Owning object uuid.
     * @param array<string,mixed> $filters  Reserved.
     *
     * @return array<int,array<string,mixed>>
     */
    public function list(string $register, string $schema, string $objectId, array $filters=[]): array
    {
        if ($this->isEnabled() === false) {
            return [];
        }

        try {
            $clientMapper = $this->lookup(serviceName: 'OCA\\TimeManager\\Db\\ClientMapper');
        } catch (Throwable $e) {
            // Time Manager classpath not loaded, or unexpected DI failure
            // — registry tabs degrade to the empty state per AD-23.
            return [];
        }

        $marker = self::MARKER_PREFIX.$objectId.']';

        $clients = $this->findRowsByMarker(
            mapper: $clientMapper,
            markerColumn: 'note',
            marker: $marker,
        );

        // Task lookup is best-effort — a Time Manager install missing
        // the tasks table (older versions / partial migrations) is
        // still surfaced as a clients-only list.
        $taskMapper = null;
        try {
            $taskMapper = $this->lookup(serviceName: 'OCA\\TimeManager\\Db\\TaskMapper');
        } catch (Throwable $e) {
            $taskMapper = null;
        }

        $tasks = [];
        if ($taskMapper !== null) {
            $tasks = $this->findRowsByMarker(
                mapper: $taskMapper,
                markerColumn: 'note',
                marker: $marker,
            );
        }

        $rows = [];
        foreach ($clients as $client) {
            $rows[] = $this->normaliseClient(client: $client, objectId: $objectId);
        }

        $taskCount = 0;
        foreach ($tasks as $task) {
            if ($taskCount >= self::TASKS_PER_OBJECT) {
                break;
            }

            $rows[] = $this->normaliseTask(task: $task, objectId: $objectId);
            $taskCount++;
        }

        return $rows;
    }//end list()

    /**
     * Provider health descriptor.
     *
     * Mirrors the umbrella registry's missing-app shape — status
     * `'unavailable'` + a human-readable message — when the NC Time
     * Manager app isn't installed. Otherwise the provider self-reports
     * as configured + ok; auth is implicit (the integration uses the
     * current NC user's permissions).
     *
     * @return array<string,mixed>
     */
    public function health(): array
    {
        $available = $this->isEnabled();
        return [
            'status'     => $available === true ? 'ok' : 'unavailable',
            'authStatus' => 'configured',
            'message'    => $available === true ? null : 'NC Time Manager app is not installed',
        ];
    }//end health()

    /**
     * Resolve a service from the container.
     *
     * Routes through the test-injected container override when
     * present, otherwise delegates to NC's global `\OCP\Server` static
     * lookup. Encapsulating the resolution here keeps the rest of the
     * provider DI-clean and lets the unit tests inject mock mappers
     * without touching the production code path.
     *
     * @param string $serviceName Fully qualified class name to resolve.
     *
     * @return object Resolved service instance.
     *
     * @SuppressWarnings(PHPMD.StaticAccess) `\OCP\Server::get()` is the
     * NC-canonical service-locator entry point for late-bound classes;
     * see MarkerLookupTrait.php for the same pattern.
     */
    private function lookup(string $serviceName): object
    {
        if ($this->container !== null) {
            $resolved = $this->container->get($serviceName);
            if (is_object($resolved) === false) {
                throw new RuntimeException(sprintf('Container returned non-object for %s', $serviceName));
            }

            return $resolved;
        }

        return Server::get($serviceName);
    }//end lookup()

    /**
     * Find Time Manager entities whose marker column contains the OR
     * object marker.
     *
     * `ClientMapper` and `TaskMapper` extend `ObjectMapper` which only
     * exposes user-scoped finders; neither has a marker-aware finder
     * so we run the LIKE directly via the table name returned by the
     * mapper's public `getTableName()` accessor. Any failure degrades
     * to an empty list.
     *
     * @param object $mapper       NC Time Manager mapper instance (typed
     *                             loosely so the file loads without the
     *                             Time Manager classpath).
     * @param string $markerColumn Column name carrying the marker
     *                             substring (currently always `note`).
     * @param string $marker       Full marker substring including the
     *                             `[or:` prefix and `]` suffix.
     *
     * @return array<int,object> Matching rows (loose-typed for the same reason).
     */
    private function findRowsByMarker(object $mapper, string $markerColumn, string $marker): array
    {
        try {
            $tableName = $mapper->getTableName();
            $qb        = $this->db->getQueryBuilder();
            $qb->select('*')
                ->from($tableName)
                ->where(
                    $qb->expr()->iLike(
                        $markerColumn,
                        $qb->createNamedParameter('%'.$this->db->escapeLikeParameter($marker).'%')
                    )
                )
                ->orderBy('created', 'DESC');

            $result = $qb->executeQuery();
            $rows   = [];
            $row    = $result->fetch();
            while ($row !== false) {
                $rows[] = (object) $row;
                $row    = $result->fetch();
            }

            $result->closeCursor();
            return $rows;
        } catch (Throwable $e) {
            return [];
        }//end try
    }//end findRowsByMarker()

    /**
     * Normalise a raw `timemanager_client` row into a registry leaf row.
     *
     * The `note` field is shown with the OR marker stripped so the
     * registry tab doesn't leak the linking convention into the UI
     * label.
     *
     * @param object $client   Raw client row (cast from associative array
     *                         in {@see findRowsByMarker}).
     * @param string $objectId Owning object uuid (used to strip the marker).
     *
     * @return array<string,mixed>
     */
    private function normaliseClient(object $client, string $objectId): array
    {
        $marker      = self::MARKER_PREFIX.$objectId.']';
        $rawNote     = isset($client->note) === true ? (string) $client->note : '';
        $cleanNote   = trim(str_replace($marker, '', $rawNote));
        $uuid        = isset($client->uuid) === true ? (string) $client->uuid : '';
        $name        = isset($client->name) === true ? (string) $client->name : '';
        $lastChanged = $this->readTimestamp(row: $client, field: 'changed');

        return [
            'type'        => 'client',
            'id'          => $uuid,
            'title'       => $name,
            'description' => $cleanNote,
            'url'         => '/index.php/apps/timemanager/clients/'.$uuid,
            'lastUpdated' => $lastChanged,
            'data'        => [
                'uuid'        => $uuid,
                'name'        => $name,
                'note'        => $cleanNote,
                'lastChanged' => $lastChanged,
            ],
        ];
    }//end normaliseClient()

    /**
     * Normalise a raw `timemanager_task` row into a registry leaf row.
     *
     * Tasks belong to a project (and projects belong to a client); we
     * surface the project uuid in `data` so the widget can group tasks
     * by project. The `note` field is shown with the OR marker
     * stripped.
     *
     * @param object $task     Raw task row (cast from associative array
     *                         in {@see findRowsByMarker}).
     * @param string $objectId Owning object uuid (used to strip the marker).
     *
     * @return array<string,mixed>
     */
    private function normaliseTask(object $task, string $objectId): array
    {
        $marker    = self::MARKER_PREFIX.$objectId.']';
        $rawNote   = isset($task->note) === true ? (string) $task->note : '';
        $cleanNote = trim(str_replace($marker, '', $rawNote));
        $uuid      = isset($task->uuid) === true ? (string) $task->uuid : '';
        $name      = isset($task->name) === true ? (string) $task->name : '';
        // Note: NC Time Manager exposes the project foreign-key column
        // as snake_case `project_uuid` — dynamic property access via a
        // variable column name avoids declaring a camelCase alias that
        // phpcs would flag.
        $projectUuidColumn = 'project_uuid';
        $projectUuid       = isset($task->{$projectUuidColumn}) === true ? (string) $task->{$projectUuidColumn} : '';
        $lastChanged       = $this->readTimestamp(row: $task, field: 'changed');

        return [
            'type'        => 'task',
            'id'          => $uuid,
            'title'       => $name,
            'description' => $cleanNote,
            'url'         => '/index.php/apps/timemanager/tasks/'.$uuid,
            'lastUpdated' => $lastChanged,
            'data'        => [
                'uuid'        => $uuid,
                'name'        => $name,
                'note'        => $cleanNote,
                'projectUuid' => $projectUuid,
                'lastChanged' => $lastChanged,
            ],
        ];
    }//end normaliseTask()

    /**
     * Read a timestamp-shaped field from the raw row.
     *
     * Time Manager stores timestamps as ISO8601 strings (`changed`,
     * `created`); we hand them through verbatim so the frontend can
     * format with its own locale. Returns `null` when the field is
     * absent or empty.
     *
     * @param object $row   Source row.
     * @param string $field Property name.
     *
     * @return string|null
     */
    private function readTimestamp(object $row, string $field): ?string
    {
        if (isset($row->{$field}) === false) {
            return null;
        }

        $value = (string) $row->{$field};
        return $value === '' ? null : $value;
    }//end readTimestamp()
}//end class
