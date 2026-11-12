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
use OCA\OpenRegister\Db\AnalyticsSeriesMapperInterface;
use OCA\OpenRegister\Service\Integration\PageWidgetRegistryInterface;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * AnalyticsSeriesService — page-level series register / fetch.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Service composes the series mapper, integration
 * registry, user session and group manager — each is required for the register / fetch / RBAC contract.
 */
class AnalyticsSeriesService implements AnalyticsSeriesServiceInterface {

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
	 * @param AnalyticsSeriesMapperInterface $mapper Series persistence.
	 * @param PageWidgetRegistryInterface $registry Registry for the page-widget surface.
	 * @param IUserSession $userSession Current user.
	 * @param IGroupManager $groupManager Group / admin checks.
	 *
	 * @return void
	 */
	public function __construct(
		private AnalyticsSeriesMapperInterface $mapper,
		private PageWidgetRegistryInterface $registry,
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
	public function ensureCanRegister(): void {
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
	public function ensureReadableOrNull(string $seriesKey): ?array {
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
	 * @param AnalyticsSeriesDraft $draft The series to register.
	 *
	 * @return array<string,mixed> The stored series render contract.
	 *
	 * @throws InvalidArgumentException On invalid key / visibility / chart type, or without a logged-in user.
	 *
	 * @spec openspec/specs/integration-leaf-foundation/spec.md
	 */
	public function registerSeries(AnalyticsSeriesDraft $draft): array {
		$this->assertRegistrable(draft: $draft);
		$userUid = $this->registeringUserUid();

		$now = new DateTime();
		$existing = $this->mapper->findByKey($draft->seriesKey);

		$entity = $this->filled(entity: ($existing ?? new AnalyticsSeries()), draft: $draft);
		$entity->setUpdatedAt($now);

		if ($existing === null) {
			$entity->setCreatedBy($userUid);
			$entity->setCreatedAt($now);
		}

		$saved = $this->persist(entity: $entity, existing: $existing);

		// (Re)declare the page widget so the render layer can discover it.
		// First-write-wins inside the registry; harmless on a refresh.
		$this->registry->registerPageWidget($this->pageWidget(draft: $draft));

		return $saved->jsonSerialize();
	}//end registerSeries()

	/**
	 * The UID of the user registering a series.
	 *
	 * @return string
	 *
	 * @throws InvalidArgumentException When no user is logged in.
	 */
	private function registeringUserUid(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new InvalidArgumentException('A logged-in user is required to register a series');
		}

		return $user->getUID();
	}//end registeringUserUid()

	/**
	 * Refuse a series without a key, or with an unknown visibility or chart type.
	 *
	 * @param AnalyticsSeriesDraft $draft The series to register.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException On invalid key / visibility / chart type.
	 */
	private function assertRegistrable(AnalyticsSeriesDraft $draft): void {
		if (trim($draft->seriesKey) === '') {
			throw new InvalidArgumentException('seriesKey is required to register a series');
		}

		if (in_array($draft->visibility(), self::VISIBILITIES, true) === false) {
			throw new InvalidArgumentException('Invalid visibility — expected one of: ' . implode(', ', self::VISIBILITIES));
		}

		if (in_array($draft->chartType(), self::CHART_TYPES, true) === false) {
			throw new InvalidArgumentException('Invalid chartType — expected one of: ' . implode(', ', self::CHART_TYPES));
		}
	}//end assertRegistrable()

	/**
	 * Write a draft's key, scope, title, chart type, data and visibility onto a series row.
	 *
	 * @param AnalyticsSeries      $entity The new or stored series.
	 * @param AnalyticsSeriesDraft $draft  The series to register.
	 *
	 * @return AnalyticsSeries The same row.
	 */
	private function filled(AnalyticsSeries $entity, AnalyticsSeriesDraft $draft): AnalyticsSeries {
		$entity->setSeriesKey($draft->seriesKey);
		$entity->setRegisterId($draft->registerId());
		$entity->setSchemaId($draft->schemaId());
		$entity->setTitle($draft->title);
		$entity->setChartType($draft->chartType());
		$entity->setLabels(json_encode(array_values($draft->labels())));
		$entity->setDatasets(json_encode(array_values($draft->datasets())));
		$entity->setVisibility($draft->visibility());
		return $entity;
	}//end filled()

	/**
	 * Insert a new series, or update the stored one.
	 *
	 * @param AnalyticsSeries      $entity   The filled series.
	 * @param AnalyticsSeries|null $existing The stored series it was loaded from, or null.
	 *
	 * @return AnalyticsSeries
	 */
	private function persist(AnalyticsSeries $entity, ?AnalyticsSeries $existing): AnalyticsSeries {
		if ($existing === null) {
			return $this->mapper->insert($entity);
		}

		return $this->mapper->update($entity);
	}//end persist()

	/**
	 * The page widget that renders a series.
	 *
	 * @param AnalyticsSeriesDraft $draft The registered series.
	 *
	 * @return array<string, mixed>
	 */
	private function pageWidget(AnalyticsSeriesDraft $draft): array {
		return [
			'id' => 'analytics-series:' . $draft->seriesKey,
			'type' => 'chart',
			'title' => $draft->title,
			'providerId' => 'analytics-series',
			'config' => [
				'seriesKey' => $draft->seriesKey,
				'chartType' => $draft->chartType(),
				'registerId' => $draft->registerId(),
				'schemaId' => $draft->schemaId(),
			],
		];
	}//end pageWidget()

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
	public function fetch(string $seriesKey): ?array {
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
	private function canRead(AnalyticsSeries $series): bool {
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
