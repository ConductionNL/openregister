<?php

/**
 * MapsOverviewService — page-level multi-object MAP render surface, the
 * maps equivalent of {@see AnalyticsSeriesServiceInterface}.
 *
 * Where the per-object {@see \OCA\OpenRegister\Service\Integration\Providers\MapsProvider}
 * lists the locations linked to ONE object (a sidebar tab), this service
 * powers a PAGE-LEVEL map showing MANY objects of a register/schema as
 * markers on a single map (procest's "cases on map" overview, issue #112).
 *
 * Two responsibilities, both additive:
 *   1. declareOverview() — declares a `map` page widget on the
 *      IntegrationRegistry (declarative render contract + optional
 *      base-layer config such as PDOK WMTS), so the nc-vue render layer
 *      can discover and place the map surface. Mirrors
 *      AnalyticsSeriesServiceInterface::register()'s widget declaration.
 *   2. queryPoints() — resolves the marker point set for a register/schema
 *      (+ optional filters) by running the CANONICAL OpenRegister read
 *      path with `_rbac: true`, then extracting `{id,label,lat,lng,...}`
 *      from each returned object's geometry.
 *
 * RBAC contract (ADR-005, fail-closed): the leaf supplies the register /
 * schema / filters, but OpenRegister owns the read. queryPoints() never
 * bypasses RBAC — it delegates to {@see ObjectService::findAll()} with
 * `_rbac: true` for non-admins, so an anonymous caller only ever sees
 * objects the public group may read. No object that the caller cannot
 * read can leak as a marker. The render contract (point shape + base
 * layer) is owned by OpenRegister; the maths/point selection (which
 * register/schema/filters) is owned by the leaf.
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
 * @spec openspec/specs/integration-maps-overview/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Search\Service\Geo\FeatureShape;
use OCA\OpenRegister\Service\Integration\PageWidgetRegistryInterface;
use OCA\OpenRegister\Service\Rbac\CallerContext;
use OCA\OpenRegister\Service\AnalyticsSeriesServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;

/**
 * MapsOverviewService — page-level multi-object map render surface.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Service composes the object read facade, geo feature
 * builder, integration registry, user session and group manager — each is required for the
 * register / query / RBAC contract.
 */
class MapsOverviewService {

	/**
	 * Hard cap on the number of objects scanned for a single overview, so a
	 * page-level map query can never become an unbounded read. The render
	 * layer paginates; this is a safety ceiling, not the page size.
	 *
	 * @var int
	 */
	private const MAX_POINTS = 5000;

	/**
	 * Default declarative base-layer config — Dutch government PDOK WMTS
	 * (BRT-Achtergrondkaart). NOT hardcoded into the renderer: it is
	 * metadata on the widget descriptor that a leaf MAY override entirely.
	 *
	 * @var array<string,mixed>
	 */
	private const DEFAULT_BASE_LAYER = [
		'type' => 'wmts',
		'provider' => 'pdok',
		'url' => 'https://service.pdok.nl/brt/achtergrondkaart/wmts/v1_0',
		'layer' => 'standaard',
		'tileMatrixSet' => 'EPSG:3857',
		'format' => 'image/png',
		'attribution' => 'Kaartgegevens © PDOK',
	];

	/**
	 * Constructor.
	 *
	 * @param MapsOverviewSources $sources The object read facade, geometry builder and page-widget registry.
	 * @param CallerContext $caller The current user (write guard) and the group manager (admin RBAC bypass).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly MapsOverviewSources $sources,
		private readonly CallerContext $caller,
	) {
	}//end __construct()

	/**
	 * Authorisation guard for the register (write) path — a map overview
	 * widget may only be declared by an authenticated user. Throws
	 * fail-closed (ADR-005) so the controller surfaces 400/401 rather than
	 * silently writing. Called explicitly from the controller so the authz
	 * decision is visible at the endpoint boundary.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When no user is logged in.
	 *
	 * @spec openspec/specs/integration-maps-overview/spec.md
	 */
	public function ensureCanRegister(): void {
		if ($this->caller->userSession->getUser() === null) {
			throw new InvalidArgumentException('A logged-in user is required to register a map overview');
		}
	}//end ensureCanRegister()

