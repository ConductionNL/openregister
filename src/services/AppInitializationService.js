/**
 * App Initialization Service
 *
 * Handles hot-loading of essential data at application startup.
 * This ensures that frequently used data is available immediately
 * without requiring API calls when modals/components open.
 */

import {
	applicationStore,
	organisationStore,
	registerStore,
	schemaStore,
	sourceStore,
	viewsStore,
} from '../store/store.js'

/**
 * Hot-load the data the app shell and most pages read.
 *
 * This function is called once at application startup to pre-load
 * registers, schemas and organisations, and set the active organisation.
 *
 * @return {Promise<void>}
 * @spec openspec/changes/boot-reads-only-what-the-page-uses/specs/frontend-app-bootstrap/spec.md
 */
export async function initializeAppData() {
	try {
		// Load all essential data in parallel for maximum performance
		// What the shell and most pages read. Applications, views and sources
		// are loaded by the one view or modal that shows them, when it opens:
		// each of those already refreshes its list on mount, so a boot read
		// here was a repeat or unused, on every page load.
		await Promise.all([
			loadRegisters(),
			loadSchemas(),
			loadOrganisations(),
		])
	} catch (error) {
		console.error('[AppInit] ✗ Error during initialization:', error)
		// Don't throw - allow app to continue even if some data fails to load
	}
}

/**
 * Reload all application data (force refresh).
 *
 * Use this when switching organisations or when data needs to be refreshed.
 * This always fetches fresh data regardless of whether it's already loaded.
 *
 * @return {Promise<void>}
 * @spec openspec/specs/frontend-app-bootstrap/spec.md
 */
export async function reloadAppData() {
	try {
		// Force reload all data in parallel
		await Promise.all([
			forceLoadRegisters(),
			forceLoadSchemas(),
			forceLoadOrganisations(),
			forceLoadApplications(),
			forceLoadViews(),
			forceLoadSources(),
		])
	} catch (error) {
		console.error('[AppInit] ✗ Error during reload:', error)
		// Don't throw - allow app to continue even if some data fails to reload
	}
}

/**
 * Load registers if not already loaded.
 *
 * @return {Promise<void>}
 */
async function loadRegisters() {
	if (registerStore.registerList.length === 0) {
		await registerStore.refreshRegisterList()
	}
}

/**
 * Force load registers (always refreshes).
 *
 * @return {Promise<void>}
 */
async function forceLoadRegisters() {
	await registerStore.refreshRegisterList()
}

/**
 * Load schemas if not already loaded.
 *
 * @return {Promise<void>}
 */
async function loadSchemas() {
	if (schemaStore.schemaList.length === 0) {
		await schemaStore.refreshSchemaList()
	}
}

/**
 * Force load schemas (always refreshes).
 *
 * @return {Promise<void>}
 */
async function forceLoadSchemas() {
	await schemaStore.refreshSchemaList()
}

/**
 * Load organisations if not already loaded.
 * Also sets the active organisation from the user session.
 *
 * @return {Promise<void>}
 */
async function loadOrganisations() {
	let listed = null
	if (
		!organisationStore.organisationList
		|| organisationStore.organisationList.length === 0
	) {
		listed = (await organisationStore.refreshOrganisationList())?.data
	}

	if (!organisationStore.activeOrganisation) {
		await loadActiveOrganisation(listed)
	}
}

/**
 * Set the active organisation from the session.
 *
 * GET /api/organisations already answers it under `active` (the same
 * OrganisationService::getActiveOrganisation() call GET /api/organisations/active
 * makes), so when the list was just read, use that instead of a second,
 * sequential request on every page load. Without the list's answer, ask.
 *
 * @param {object|null} listed The organisation list response body, when read now
 * @return {Promise<void>}
 * @spec openspec/changes/boot-reads-only-what-the-page-uses/specs/frontend-app-bootstrap/spec.md
 */
async function loadActiveOrganisation(listed) {
	if (listed && Object.hasOwn(listed, 'active')) {
		if (listed.active) {
			organisationStore.setActiveOrganisation(listed.active)
		}
		return
	}
	await organisationStore.getActiveOrganisation()
}

/**
 * Force load organisations (always refreshes).
 * Also refetches the active organisation from the user session.
 *
 * @return {Promise<void>}
 */
async function forceLoadOrganisations() {
	await organisationStore.refreshOrganisationList()

	// Always refetch the active organisation from session
	await organisationStore.getActiveOrganisation()
}

/**
 * Force load applications (always refreshes).
 *
 * @return {Promise<void>}
 */
async function forceLoadApplications() {
	await applicationStore.refreshApplicationList()
}

/**
 * Force load views (always refreshes).
 *
 * @return {Promise<void>}
 */
async function forceLoadViews() {
	try {
		await viewsStore.fetchViews()
	} catch {
		// Swallow errors so a missing views endpoint doesn't break app startup
	}
}

/**
 * Force load sources (always refreshes).
 *
 * @return {Promise<void>}
 */
async function forceLoadSources() {
	await sourceStore.refreshSourceList()
}

/**
 * Check if all essential data is loaded.
 *
 * @return {boolean} True if all data is loaded
 * @spec openspec/specs/frontend-app-bootstrap/spec.md
 */
export function isAppDataLoaded() {
	return Boolean(
		registerStore.registerList.length > 0
		&& schemaStore.schemaList.length > 0
		&& organisationStore.organisationList?.length >= 0 // Allow 0 organisations
		&& applicationStore.applicationList?.length >= 0, // Allow 0 applications
	)
}

export default {
	initializeAppData,
	reloadAppData,
	isAppDataLoaded,
}
