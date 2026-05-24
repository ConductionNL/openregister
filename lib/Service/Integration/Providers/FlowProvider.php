<?php

/**
 * FlowProvider — exposes NC Flow (workflowengine) operations to the OR
 * registry so admins can see which flow rules are wired to an OR
 * register/schema/object.
 *
 * Storage strategy is `link-table`: the operation rows live in NC Flow's
 * own `flow_operations` table. The provider reads them via the public
 * `OCA\WorkflowEngine\Manager` API rather than running raw SQL, so
 * upstream schema changes don't silently break us.
 *
 * Filtering rules: admin-scoped operations are returned (NC Flow is
 * admin-gated; per-user flows are out of scope for object-level
 * surfacing). Operations whose `name` carries the
 * `[or:{objectUuid}]` marker are prioritised; otherwise all admin-scoped
 * operations are listed for audit visibility — this matches the spec's
 * "schema-scoped linking (default)" requirement, since admins typically
 * scope flows to an Entity class that maps 1:1 to an OR schema.
 *
 * When the `workflowengine` app is missing or its Manager isn't
 * resolvable, `list()` returns the empty array and `health()` reports
 * `status: 'unavailable'` — never throws (AD-23).
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
 * @spec openspec/changes/integration-flow/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- self-documenting IntegrationProvider metadata getters mirror the contract in the interface.

use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCA\WorkflowEngine\Helper\ScopeContext;
use OCA\WorkflowEngine\Manager as FlowManager;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\WorkflowEngine\IManager as IFlowManager;
use Throwable;

/**
 * NC Flow integration provider.
 *
 * Real implementation backed by `OCA\WorkflowEngine\Manager`.
 * Replaces the prior MarkerLookupTrait copy-paste so that OR no longer
 * runs raw `flow_operations` queries — that table can change shape per
 * NC release; the Manager API is the stable surface.
 */
class FlowProvider extends AbstractIntegrationProvider
{

    /**
     * NC app id required for this integration.
     *
     * @var string
     */
    private const REQUIRED_APP = 'workflowengine';

    /**
     * Marker prefix used to recognise OR-linked flow operations.
     *
     * Admins who want a flow scoped to a specific OR object embed the
     * marker `[or:{objectUuid}]` in the operation's `name`. Matching is
     * substring-based on the name field — same convention as the rest
     * of the leaf providers (Tasks, Polls, Activity, ...).
     *
     * @var string
     */
    private const MARKER_PREFIX = '[or:';

    /**
     * Cached Manager handle for the current request.
     *
     * Resolved lazily inside `getManager()` so the provider stays
     * loadable when `workflowengine` is disabled (the import is
     * resolved at use-time; PHP doesn't fail to load a class just
     * because one of its `use` statements references a missing class).
     *
     * @var FlowManager|null
     */
    private ?FlowManager $manager = null;

    /**
     * Constructor.
     *
     * Signature is intentionally `(db, appManager, l10n)` so that the
     * shared "greenfield providers" registration block in
     * Application.php keeps working without a per-provider override.
     * The actual NC Flow Manager is resolved on demand via `Server::get`
     * inside `list()` / `getCount()` — that lets the provider load
     * cleanly even when the `workflowengine` app is disabled (no class
     * resolution at construction time).
     *
     * @param IDBConnection $db         Reserved for future direct queries
     *                                  (e.g. fire-events panel); not used
     *                                  in the current Manager-backed path.
     * @param IAppManager   $appManager NC app manager — gates `isEnabled`.
     * @param IL10N         $l10n       Localisation for the leaf label.
     *
     * @return void
     */
    public function __construct(
        private IDBConnection $db,
        private IAppManager $appManager,
        private IL10N $l10n,
    ) {
    }//end __construct()

    public function getId(): string
    {
        return 'flow';
    }//end getId()

    public function getLabel(): string
    {
        return $this->l10n->t('Automation');
    }//end getLabel()

