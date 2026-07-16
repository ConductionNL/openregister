<?php

/**
 * AnalyticsSeriesService — register / fetch page-level pre-computed chart
 * series, the backend behind the AnalyticsSeries render surface.
 *
 * A leaf app (e.g. procest's SLA dashboard) computes a series — labels +
 * datasets — and registers it under a stable key. The series is stored
 * RBAC-scoped (private / group / public) and exposed as a renderable
 * chart widget through the IntegrationRegistry page-widget surface, which
 * the nc-vue render layer consumes.
 *
 * OR owns the reusable PERSISTENCE + RENDER contract only; the leaf owns
 * the maths. This deliberately mirrors the link-table services
 * (AnalyticsLinkService etc.) so the registry surface stays consistent.
 *
 * RBAC contract (ADR-005, fail-closed):
 *   - register requires a logged-in user; the creator is recorded.
 *   - fetch enforces visibility: 'public' is readable by anyone,
 *     'group'/'private' require the current user to be the creator or an
 *     admin. A disallowed fetch returns null (caller → 404) so the
 *     endpoint does not leak series existence.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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
 * @spec openspec/specs/integration-leaf-foundation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Db\AnalyticsSeries;
use OCA\OpenRegister\Db\AnalyticsSeriesMapper;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * AnalyticsSeriesService — page-level series register / fetch.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Service composes the series mapper, integration
 * registry, user session and group manager — each is required for the register / fetch / RBAC contract.
 */
class AnalyticsSeriesService
{

    /**
     * Allowed visibility tiers.
     *
     * @var string[]
     */
    private const VISIBILITIES = ['private', 'group', 'public'];

    /**
     * Allowed chart types the render layer understands.
     *
     * @var string[]
     */
    private const CHART_TYPES = ['line', 'bar', 'pie', 'doughnut', 'area', 'radar'];

    /**
     * Constructor.
     *
     * @param AnalyticsSeriesMapper $mapper       Series persistence.
     * @param IntegrationRegistry   $registry     Registry for the page-widget surface.
     * @param IUserSession          $userSession  Current user.
     * @param IGroupManager         $groupManager Group / admin checks.
     *
     * @return void
     */
    public function __construct(
        private AnalyticsSeriesMapper $mapper,
        private IntegrationRegistry $registry,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
    ) {
    }//end __construct()

    /**
     * Authorisation guard for the register (write) path — a series may
     * only be registered by an authenticated user. Throws fail-closed
     * (ADR-005) so the controller surfaces 400/401 rather than silently
     * writing. Called explicitly from the controller so the authz
     * decision is visible at the endpoint boundary.
     *
     * @return void
     *
     * @throws InvalidArgumentException When no user is logged in.
     *
     * @spec openspec/specs/integration-leaf-foundation/spec.md
     */
    public function ensureCanRegister(): void
    {
        if ($this->userSession->getUser() === null) {
            throw new InvalidArgumentException('A logged-in user is required to register a series');
        }
    }//end ensureCanRegister()

    /**
     * Authorisation guard for the fetch (read) path — resolves the series
     * RBAC-scoped and returns it only when the current user may read it.
     * Returns null on unknown OR disallowed so the controller answers a
     * uniform 404 (no enumeration oracle, ADR-005 fail-closed). Called
     * explicitly from the controller so the authz decision is visible at
     * the endpoint boundary.
     *
     * @param string $seriesKey The series key.
     *
     * @return array<string,mixed>|null The render contract, or null.
     *
     * @spec openspec/specs/integration-leaf-foundation/spec.md
     */
    public function ensureReadableOrNull(string $seriesKey): ?array
    {
        return $this->fetch(seriesKey: $seriesKey);
    }//end ensureReadableOrNull()

