<template>
	<div class="or-map-view">
		<div class="or-map-view__toolbar">
			<NcSelect
				v-if="hasBaseLayers"
				v-model="activeLayer"
				:inputLabel="baseLayerLabel"
				:options="baseLayers"
				:reduce="reduceLayer"
				label="label"
				:clearable="false"
				class="or-map-view__layer-select" />
			<span class="or-map-view__count" aria-live="polite">
				{{ countLabel }}
			</span>
			<NcButton
				v-if="drawable && hasArea"
				data-testid="map-clear-area"
				@click="clearArea">
				{{ t('openregister', 'Clear area') }}
			</NcButton>
		</div>

		<!-- Leaflet renders here. -->
		<div
			ref="mapContainer"
			class="or-map-view__map"
			role="application"
			:aria-label="mapAriaLabel" />

		<!-- Accessible text alternative of the same records (REQ-GEO-014). -->
		<div class="or-map-view__list">
			<h3 class="or-map-view__list-title">
				{{ t('openregister', 'Locations') }}
			</h3>
			<p v-if="markers.length === 0" class="or-map-view__empty">
				{{ t('openregister', 'No objects with a location to display') }}
			</p>
			<ul v-else>
				<li v-for="marker in markers" :key="marker.id" data-testid="map-location">
					<button type="button" class="or-map-view__open" @click="$emit('objectClick', marker.properties)">
						{{ marker.title }}
					</button>
					<span class="or-map-view__coords">{{
						coordsLabel(marker)
					}}</span>
				</li>
			</ul>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
/**
 * MapView — the map presentation of a records list.
 *
 * All data shaping lives in the pure `mapData.js` helpers (unit-tested
 * separately). This component draws the records as markers on a base layer
 * with Leaflet from the app bundle and, when `drawable`, offers a draw
 * control: a drawn polygon is emitted as GeoJSON (`area`), removing it emits
 * `areaCleared`. It always renders an accessible text list of the same
 * records, so the view still works when tiles cannot load (REQ-GEO-003,
 * REQ-GEO-014).
 *
 * @spec openspec/changes/geometry-on-a-map/specs/geo-metadata-kaart/spec.md#requirement-req-geomap-002-records-with-a-geometry-can-be-seen-and-searched-on-a-map
 */
import { NcButton, NcSelect } from '@nextcloud/vue'
import { loadLeaflet } from '../../services/geo/leaflet.js'
import {
	BASE_LAYERS,
	buildMarkers,
	defaultBaseLayer,
	drawnArea,
	formatWgs84,
	markerBounds,
	tileUrl,
} from '../../services/geo/mapData.js'

