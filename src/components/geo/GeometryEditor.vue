<!--
SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div class="or-geometry-editor">
		<span class="or-geometry-editor__label">{{ label }}</span>
		<div
			ref="mapContainer"
			class="or-geometry-editor__map"
			role="application"
			:aria-label="label" />
		<NcTextArea
			:modelValue="text"
			:label="t('openregister', 'As JSON')"
			:error="error !== ''"
			:helperText="error"
			resize="vertical"
			@update:modelValue="onText" />
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
/**
 * GeometryEditor — the record form's editor for a `geo:*` property.
 *
 * A small Leaflet map with the draw control (the shapes the property's
 * format allows) writes the drawn shape as GeoJSON; the JSON text below it
 * is the fallback, and shows what will be saved. The server validates the
 * geometry on save (REQ-GEOMAP-001).
 *
 * @spec openspec/changes/geometry-on-a-map/specs/geo-metadata-kaart/spec.md#requirement-req-geomap-001-a-geometry-is-validated-on-save
 */
import { NcTextArea } from '@nextcloud/vue'
import { drawnGeometry, drawShapes, parseGeometryText } from '../../services/geo/geometryValue.js'
import { loadLeaflet } from '../../services/geo/leaflet.js'
import { defaultBaseLayer, tileUrl } from '../../services/geo/mapData.js'

export default {
	name: 'GeometryEditor',
	components: { NcTextArea },

	props: {
		/** The GeoJSON geometry, or null. */
		value: {
			type: Object,
			default: null,
		},

		/** The property's geo format, e.g. `geo:point`. */
		geoType: {
			type: String,
			default: 'geo:geometry',
		},

		/** The field's label. */
		label: {
			type: String,
			default: '',
		},
	},

	emits: ['input'],

	data() {
		return {
			text: this.value ? JSON.stringify(this.value, null, 2) : '',
			error: '',
		}
	},

	watch: {
		value(newVal) {
			const current = parseGeometryText(this.text).value
			if (JSON.stringify(current) !== JSON.stringify(newVal)) {
				this.text = newVal ? JSON.stringify(newVal, null, 2) : ''
				this.error = ''
				this.showValue()
			}
		},
	},

	created() {
		this.leaflet = null
		this.map = null
		this.drawnLayer = null
	},

	async mounted() {
		try {
			this.leaflet = await loadLeaflet()
		} catch {
			// No map library: the JSON text still edits the value.
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

		/**
		 * The JSON text changed: emit it when it is an object, else say why not.
		 *
		 * @param {string} text The text.
		 * @spec openspec/changes/geometry-on-a-map/specs/geo-metadata-kaart/spec.md#requirement-req-geomap-001-a-geometry-is-validated-on-save
		 */
		onText(text) {
			this.text = text
			const parsed = parseGeometryText(text)
			if (parsed.error !== '') {
				this.error = t('openregister', 'That is not valid JSON: {reason}', { reason: parsed.error })
				return
			}
			this.error = ''
			this.$emit('input', parsed.value)
			this.showValue()
		},

		/**
		 * A shape was drawn: it replaces the value.
		 *
		 * @param {object} layer The drawn Leaflet layer.
		 * @spec openspec/changes/geometry-on-a-map/specs/geo-metadata-kaart/spec.md#requirement-req-geomap-001-a-geometry-is-validated-on-save
		 */
		onDrawn(layer) {
			const geometry = drawnGeometry(layer, this.geoType)
			if (geometry === null) {
				return
			}
			this.text = JSON.stringify(geometry, null, 2)
			this.error = ''
			this.$emit('input', geometry)
			this.showValue()
		},

		/**
		 * The shape was deleted on the map: the value is cleared.
		 */
		onDeleted() {
			this.text = ''
			this.error = ''
			this.$emit('input', null)
		},

		/**
		 * Create the map with the draw control.
		 */
		createMap() {
			const L = this.leaflet
			if (!L || !this.$refs.mapContainer || this.map !== null) {
				return
			}
			this.map = L.map(this.$refs.mapContainer).setView([52.1, 5.3], 7)
			const layer = defaultBaseLayer()
			L.tileLayer(tileUrl(layer), { attribution: layer.label, maxZoom: 19 }).addTo(this.map)
			this.drawnLayer = new L.FeatureGroup().addTo(this.map)
			if (L.Control && L.Control.Draw) {
				const shapes = drawShapes(this.geoType)
				this.map.addControl(new L.Control.Draw({
					draw: { ...shapes, rectangle: false, circle: false, circlemarker: false },
					edit: { featureGroup: this.drawnLayer },
				}))
				this.map.on(L.Draw.Event.CREATED, (event) => this.onDrawn(event.layer))
				this.map.on(L.Draw.Event.DELETED, () => this.onDeleted())
			}
			this.showValue()
		},

		/**
		 * Show the current value on the map and fit the map to it.
		 */
		showValue() {
			const L = this.leaflet
			if (!L || this.map === null || this.drawnLayer === null) {
				return
			}
			this.drawnLayer.clearLayers()
			const value = parseGeometryText(this.text).value
			if (value === null) {
				return
			}
			try {
				L.geoJSON(value).eachLayer((shape) => this.drawnLayer.addLayer(shape))
				const bounds = this.drawnLayer.getBounds()
				if (bounds.isValid()) {
					this.map.fitBounds(bounds, { padding: [24, 24], maxZoom: 16 })
				}
			} catch {
				// Not a geometry Leaflet can draw; the server says why on save.
			}
		},
	},
}
</script>

<style scoped>
.or-geometry-editor {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.or-geometry-editor__label {
	font-weight: bold;
}

.or-geometry-editor__map {
	inline-size: 100%;
	min-block-size: 260px;
	border-radius: var(--border-radius-large);
	background-color: var(--color-background-dark);
}
</style>
