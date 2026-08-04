<?php

/**
 * ActivityProvider — exposes NC Activity entities linked to an OpenRegister
 * object via a `[or:{objectUuid}]` marker in the entity's `subject`
 * field.
 *
 * Storage strategy is `query-time` — the marker lives in the upstream
 * app's own table (`activity`), not in OR. Every `list()` call runs a
 * live LIKE query against the upstream `activity` table; OpenRegister
 * persists nothing about the link itself.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing

use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\IL10N;

class ActivityProvider extends AbstractIntegrationProvider
{
    use MarkerLookupTrait;

    private const REQUIRED_APP = 'activity';

    private const MARKER_PREFIX = '[or:';

    /**
     * Construct the provider with required dependencies.
     *
     * @param IDBConnection $db         Database connection.
     * @param IAppManager   $appManager App manager for installation checks.
     * @param IL10N         $l10n       Localisation service.
     *
     * @spec openspec/changes/retrofit-2026-05-24-activity-provider/tasks.md#task-2
     */
    public function __construct(
        private IDBConnection $db,
        private IAppManager $appManager,
        private IL10N $l10n,
    ) {
    }//end __construct()

    /**
     * Return the unique provider identifier.
     *
     * @return string Provider ID.
     *
     * @spec openspec/changes/retrofit-2026-05-24-activity-provider/tasks.md#task-2
     */
    public function getId(): string
    {
        return 'activity';
    }//end getId()

    /**
     * Return the human-readable provider label.
     *
     * @return string Localised label.
     *
     * @spec openspec/changes/retrofit-2026-05-24-activity-provider/tasks.md#task-2
     */
    public function getLabel(): string
    {
        return $this->l10n->t('Activity');
    }//end getLabel()

    /**
     * Return the icon identifier for this provider.
     *
     * @return string Icon name.
     *
     * @spec openspec/changes/retrofit-2026-05-24-activity-provider/tasks.md#task-2
     */
    public function getIcon(): string
    {
        return 'Timeline';
    }//end getIcon()

    /**
     * Return the group this provider belongs to.
     *
     * @return string|null Group identifier or null if ungrouped.
     *
     * @spec openspec/changes/retrofit-2026-05-24-activity-provider/tasks.md#task-2
     */
    public function getGroup(): ?string
    {
        return 'workflow';
    }//end getGroup()

    /**
     * Return the Nextcloud app this provider requires.
     *
     * @return string|null Required app ID or null if none.
     *
     * @spec openspec/changes/retrofit-2026-05-24-activity-provider/tasks.md#task-2
     */
    public function getRequiredApp(): ?string
    {
        return self::REQUIRED_APP;
    }//end getRequiredApp()

    /**
     * Return the storage strategy for this provider.
     *
     * @return string Storage strategy identifier.
     *
     * @spec openspec/changes/retrofit-2026-05-24-activity-provider/tasks.md#task-2
     */
    public function getStorageStrategy(): string
    {
        return 'query-time';
    }//end getStorageStrategy()

    /**
     * Check whether this provider is currently available.
     *
     * @return bool True when the required NC app is installed.
     *
     * @spec openspec/changes/retrofit-2026-05-24-activity-provider/tasks.md#task-2
     */
    public function isEnabled(): bool
    {
        return $this->appManager->isInstalled(self::REQUIRED_APP);
    }//end isEnabled()

    /**
     * List linked Activity entities for an OR object.
     *
     * Linking convention: the entity's `subject` field contains
     * the marker `[or:{objectUuid}]`. The trait runs the LIKE query;
     * rows are normalised into the registry leaf row shape.
     *
     * Tier-2 narrowing: optional `type` / `actor` / `after` filters may
     * be passed in `$filters`. They are applied in PHP over the rows the
     * MarkerLookupTrait returns — the marker LIKE query itself is left
     * untouched so the wave-5.3 carve-out (NC Activity's single string
     * `subject` column as the marker target) stays canonical.
     *
     * @param string $register Register slug for the parent object.
     * @param string $schema   Schema slug for the parent object.
     * @param string $objectId UUID of the OR object whose rows we want.
     * @param array  $filters  Optional filters: `type`, `actor`, `after` (Unix ts).
     *
     * @return array<int,array<string,mixed>> List of registry leaf rows.
     *
     * @spec openspec/changes/retrofit-2026-05-24-activity-provider/tasks.md#task-3
     */
    public function list(string $register, string $schema, string $objectId, array $filters=[]): array
    {
        if ($this->isEnabled() === false) {
            return [];
        }

        $marker = self::MARKER_PREFIX.$objectId.']';
        $rows   = $this->findByMarker(
            db: $this->db,
            table: 'activity',
            markerColumn: 'subject',
            marker: $marker,
            extraColumns: ['type', 'affecteduser', 'timestamp', 'object_id'],
            idColumn: 'activity_id',
        );

        $rows = $this->applyFilters(rows: $rows, filters: $filters);

        return array_map(
                static function (array $row): array {
                    // Flatten the activity event so CnActivityTab can read
                    // type / timestamp / actor at the row root without
                    // hand-walking `data.*`. `data` is retained for any
                    // generic consumer that still wants the raw row.
                    return [
                        'id'           => (string) ($row['activity_id'] ?? ''),
                        'title'        => (string) ($row['subject'] ?? ''),
                        'subject'      => (string) ($row['subject'] ?? ''),
                        'type'         => (string) ($row['type'] ?? ''),
                        'timestamp'    => (int) ($row['timestamp'] ?? 0),
                        'affecteduser' => (string) ($row['affecteduser'] ?? ''),
                        'actor_id'     => (string) ($row['affecteduser'] ?? ''),
                        'object_id'    => (string) ($row['object_id'] ?? ''),
                        'url'          => '/index.php/apps/activity/'.(string) ($row['activity_id'] ?? ''),
                        'data'         => $row,
                    ];
                },
                $rows
                );
    }//end list()

    /**
     * Apply Tier-2 type/actor/after filters over marker-matched rows.
     *
     * Filtering is done in PHP (not in the marker query) to preserve the
     * wave-5.3 MarkerLookupTrait carve-out intact.
     *
     * @param array $rows    Rows returned by the marker lookup.
     * @param array $filters Optional `type` / `actor` / `after` filters.
     *
     * @return array Filtered rows.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) — three independent
     * predicate filters short-circuit; splitting them would only add
     * indirection.
     */
    private function applyFilters(array $rows, array $filters): array
    {
        $type  = '';
        $actor = '';
        $after = 0;
        if (isset($filters['type']) === true) {
            $type = (string) $filters['type'];
        }

        if (isset($filters['actor']) === true) {
            $actor = (string) $filters['actor'];
        }

        if (isset($filters['after']) === true) {
            $after = (int) $filters['after'];
        }

        if ($type === '' && $actor === '' && $after === 0) {
            return $rows;
        }

        return array_values(
            array_filter(
                $rows,
                static function (array $row) use ($type, $actor, $after): bool {
                    if ($type !== '' && (string) ($row['type'] ?? '') !== $type) {
                        return false;
                    }

                    if ($actor !== '' && (string) ($row['affecteduser'] ?? '') !== $actor) {
                        return false;
                    }

                    if ($after > 0 && (int) ($row['timestamp'] ?? 0) < $after) {
                        return false;
                    }

                    return true;
                }
            )
        );
    }//end applyFilters()

    /**
     * Return the health descriptor for this provider.
     *
     * @return array<string,mixed> Health status payload.
     *
     * @spec openspec/changes/retrofit-2026-05-24-activity-provider/tasks.md#task-4
     */
    public function health(): array
    {
        $available = $this->isEnabled();
        $status    = 'unavailable';
        $message   = 'NC Activity app is not installed';
        if ($available === true) {
            $status  = 'ok';
            $message = null;
        }

        return [
            'status'     => $status,
            'authStatus' => 'configured',
            'message'    => $message,
        ];
    }//end health()
}//end class
