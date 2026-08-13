<template>
	<div class="or-map-view">
		<div class="or-map-view__toolbar">
			<NcSelect
				v-if="hasBaseLayers"
				v-model="activeLayer"
				:input-label="baseLayerLabel"
				:options="baseLayers"
				:reduce="reduceLayer"
				label="label"
				:clearable="false"
				class="or-map-view__layer-select" />
			<span class="or-map-view__count" aria-live="polite">
				{{ countLabel }}
			</span>
		</div>

		<!-- Map container; Leaflet (if globally available) renders here. -->
		<div
			ref="mapContainer"
			class="or-map-view__map"
			role="application"
			:aria-label="mapAriaLabel" />

		<!-- Accessible text alternative + graceful fallback when no map
			library is present (REQ-GEO-014 accessibility). -->
		<div class="or-map-view__list">
			<h3 class="or-map-view__list-title">
				{{ t('openregister', 'Locations') }}
			</h3>
			<p v-if="markers.length === 0" class="or-map-view__empty">
				{{ t('openregister', 'No objects with a location to display') }}
			</p>
			<ul v-else>
				<li v-for="marker in markers" :key="marker.id">
					<strong>{{ marker.title }}</strong>
					<span class="or-map-view__coords">{{
						coordsLabel(marker)
					}}</span>
				</li>
			</ul>
		</div>
	</div>
</template>

<script>
/**
 * MapView — thin Leaflet host for OpenRegister geo objects.
 *
 * Rendering is intentionally minimal: all data shaping lives in the
 * pure `mapData.js` helpers (unit-tested separately). This component
 * only wires markers/bounds into Leaflet when it is available on the
 * page, and always renders an accessible text list of the same data so
 * the view degrades gracefully when map tiles or the map library can't
 * load (REQ-GEO-003, REQ-GEO-014).
 *
 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-003
 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-014
 */
import { NcSelect } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import {
	BASE_LAYERS,
	defaultBaseLayer,
	buildMarkers,
	markerBounds,
	formatWgs84,
} from '../../services/geo/mapData.js'

export default {
	name: 'MapView',
	components: {
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
	},
	data() {
		return {
			baseLayers: BASE_LAYERS,
			activeLayer: defaultBaseLayer().id,
			map: null,
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
			this.renderMap()
		},
		activeLayer() {
			this.renderMap()
		},
	},
	mounted() {
		this.renderMap()
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
		 * Render markers into Leaflet when the library is available.
		 *
		 * Leaflet is an optional, lazily-discovered global (`window.L`);
		 * the accessible list is always shown regardless, so the view
		 * works even when the map library or tiles fail to load.
		 *
		 * @return {void}
		 */
		renderMap() {
			const leaflet = typeof window !== 'undefined' ? window.L : undefined
			if (!leaflet || !this.$refs.mapContainer) {
				return
			}
			if (this.map === null) {
				this.map = leaflet
					.map(this.$refs.mapContainer)
					.setView([52.1, 5.3], 7)
			}
			if (this.bounds !== null) {
				this.map.fitBounds(
					[
						[this.bounds.south, this.bounds.west],
						[this.bounds.north, this.bounds.east],
					],
					{ padding: [24, 24] },
				)
			}
		},
	},
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
	min-block-size: 300px;
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-background-dark);
}

.or-map-view__list {
	margin-block-start: 12px;
}

.or-map-view__coords {
	margin-inline-start: 8px;
	color: var(--color-text-maxcontrast);
}
</style>