	/**
	 * Declare a page-level map overview widget.
	 *
	 * @param MapOverview $overview The key, marker source, title and base layer.
	 *
	 * @return array<string,mixed> The stored widget render contract.
	 *
	 * @throws InvalidArgumentException On empty key / register / schema.
	 *
	 * @spec openspec/specs/integration-maps-overview/spec.md
	 */
	public function declareOverview(MapOverview $overview): array {
		$this->assertRegistrable(overviewKey: $overview->key, source: $overview->source);

		// (Re)declare the page widget so the render layer can discover it.
		// First-write-wins inside the registry; harmless on a re-declare.
		$this->sources->registry->registerPageWidget(
			[
				'id' => $overview->widgetId(),
				'type' => 'map',
				'title' => $overview->title,
				'providerId' => 'maps-overview',
				'config' => $this->widgetConfig(overview: $overview),
			]
		);

		return $this->sources->registry->getPageWidget($overview->widgetId()) ?? [];
	}//end declareOverview()

	/**
	 * The config a map overview widget carries: its scope, filters and base layer, no marker data.
	 *
	 * @param MapOverview $overview The overview.
	 *
	 * @return array<string,mixed>
	 */
	private function widgetConfig(MapOverview $overview): array {
		return [
			'overviewKey' => $overview->key,
			'register' => $overview->source->register,
			'schema' => $overview->source->schema,
			'geoProperty' => $overview->source->geoProperty,
			'filters' => $overview->source->filters,
			'baseLayer' => ($overview->baseLayer ?? self::DEFAULT_BASE_LAYER),
		];
	}//end widgetConfig()

	/**
	 * Refuse an overview declaration without a key or scope.
	 *
	 * @param string          $overviewKey Stable overview/widget key.
	 * @param MapMarkerSource $source      The register and schema the markers come from.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException On empty key / register / schema.
	 */
	private function assertRegistrable(string $overviewKey, MapMarkerSource $source): void {
		if (trim($overviewKey) === '') {
			throw new InvalidArgumentException('overviewKey is required to register a map overview');
		}

		if ((string)$source->register === '' || (string)$source->schema === '') {
			throw new InvalidArgumentException('register and schema are required to register a map overview');
		}
	}//end assertRegistrable()

	/**
	 * Authorisation guard for the points (read) path — RBAC-scopes the
	 * marker query and returns only the points the current user may read.
	 *
	 * This is the named authz seam the controller calls so the read
	 * decision is visible at the endpoint boundary (ADR-005). It delegates
	 * to {@see queryPoints()}, which runs the canonical OR read path with
	 * `_rbac: true` for non-admins — an anonymous / low-privilege caller
	 * only ever sees public-readable objects (fail-closed, no leak). The
	 * register/schema scope keys are caller-immutable.
	 *
	 * @param MapMarkerSource $source The register, schema, filters and geo property to query.
	 * @param int|null        $limit  Max markers (capped at MAX_POINTS).
	 *
	 * @return array<int,array<string,mixed>> The RBAC-scoped marker point set.
	 *
	 * @throws InvalidArgumentException On empty register / schema.
	 *
	 * @spec openspec/specs/integration-maps-overview/spec.md
	 */
	public function ensureReadablePoints(MapMarkerSource $source, ?int $limit = null): array {
		return $this->queryPoints(source: $source, limit: $limit);
	}//end ensureReadablePoints()

	/**
	 * Query the marker point set for a register/schema, RBAC-scoped.
	 *
	 * Runs the canonical OpenRegister read path with `_rbac: true` for
	 * non-admins, so the returned markers are exactly the objects the
	 * current user (anonymous included) may read — fail-closed, no leak.
	 * Each readable object that carries a recognisable geometry becomes a
	 * marker `{id,label,lat,lng,register,schema,...}`. Objects without
	 * geometry are silently skipped (nothing to plot).
	 *
	 * @param MapMarkerSource $source The register, schema, filters and geo property to query.
	 * @param int|null        $limit  Max markers to return (capped at MAX_POINTS).
	 *
	 * @return array<int,array<string,mixed>> The RBAC-scoped marker point set.
	 *
	 * @throws InvalidArgumentException On empty register / schema.
	 *
	 * @spec openspec/specs/integration-maps-overview/spec.md
	 */
	public function queryPoints(MapMarkerSource $source, ?int $limit = null): array {
		if ((string)$source->register === '' || (string)$source->schema === '') {
			throw new InvalidArgumentException('register and schema are required to query map points');
		}

		// RBAC: admins read everything; everyone else (anonymous included)
		// is scoped to what the public group / their groups may read. This
		// is the SAME read posture ObjectsController uses for the objects
		// index — we do not invent a second RBAC path.
		$rbac = ($this->isCurrentUserAdmin() === false);

		// Sanitise the filters into the OpenRegister findAll filter shape:
		// register/schema are mandatory scope keys; the rest narrow the set.
		$objectFilters = $source->objectFilters();

		$result = $this->sources->objectService->findAll(
			config: [
				'limit' => $this->pointCap(limit: $limit),
				'filters' => $objectFilters,
			],
			_rbac: $rbac,
			_multitenancy: $rbac
		);

		// `ObjectService::findAll()` returns rendered **ObjectEntity objects**
		// (ObjectService.php -> RenderObject::renderEntities(), declared
		// `@return ObjectEntity[]`), NOT arrays. This loop used to say
		// `if (is_array($row) === false) { continue; }` on the strength of a
		// comment claiming the opposite, so EVERY row was skipped and this
		// endpoint answered HTTP 200 with `{"points":[],"count":0}` for every
		// register/schema — indistinguishable from "nothing has geometry".
		// `jsonSerialize()` is the rendered array shape `pointFromRow()` reads
		// (@self + id + the schema properties at top level).
		return $this->rowsToPoints(rows: $result, source: $source);
	}//end queryPoints()

