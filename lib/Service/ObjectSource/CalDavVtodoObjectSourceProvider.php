<?php

/**
 * CalDavVtodoObjectSourceProvider — serves a schema's objects live from the
 * acting user's CalDAV VTODOs (read-only).
 *
 * The authoritative record is the VTODO; this provider projects each VTODO as a
 * virtual ObjectEntity (mapping SUMMARY→title, DESCRIPTION→description,
 * DUE→dueDate, STATUS→status, plus the X-OPENREGISTER-* link metadata) and never
 * writes back. Reuses the existing TaskService CalDAV plumbing, which is already
 * scoped to the logged-in user's calendars — so another user's tasks are simply
 * absent (denied == not-found, no enumeration oracle).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
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
 * @spec openspec/changes/object-source-providers/tasks.md#task-5.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\TaskService;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read-only object-source provider backed by CalDAV VTODOs.
 */
class CalDavVtodoObjectSourceProvider implements ObjectSourceProvider
{
    /**
     * Constructor.
     *
     * @param TaskService     $taskService The CalDAV VTODO read/list service.
     * @param IAppManager     $appManager  App availability checks.
     * @param LoggerInterface $logger      Logger for read failures.
     *
     * @return void
     */
    public function __construct(
        private readonly TaskService $taskService,
        private readonly IAppManager $appManager,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * {@inheritDoc}
     *
     * @return string The provider id.
     *
     * @spec openspec/changes/object-source-providers/tasks.md#task-5.1
     */
    public function getId(): string
    {
        return 'caldav-vtodo';
    }//end getId()

    /**
     * {@inheritDoc}
     *
     * Enabled when CalDAV is available. VTODOs live in the user's CalDAV
     * calendars served by the CORE `dav` app — the `tasks`/`calendar` apps are
     * only optional UIs, so this checks `dav` (with tasks/calendar as positive
     * signals too).
     *
     * @return bool True when CalDAV VTODO reads are available.
     *
     * @spec openspec/changes/object-source-providers/tasks.md#task-5.2
     */
    public function isEnabled(): bool
    {
        try {
            return ($this->appManager->isInstalled('dav') === true
                || $this->appManager->isInstalled('tasks') === true
                || $this->appManager->isInstalled('calendar') === true);
        } catch (Throwable $e) {
            return false;
        }
    }//end isEnabled()

    /**
     * {@inheritDoc}
     *
     * @param Register             $register The register.
     * @param Schema               $schema   The sourced schema.
     * @param string               $id       The VTODO id (calendar-object uri) or uid.
     * @param array<string, mixed> $config   The object-source config block.
     *
     * @return ObjectEntity|null The virtual object, or null when absent/denied.
     *
     * @spec openspec/changes/object-source-providers/tasks.md#task-5.1
     */
    public function find(Register $register, Schema $schema, string $id, array $config=[]): ?ObjectEntity
    {
        foreach ($this->readTasks(config: $config, limit: 1000, offset: 0) as $task) {
            if ($this->matchesScope(task: $task, register: $register, schema: $schema, config: $config) === false) {
                continue;
            }

            if ((string) ($task['id'] ?? '') === $id || (string) ($task['uid'] ?? '') === $id) {
                return $this->toObjectEntity(register: $register, schema: $schema, task: $task);
            }
        }

        return null;
    }//end find()

    /**
     * {@inheritDoc}
     *
     * @param Register             $register The register.
     * @param Schema               $schema   The sourced schema.
     * @param array<string, mixed> $query    Query (filters/limit/offset).
     * @param array<string, mixed> $config   The object-source config block.
     *
     * @return ObjectEntity[] The matching virtual objects.
     *
     * @spec openspec/changes/object-source-providers/tasks.md#task-5.1
     */
    public function findAll(Register $register, Schema $schema, array $query=[], array $config=[]): array
    {
        $limit  = ($query['limit'] ?? 200);
        $offset = ($query['offset'] ?? 0);
        $status = ($query['filters']['status'] ?? null);

        $objects = [];
        foreach ($this->readTasks(config: $config, limit: (int) $limit, offset: (int) $offset, status: $status) as $task) {
            if ($this->matchesScope(task: $task, register: $register, schema: $schema, config: $config) === false) {
                continue;
            }

            $objects[] = $this->toObjectEntity(register: $register, schema: $schema, task: $task);
        }

        return $objects;
    }//end findAll()

    /**
     * Whether a VTODO is in scope for the bound schema.
     *
     * By default a sourced schema only surfaces VTODOs whose X-OPENREGISTER link
     * points at the bound register AND schema, so each projection shows only its
     * own tasks (not every VTODO the user owns). Config escape hatches:
     *  - `unscoped: true` — surface all of the user's VTODOs (no link filter);
     *  - `register` (int) / `schema` (int) — scope to a different register/schema
     *    than the bound one;
     *  - `schemas` (int[]) — scope to VTODOs linked to ANY of these schema ids.
     *
     * @param array<string, mixed> $task     The VTODO task array.
     * @param Register             $register The bound register.
     * @param Schema               $schema   The bound schema.
     * @param array<string, mixed> $config   The object-source config block.
     *
     * @return bool True when the task is in scope.
     *
     * @spec openspec/changes/object-source-providers/tasks.md#task-5.1
     */
    private function matchesScope(array $task, Register $register, Schema $schema, array $config): bool
    {
        if (($config['unscoped'] ?? false) === true) {
            return true;
        }

        $wantRegister = (int) ($config['register'] ?? $register->getId());
        if ((int) ($task['registerId'] ?? 0) !== $wantRegister) {
            return false;
        }

        $wantSchemas = $config['schemas'] ?? null;
        if (is_array($wantSchemas) === true && empty($wantSchemas) === false) {
            return in_array((int) ($task['schemaId'] ?? 0), array_map('intval', $wantSchemas), true);
        }

        $wantSchema = (int) ($config['schema'] ?? $schema->getId());
        return ((int) ($task['schemaId'] ?? 0) === $wantSchema);
    }//end matchesScope()

    /**
     * {@inheritDoc}
     *
     * @param Register             $register The register.
     * @param Schema               $schema   The sourced schema.
     * @param array<string, mixed> $query    Query (filters).
     * @param array<string, mixed> $config   The object-source config block.
     *
     * @return int The number of matching virtual objects.
     *
     * @spec openspec/changes/object-source-providers/tasks.md#task-5.1
     */
    public function count(Register $register, Schema $schema, array $query=[], array $config=[]): int
    {
        return count($this->findAll(register: $register, schema: $schema, query: $query, config: $config));
    }//end count()

    /**
     * Read VTODO task arrays for the acting user, failing closed to an empty list.
     *
     * @param array<string, mixed> $config The object-source config block.
     * @param int                  $limit  Maximum tasks to read.
     * @param int                  $offset Tasks to skip.
     * @param string|null          $status Optional status filter.
     *
     * @return array<int, array<string, mixed>> The task arrays.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $config reserved for calendar/collection selection.
     *
     * @spec openspec/changes/object-source-providers/tasks.md#task-5.1
     */
    private function readTasks(array $config, int $limit, int $offset, ?string $status=null): array
    {
        try {
            $result = $this->taskService->getAllUserTasks(status: $status, limit: $limit, offset: $offset);
            return array_values($result['results']);
        } catch (Throwable $e) {
            $this->logger->warning(
                '[ObjectSource:caldav-vtodo] could not read VTODOs: '.$e->getMessage()
            );
            return [];
        }
    }//end readTasks()

    /**
     * Map a VTODO task array onto a non-persisted virtual ObjectEntity.
     *
     * @param Register             $register The register.
     * @param Schema               $schema   The sourced schema.
     * @param array<string, mixed> $task     The TaskService VTODO array.
     *
     * @return ObjectEntity The virtual object (never saved).
     *
     * @spec openspec/changes/object-source-providers/tasks.md#task-5.1
     */
    private function toObjectEntity(Register $register, Schema $schema, array $task): ObjectEntity
    {
        $uuid = (string) ($task['uid'] ?? $task['id'] ?? '');

        $data = [
            'title'       => ($task['summary'] ?? ''),
            'description' => ($task['description'] ?? ''),
            'status'      => ($task['status'] ?? ''),
            'dueDate'     => ($task['due'] ?? null),
            'completed'   => ($task['completed'] ?? null),
            'priority'    => ($task['priority'] ?? null),
        ];

        // Merge non-core schema fields round-tripped via X-OPENREGISTER-DATA
        // (e.g. assignee), so the projection is faithful to the bound schema.
        if (empty($task['fields']) === false && is_array($task['fields']) === true) {
            $data = array_merge($data, $task['fields']);
        }

        $entity = new ObjectEntity();
        $entity->setUuid($uuid);
        $entity->setRegister((string) $register->getId());
        $entity->setSchema((string) $schema->getId());
        $entity->setObject($data);

        return $entity;
    }//end toObjectEntity()
}//end class