    public function getIcon(): string
    {
        return 'RobotOutline';
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

    /**
     * Per the spec's "Permission Inheritance" requirement: NC Flow is
     * admin-gated, so the registry surface is too. Non-admin users see
     * no Flow tab and `/api/integrations/flow` returns 403.
     *
     * @return string|null
     */
    public function requiresPermission(): ?string
    {
        return 'admin';
    }//end requiresPermission()

    public function isEnabled(): bool
    {
        return $this->appManager->isInstalled(self::REQUIRED_APP);
    }//end isEnabled()

    /**
     * List NC Flow operations relevant to an OR object.
     *
     * Strategy:
     *   1. Bail with `[]` when `workflowengine` is disabled — health()
     *      reports the actual cause; throwing would crash sub-resource
     *      dispatch.
     *   2. Resolve `OCA\WorkflowEngine\Manager` via `Server::get`.
     *      If resolution fails (e.g. the app is installed but the
     *      classmap hasn't loaded yet, or constructor deps are missing
     *      in a CLI bootstrap) we degrade to `[]`.
     *   3. Call `getAllOperations(ScopeContext::SCOPE_ADMIN)`. NC's
     *      Manager returns a `class => [rows]` map; we flatten it and
     *      optionally filter to rows whose `name` carries the OR marker
     *      for this object.
     *
     * @param string              $register Register slug or numeric id
     *                                      (unused — flow ops aren't OR-scoped).
     * @param string              $schema   Schema slug or numeric id (unused).
     * @param string              $objectId Owning object uuid (used to build
     *                                      the optional marker filter).
     * @param array<string,mixed> $filters  Optional registry filters; honoured
     *                                      key: `_search` (substring match
     *                                      against operation name).
     *
     * @return array<int,array<string,mixed>> Flow operation rows.
     */
    public function list(string $register, string $schema, string $objectId, array $filters=[]): array
    {
        unset($register, $schema);

        if ($this->isEnabled() === false) {
            return [];
        }

        $manager = $this->getManager();
        if ($manager === null) {
            return [];
        }

        try {
            $byClass = $manager->getAllOperations(new ScopeContext(IFlowManager::SCOPE_ADMIN));
        } catch (Throwable $e) {
            // Schema drift / DB unavailable — match AD-23 graceful degrade.
            return [];
        }

        $matched = $this->normaliseOperations(
            byClass: $byClass,
            marker: self::MARKER_PREFIX.$objectId.']',
            search: (string) ($filters['_search'] ?? '')
        );

        // If any rows carry the OR marker for this object, narrow to
        // those — that's the "linked" set. Otherwise return everything
        // admin-scoped so the audit view stays useful (the spec calls
        // this "schema-scoped linking (default)").
        $linked = array_values(
            array_filter(
                $matched,
                static function (array $row): bool {
                    return ($row['hasMarker'] ?? false) === true;
                }
            )
        );

        if ($linked !== []) {
            return $linked;
        }

        return $matched;
    }//end list()

    /**
     * Flatten and normalise the `class => [rows]` map returned by
     * `Manager::getAllOperations` into the registry's leaf row shape.
     *
     * Filters by `$search` (substring match on operation name) when
     * non-empty. Marker presence is recorded on each row so the caller
     * can choose to narrow to marker-matched rows.
     *
     * @param array<string,mixed> $byClass NC Manager-shaped operations map.
     * @param string              $marker  Full marker string to test (`[or:{uuid}]`).
     * @param string              $search  Substring filter for op name; empty = no filter.
     *
     * @return array<int,array<string,mixed>> Normalised registry rows.
     */
    private function normaliseOperations(array $byClass, string $marker, string $search): array
    {
        $matched = [];

        foreach ($byClass as $operationClass => $rows) {
            if (is_array($rows) === false) {
                continue;
            }

            foreach ($rows as $row) {
                if (is_array($row) === false) {
                    continue;
                }

                $normalised = $this->normaliseOperationRow(
                    row: $row,
                    fallbackClass: (string) $operationClass,
                    marker: $marker,
                    search: $search
                );
                if ($normalised !== null) {
                    $matched[] = $normalised;
                }
            }
        }//end foreach

        return $matched;
    }//end normaliseOperations()

    /**
     * Normalise a single NC flow_operations row.
     *
     * Returns null when the `$search` filter is non-empty and the
     * operation name doesn't contain it (case-insensitive).
     *
     * @param array<string,mixed> $row           Raw NC operation row.
     * @param string              $fallbackClass Class key from the parent map (used when `row.class` is missing).
     * @param string              $marker        Full marker string to test against the operation name.
     * @param string              $search        Substring search; empty disables the filter.
     *
     * @return array<string,mixed>|null
     */
    private function normaliseOperationRow(array $row, string $fallbackClass, string $marker, string $search): ?array
    {
        $name = (string) ($row['name'] ?? '');

        if ($search !== '' && stripos($name, $search) === false) {
            return null;
        }

        return [
            'id'        => (string) ($row['id'] ?? ''),
            'title'     => $name,
            'class'     => (string) ($row['class'] ?? $fallbackClass),
            'entity'    => (string) ($row['entity'] ?? ''),
            'operation' => (string) ($row['operation'] ?? ''),
            'hasMarker' => str_contains($name, $marker),
            'url'       => '/index.php/settings/admin/workflow',
            'data'      => $row,
        ];
    }//end normaliseOperationRow()

    public function health(): array
    {
        if ($this->isEnabled() === false) {
            return [
                'status'     => 'unavailable',
                'authStatus' => 'configured',
                'message'    => 'NC Flow (workflowengine) app is not installed',
            ];
        }

        $manager = $this->getManager();
        if ($manager === null) {
            return [
                'status'     => 'degraded',
                'authStatus' => 'configured',
                'message'    => 'NC Flow Manager could not be resolved from the container',
            ];
        }

        return [
            'status'     => 'ok',
            'authStatus' => 'configured',
            'message'    => null,
        ];
    }//end health()

    /**
     * Resolve the late-bound `OCA\WorkflowEngine\Manager`.
     *
     * Cached per-request. Returns null when:
     *   - the `workflowengine` app is disabled (the class isn't on the
     *     classpath); or
     *   - the container fails to build the service (missing
     *     dependencies in a non-standard bootstrap, e.g. a CLI command
     *     without the full event-dispatcher graph).
     *
     * Resolution failures are intentionally swallowed — health()
     * surfaces the user-visible signal.
     *
     * @return FlowManager|null
     */
    protected function getManager(): ?FlowManager
    {
        if ($this->manager !== null) {
            return $this->manager;
        }

        if (class_exists(FlowManager::class) === false) {
            return null;
        }

        try {
            $resolved      = \OCP\Server::get(FlowManager::class);
            $this->manager = $resolved;
            return $this->manager;
        } catch (Throwable $e) {
            return null;
        }
    }//end getManager()
}//end class