export default {
	name: 'MapView',
	components: {
		NcButton,
		NcSelect,
	},

	props: {
		/** Object rows to plot. */
		objects: {
			type: Array,
			default: () => [],
		},

		/** Geo property name, or null to auto-detect. */
		geoProperty: {
			type: String,
			default: null,
		},

		/** Offer the draw control for an area search. */
		drawable: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['area', 'areaCleared', 'objectClick'],

	data() {
		return {
			baseLayers: BASE_LAYERS,
			activeLayer: defaultBaseLayer().id,
			hasArea: false,
		}
	},

	computed: {
		hasBaseLayers() {
			return this.baseLayers.length !== 0
		},

		baseLayerLabel() {
			return t('openregister', 'Base map layer')
		},

		markers() {
			return buildMarkers(this.objects, this.geoProperty)
		},

		bounds() {
			return markerBounds(this.markers)
		},

		countLabel() {
			return t('openregister', '{count} locations', {
				count: this.markers.length,
			})
		},

		mapAriaLabel() {
			return t('openregister', 'Map with {count} object locations', {
				count: this.markers.length,
			})
		},
	},

	watch: {
		markers() {
			this.renderMarkers()
		},

		activeLayer() {
			this.renderBaseLayer()
		},
	},

	created() {
		// Leaflet objects stay off the reactive state: a proxied map breaks
		// Leaflet's own identity checks.
		this.leaflet = null
		this.map = null
		this.tiles = null
		this.markerLayer = null
		this.drawnLayer = null
	},

	async mounted() {
		try {
			this.leaflet = await loadLeaflet()
		} catch {
			// No map library: the accessible list still shows the records.
			return
		}
		this.createMap()
	},

	beforeUnmount() {
		if (this.map !== null) {
			this.map.remove()
			this.map = null
		}
	},

	methods: {
		t,
		reduceLayer(layer) {
			return layer.id
		},

		coordsLabel(marker) {
			return formatWgs84(marker.lon, marker.lat)
		},

		/**
		 * Create the map, its base layer, the markers and the draw control.
		 *
		 * @return {void}
		 * @spec openspec/changes/geometry-on-a-map/specs/geo-metadata-kaart/spec.md#requirement-req-geomap-002-records-with-a-geometry-can-be-seen-and-searched-on-a-map
		 */
		createMap() {
			const L = this.leaflet
			if (!L || !this.$refs.mapContainer || this.map !== null) {
				return
			}
			this.map = L.map(this.$refs.mapContainer).setView([52.1, 5.3], 7)
			this.markerLayer = L.layerGroup().addTo(this.map)
			this.renderBaseLayer()
			this.renderMarkers()
			if (this.drawable && L.Control && L.Control.Draw) {
				this.drawnLayer = new L.FeatureGroup().addTo(this.map)
				this.map.addControl(new L.Control.Draw({
					draw: { polygon: true, rectangle: true, polyline: false, circle: false, circlemarker: false, marker: false },
					edit: { featureGroup: this.drawnLayer },
				}))
				this.map.on(L.Draw.Event.CREATED, (event) => this.onDrawn(event.layer))
				this.map.on(L.Draw.Event.DELETED, () => this.onCleared())
			}
		},

		/**
		 * Show the chosen base layer.
		 *
		 * @return {void}
		 */
		renderBaseLayer() {
			const L = this.leaflet
			if (!L || this.map === null) {
				return
			}
			if (this.tiles !== null) {
				this.map.removeLayer(this.tiles)
			}
			const layer = this.baseLayers.find((entry) => entry.id === this.activeLayer) || defaultBaseLayer()
			this.tiles = L.tileLayer(tileUrl(layer), { attribution: layer.label, maxZoom: 19 }).addTo(this.map)
		},

		/**
		 * Draw one marker per record and fit the map to them.
		 *
		 * @return {void}
		 */
		renderMarkers() {
			const L = this.leaflet
			if (!L || this.map === null || this.markerLayer === null) {
				return
			}
			this.markerLayer.clearLayers()
			const color = markerColor()
			for (const marker of this.markers) {
				L.circleMarker([marker.lat, marker.lon], { radius: 7, color, fillColor: color, fillOpacity: 0.8 })
					.bindTooltip(marker.title)
					.on('click', () => this.$emit('objectClick', marker.properties))
					.addTo(this.markerLayer)
			}
			if (this.bounds !== null) {
				this.map.fitBounds(
					[
						[this.bounds.south, this.bounds.west],
						[this.bounds.north, this.bounds.east],
					],
					{ padding: [24, 24], maxZoom: 16 },
				)
			}
		},

		/**
		 * Keep one drawn area and emit it as GeoJSON.
		 *
		 * @param {object} layer The drawn Leaflet layer.
		 * @return {void}
		 * @spec openspec/changes/geometry-on-a-map/specs/geo-metadata-kaart/spec.md#requirement-req-geomap-002-records-with-a-geometry-can-be-seen-and-searched-on-a-map
		 */
		onDrawn(layer) {
			const area = drawnArea(layer)
			if (area === null) {
				return
			}
			if (this.drawnLayer !== null) {
				this.drawnLayer.clearLayers()
				this.drawnLayer.addLayer(layer)
			}
			this.hasArea = true
			this.$emit('area', area)
		},

		/**
		 * The area was removed: show every record again.
		 *
		 * @return {void}
		 * @spec openspec/changes/geometry-on-a-map/specs/geo-metadata-kaart/spec.md#requirement-req-geomap-002-records-with-a-geometry-can-be-seen-and-searched-on-a-map
		 */
		onCleared() {
			this.hasArea = false
			this.$emit('areaCleared')
		},

		/**
		 * Remove the drawn area from the toolbar button.
		 *
		 * @return {void}
		 */
		clearArea() {
			if (this.drawnLayer !== null) {
				this.drawnLayer.clearLayers()
			}
			this.onCleared()
		},
	},
}

/**
 * The marker colour: the theme's primary element colour, read from its CSS
 * variable because Leaflet paints SVG with a literal colour.
 *
 * @return {string} The colour.
 */
function markerColor() {
	if (typeof document === 'undefined' || typeof getComputedStyle !== 'function') {
		return 'currentColor'
	}
	const value = getComputedStyle(document.documentElement).getPropertyValue('--color-primary-element').trim()
	return value || 'currentColor'
}
</script>

<style scoped>
.or-map-view__toolbar {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-block-end: 8px;
}

.or-map-view__map {
	inline-size: 100%;
	min-block-size: 420px;
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-background-dark);
}

.or-map-view__list {
	margin-block-start: 12px;
}

.or-map-view__open {
	padding: 0;
	border: none;
	background: none;
	color: var(--color-main-text);
	font-weight: bold;
	text-decoration: underline;
	cursor: pointer;
}

.or-map-view__coords {
	margin-inline-start: 8px;
	color: var(--color-text-maxcontrast);
}
</style>
