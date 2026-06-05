/**
 * Photos integration registration.
 *
 * Registers the Photos integration with the OpenRegister frontend registry.
 * The backend provider (PhotosProvider, id='photos') pairs with this registration.
 * Tab and widget components live in @conduction/nextcloud-vue (CnPhotosTab / CnPhotosCard).
 *
 * @see openspec/changes/integration-photos/tasks.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * Photos integration descriptor.
 *
 * Surface mapping:
 *  - tab          → CnPhotosTab   (thumbnail grid + lightbox with EXIF)
 *  - widget       → CnPhotosCard  (detail-page strip, user-dashboard grid, etc.)
 *  - referenceType→ 'photos'      (thumbnail chip in form/detail property display)
 */
export const photosIntegration = {
	id: 'photos',
	label: 'Photos',
	icon: 'Image',
	group: 'docs',
	requiredApp: 'photos',
	referenceType: 'photos',
}

/**
 * Register the Photos integration when the global registry is available.
 *
 * The registry is initialised by @conduction/nextcloud-vue before app bootstrap.
 * When tab/widget components from that library are available they are attached
 * here via dynamic import so the core bundle stays lean.
 */
export function registerPhotosIntegration() {
	const registry = window?.OCA?.OpenRegister?.integrations

	if (typeof registry?.register !== 'function') {
		// Registry not available yet (e.g. library version mismatch); skip silently.
		return
	}

	registry.register(photosIntegration)
}

// Auto-register on module load.
registerPhotosIntegration()