	/**
	 * The marker cap: a positive limit below MAX_POINTS, else MAX_POINTS.
	 *
	 * @param int|null $limit Requested limit.
	 *
	 * @return int
	 */
	private function pointCap(?int $limit): int {
		if ($limit !== null && $limit > 0 && $limit < self::MAX_POINTS) {
			return $limit;
		}

		return self::MAX_POINTS;
	}//end pointCap()

	/**
	 * Turn readable rows into markers, skipping unusable and geometry-less rows.
	 *
	 * @param iterable<mixed>  $rows   Rows as returned by ObjectService::findAll().
	 * @param MapMarkerSource $source The geo property and the scope echoed onto each marker.
	 *
	 * @return array<int,array<string,mixed>> The markers, in row order.
	 */
	private function rowsToPoints(iterable $rows, MapMarkerSource $source): array {
		$points = [];
		foreach ($rows as $row) {
			$rowArray = $this->rowToArray(row: $row);
			if ($rowArray === null) {
				continue;
			}

			$point = $this->pointFromRow(row: $rowArray, source: $source);
			if ($point !== null) {
				$points[] = $point;
			}
		}

		return $points;
	}//end rowsToPoints()

	/**
	 * Normalise one `findAll()` row to the rendered array shape.
	 *
	 * `ObjectService::findAll()` returns rendered **ObjectEntity objects**
	 * (`ObjectService::findAll()` -> `RenderObject::renderEntities()`, declared
	 * `@return ObjectEntity[]`), NOT arrays. `queryPoints()` used to test
	 * `is_array($row)` on the strength of a comment claiming the opposite, so
	 * EVERY row was skipped and the overview endpoint answered HTTP 200 with
	 * `{"points":[],"count":0}` for every register/schema — indistinguishable
	 * from "nothing here has geometry". `jsonSerialize()` is exactly the shape
	 * `pointFromRow()` reads: `@self` + `id` + the schema properties at top
	 * level. Plain arrays are still accepted so a caller passing pre-rendered
	 * rows keeps working.
	 *
	 * @param mixed $row One row as returned by ObjectService::findAll().
	 *
	 * @return array<string,mixed>|null The rendered array, or null when unusable.
	 *
	 * @spec openspec/specs/integration-maps-overview/spec.md
	 */
	private function rowToArray(mixed $row): ?array {
		if ($row instanceof ObjectEntity === true) {
			return $row->jsonSerialize();
		}

		if (is_array($row) === true) {
			return $row;
		}

		return null;
	}//end rowToArray()

	/**
	 * Extract a marker point from a rendered object row.
	 *
	 * Reuses {@see GeoFeatureCollectionBuilder} to locate the geometry, then
	 * reduces it to a single representative `{lat,lng}` (first coordinate of
	 * Point / first vertex of a Polygon ring etc.). Returns null when the
	 * object carries no recognisable geometry.
	 *
	 * @param array<string,mixed> $row    The rendered object row.
	 * @param MapMarkerSource     $source The geo property and the scope echoed onto the marker.
	 *
	 * @return array<string,mixed>|null The marker, or null when no geometry.
	 */
	private function pointFromRow(array $row, MapMarkerSource $source): ?array {
		$feature = $this->sources->geoBuilder->feature(row: $row, shape: new FeatureShape(geoProperty: $source->geoProperty));
		if ($feature === null) {
			return null;
		}

		$latLng = $this->representativeLatLng(geometry: ($feature['geometry'] ?? []));
		if ($latLng === null) {
			return null;
		}

		$self = ($row['@self'] ?? []);
		$id = ($row['id'] ?? ($self['id'] ?? ($feature['id'] ?? null)));
		$label = $this->deriveLabel(row: $row, id: $id);

		$idString = null;
		if ($id !== null) {
			$idString = (string)$id;
		}

		return [
			'id' => $idString,
			'label' => $label,
			'lat' => $latLng['lat'],
			'lng' => $latLng['lng'],
			'register' => (string)$source->register,
			'schema' => (string)$source->schema,
			'geometry' => ($feature['geometry'] ?? null),
		];
	}//end pointFromRow()