    /**
     * Register (create or replace) a page-level series.
     *
     * Upserts by `seriesKey`: re-registering the same key updates the
     * payload in place so a leaf can refresh its dashboard data without
     * accumulating duplicates. Also (re)declares the matching page widget
     * on the IntegrationRegistry so the render layer can discover it.
     *
     * @param string                         $seriesKey  Stable series key.
     * @param array<int,mixed>               $labels     X-axis labels.
     * @param array<int,array<string,mixed>> $datasets   Named datasets.
     * @param string|null                    $title      Chart title.
     * @param string                         $chartType  Chart type hint.
     * @param string                         $visibility 'private'|'group'|'public'.
     * @param int|null                       $registerId Optional register scope.
     * @param int|null                       $schemaId   Optional schema scope.
     *
     * @return array<string,mixed> The stored series render contract.
     *
     * @throws InvalidArgumentException On invalid key / visibility / chart type.
     *
     * @spec openspec/specs/integration-leaf-foundation/spec.md
     */
    public function register(
        string $seriesKey,
        array $labels,
        array $datasets,
        ?string $title=null,
        string $chartType='line',
        string $visibility='private',
        ?int $registerId=null,
        ?int $schemaId=null
    ): array {
        if (trim($seriesKey) === '') {
            throw new InvalidArgumentException('seriesKey is required to register a series');
        }

        if (in_array($visibility, self::VISIBILITIES, true) === false) {
            throw new InvalidArgumentException('Invalid visibility — expected one of: '.implode(', ', self::VISIBILITIES));
        }

        if (in_array($chartType, self::CHART_TYPES, true) === false) {
            throw new InvalidArgumentException('Invalid chartType — expected one of: '.implode(', ', self::CHART_TYPES));
        }

        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new InvalidArgumentException('A logged-in user is required to register a series');
        }

        $now      = new DateTime();
        $existing = $this->mapper->findByKey($seriesKey);

        $entity = ($existing ?? new AnalyticsSeries());
        $entity->setSeriesKey($seriesKey);
        $entity->setRegisterId($registerId);
        $entity->setSchemaId($schemaId);
        $entity->setTitle($title);
        $entity->setChartType($chartType);
        $entity->setLabels(json_encode(array_values($labels)));
        $entity->setDatasets(json_encode(array_values($datasets)));
        $entity->setVisibility($visibility);
        $entity->setUpdatedAt($now);

        if ($existing === null) {
            $entity->setCreatedBy($user->getUID());
            $entity->setCreatedAt($now);
            $saved = $this->mapper->insert($entity);
        } else {
            $saved = $this->mapper->update($entity);
        }

        // (Re)declare the page widget so the render layer can discover it.
        // First-write-wins inside the registry; harmless on a refresh.
        $this->registry->registerPageWidget(
                [
                    'id'         => 'analytics-series:'.$seriesKey,
                    'type'       => 'chart',
                    'title'      => $title,
                    'providerId' => 'analytics-series',
                    'config'     => [
                        'seriesKey'  => $seriesKey,
                        'chartType'  => $chartType,
                        'registerId' => $registerId,
                        'schemaId'   => $schemaId,
                    ],
                ]
                );

        return $saved->jsonSerialize();
    }//end register()

    /**
     * Fetch a registered series by key, RBAC-scoped.
     *
     * Returns null when the series is unknown or the current user is not
     * permitted to read it, so the controller can return a uniform 404.
     *
     * @param string $seriesKey The series key.
     *
     * @return array<string,mixed>|null The render contract, or null.
     *
     * @spec openspec/specs/integration-leaf-foundation/spec.md
     */
    public function fetch(string $seriesKey): ?array
    {
        $series = $this->mapper->findByKey($seriesKey);
        if ($series === null) {
            return null;
        }

        if ($this->canRead(series: $series) === false) {
            return null;
        }

        return $series->jsonSerialize();
    }//end fetch()

    /**
     * Whether the current user may read the given series.
     *
     * - 'public'           — anyone.
     * - 'group'/'private'  — the creator or an admin.
     *
     * @param AnalyticsSeries $series The series.
     *
     * @return bool True when readable.
     */
    private function canRead(AnalyticsSeries $series): bool
    {
        if ($series->getVisibility() === 'public') {
            return true;
        }

        $user = $this->userSession->getUser();
        if ($user === null) {
            return false;
        }

        $uid = $user->getUID();
        if ($series->getCreatedBy() === $uid) {
            return true;
        }

        return $this->groupManager->isAdmin($uid);
    }//end canRead()
}//end class
