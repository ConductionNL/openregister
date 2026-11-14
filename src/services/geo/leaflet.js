/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * leaflet — load Leaflet and its draw control from the app bundle.
 *
 * `leaflet-draw` is a plugin that attaches itself to a global `L`, so Leaflet
 * is put on `window.L` before the plugin is imported. Both are imported on
 * demand, so pages without a map do not load them.
 *
 * @spec openspec/changes/geometry-on-a-map/specs/geo-metadata-kaart/spec.md#requirement-req-geomap-002-records-with-a-geometry-can-be-seen-and-searched-on-a-map
 */

let loading = null

/**
 * Leaflet with the draw control, loaded once.
 *
 * @return {Promise<object>} The Leaflet namespace, with `L.Control.Draw`.
 * @spec openspec/changes/geometry-on-a-map/specs/geo-metadata-kaart/spec.md#requirement-req-geomap-002-records-with-a-geometry-can-be-seen-and-searched-on-a-map
 */
export function loadLeaflet() {
	if (loading === null) {
		loading = (async () => {
			const module = await import('leaflet')
			const leaflet = module.default || module
			await import('leaflet/dist/leaflet.css')
			if (typeof window !== 'undefined' && !window.L) {
				window.L = leaflet
			}
			await import('leaflet-draw')
			await import('leaflet-draw/dist/leaflet.draw.css')
			return leaflet
		})()
	}
	return loading
}