	/**
	 * Reduce a GeoJSON geometry to a single representative [lat,lng].
	 *
	 * Point → its coordinate; Polygon/MultiPolygon/LineString → the first
	 * vertex. GeoJSON positions are [lon, lat]; we return them split out so
	 * the render layer never has to remember the ordering.
	 *
	 * @param array<string,mixed> $geometry The GeoJSON geometry.
	 *
	 * @return array{lat: float, lng: float}|null The representative point, or null.
	 */
	private function representativeLatLng(array $geometry): ?array {
		$coordinates = ($geometry['coordinates'] ?? null);
		if (is_array($coordinates) === false) {
			return null;
		}

		$position = $this->firstPosition(coordinates: $coordinates);
		if ($this->isNumericPair(position: $position) === false) {
			return null;
		}

		return [
			'lat' => (float)$position[1],
			'lng' => (float)$position[0],
		];
	}//end representativeLatLng()

	/**
	 * Drill down to the first [lon, lat] position regardless of nesting
	 * depth (Point=0, LineString=1, Polygon=2, MultiPolygon=3), at most four levels.
	 *
	 * @param array<int|string,mixed> $coordinates GeoJSON coordinates.
	 *
	 * @return array<int|string,mixed> The innermost position reached.
	 */
	private function firstPosition(array $coordinates): array {
		$position = $coordinates;
		$guard = 0;
		while (isset($position[0]) === true && is_array($position[0]) === true && $guard < 4) {
			$position = $position[0];
			$guard++;
		}

		return $position;
	}//end firstPosition()

	/**
	 * Whether a position starts with two numeric values.
	 *
	 * @param array<int|string,mixed> $position A GeoJSON position.
	 *
	 * @return bool
	 */
	private function isNumericPair(array $position): bool {
		return isset($position[0]) === true && isset($position[1]) === true
			&& is_numeric($position[0]) === true && is_numeric($position[1]) === true;
	}//end isNumericPair()

	/**
	 * Derive a human-readable marker label from a rendered object row.
	 *
	 * Prefers @self.name / @self.title, then a top-level name/title field,
	 * finally falls back to the id. Never leaks anything the RBAC read path
	 * did not already return.
	 *
	 * @param array<string,mixed> $row The rendered object row.
	 * @param mixed $id The resolved id.
	 *
	 * @return string The marker label.
	 */
	private function deriveLabel(array $row, mixed $id): string {
		$label = $this->firstFilledScalar(source: ($row['@self'] ?? []), keys: ['name', 'title'])
			?? $this->firstFilledScalar(source: $row, keys: ['name', 'title', 'naam', 'titel']);
		if ($label !== null) {
			return $label;
		}

		if ($id !== null) {
			return (string)$id;
		}

		return '';
	}//end deriveLabel()

	/**
	 * The first of the given keys holding a non-empty scalar, as a string.
	 *
	 * @param array<string,mixed> $source The array to read.
	 * @param string[] $keys Keys to try, in order.
	 *
	 * @return string|null The value, or null when no key holds one.
	 */
	private function firstFilledScalar(array $source, array $keys): ?string {
		foreach ($keys as $key) {
			if ($this->isFilledScalar(value: ($source[$key] ?? null)) === true) {
				return (string)$source[$key];
			}
		}

		return null;
	}//end firstFilledScalar()

	/**
	 * Whether a value is a scalar that does not stringify to ''.
	 *
	 * @param mixed $value The value (null when the key is absent).
	 *
	 * @return bool
	 */
	private function isFilledScalar(mixed $value): bool {
		return $value !== null && is_scalar($value) === true && (string)$value !== '';
	}//end isFilledScalar()

	/**
	 * Whether the current user is an admin (RBAC bypass).
	 *
	 * @return bool True when the logged-in user is in the `admin` group.
	 */
	private function isCurrentUserAdmin(): bool {
		$user = $this->caller->userSession->getUser();
		if ($user === null) {
			return false;
		}

		return $this->caller->groupManager->isAdmin($user->getUID());
	}//end isCurrentUserAdmin()
}//end class
