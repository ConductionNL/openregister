<?php

/**
 * AnalyticsProvider — exposes NC Analytics reports linked to an
 * OpenRegister object via the Tier-2 `openregister_analytics_links`
 * table.
 *
 * Pre-Tier-2 the provider matched a `[or:{objectUuid}]` marker embedded
 * in the report's `name` field (wave-2.2). Tier-2 (this file) reads the
 * dedicated link table instead — the marker convention is retained as a
 * backwards-compat fallback for reports that pre-date the link table.
 *
 * Storage strategy is `link-table` — the link rows live in OR; the
 * upstream `analytics_report` table is only read for live title / type
 * data via the wrapping
 * {@see \OCA\OpenRegister\Service\AnalyticsLinkService}.
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

use OCA\OpenRegister\Db\AnalyticsLink;
use OCA\OpenRegister\Db\AnalyticsLinkMapper;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\IL10N;
use Throwable;

class AnalyticsProvider extends AbstractIntegrationProvider
{
    use MarkerLookupTrait;

    private const REQUIRED_APP = 'analytics';

    private const MARKER_PREFIX = '[or:';

    /**
     * Constructor.
     *
     * @param IDBConnection       $db                  NC DB connection.
     * @param IAppManager         $appManager          NC app manager.
     * @param IL10N               $l10n                Localisation.
     * @param AnalyticsLinkMapper $analyticsLinkMapper Analytics-link mapper (Tier-2 link table).
     */
    public function __construct(
        private IDBConnection $db,
        private IAppManager $appManager,
        private IL10N $l10n,
        private AnalyticsLinkMapper $analyticsLinkMapper,
    ) {
    }//end __construct()

    public function getId(): string
    {
        return 'analytics';
    }//end getId()

    public function getLabel(): string
    {
        return $this->l10n->t('Analytics');
    }//end getLabel()

    public function getIcon(): string
    {
        return 'ChartBar';
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
     * List linked Analytics reports for an OR object.
     *
     * Reads the Tier-2 link table first; if no link rows exist it falls
     * back to the legacy `[or:{uuid}]` marker scan in
     * `analytics_report.name` (wave-2.2 convention) so reports that
     * pre-date the link table still surface.
     *
     * @param string $register Register slug for the parent object.
     * @param string $schema   Schema slug for the parent object.
     * @param string $objectId UUID of the OR object whose rows we want.
     * @param array  $filters  Optional registry filters (unused).
     *
     * @return array List of registry leaf rows.
     *
     * @spec openspec/specs/integration-analytics/spec.md
     */
    public function list(string $register, string $schema, string $objectId, array $filters=[]): array
    {
        if ($this->isEnabled() === false) {
            return [];
        }

        // Tier-2 path: read from the link table.
        try {
            $linkRows = $this->analyticsLinkMapper->findByObjectUuid($objectId);
        } catch (Throwable $e) {
            $linkRows = [];
        }

        if (count($linkRows) > 0) {
            return array_map(
                fn (AnalyticsLink $link): array => $this->rowFromLink(link: $link),
                $linkRows
            );
        }

        // Backwards-compat fallback: scan the legacy `[or:{uuid}]` marker
        // in `analytics_report.name` (wave-2.2 marker-on-name convention).
        $marker = self::MARKER_PREFIX.$objectId.']';
        $rows   = $this->findByMarker(
            db: $this->db,
            table: 'analytics_report',
            markerColumn: 'name',
            marker: $marker,
            extraColumns: ['subheader', 'type'],
            idColumn: 'id',
        );

        return array_map(
                static function (array $row): array {
                    return [
                        'id'    => (string) ($row['id'] ?? ''),
                        'title' => (string) ($row['name'] ?? ''),
                        'url'   => '/index.php/apps/analytics/#/r/'.(string) ($row['id'] ?? ''),
                        'data'  => $row,
                    ];
                },
                $rows
                );
    }//end list()

    /**
     * Convert an AnalyticsLink row into the registry leaf-row shape.
     *
     * @param AnalyticsLink $link Link row from the mapper.
     *
     * @return array<string,mixed>
     */
    private function rowFromLink(AnalyticsLink $link): array
    {
        $reportId = (int) $link->getReportId();
        $data     = $link->jsonSerialize();

        return [
            'id'        => (string) $reportId,
            'title'     => (string) $link->getReportTitle(),
            'url'       => '/index.php/apps/analytics/#/r/'.$reportId,
            'subheader' => $link->getSubheader(),
            'data'      => $data,
        ];
    }//end rowFromLink()

    /**
     * Provider health descriptor (enabled/disabled echo).
     *
     * @return array<string,mixed>
     *
     * @spec exclude Static enabled/disabled descriptor echoing isEnabled() — no standalone health behaviour;
     *              the health/OCS contract is owned by pluggable-integration-registry task-2.
     */
    public function health(): array
    {
        $available = $this->isEnabled();

        $status = 'unavailable';
        if ($available === true) {
            $status = 'ok';
        }

        $message = 'NC Analytics app is not installed';
        if ($available === true) {
            $message = null;
        }

        return [
            'status'     => $status,
            'authStatus' => 'configured',
            'message'    => $message,
        ];
    }//end health()
}//end class
