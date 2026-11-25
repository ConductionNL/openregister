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
 * Hot-load all essential application data.
 *
 * This function is called once at application startup to pre-load
 * data that is frequently needed across the application.
 *
 * @return {Promise<void>}
 * @spec openspec/specs/frontend-app-bootstrap/spec.md
 */
export async function initializeAppData() {
	try {
		// Load all essential data in parallel for maximum performance
		await Promise.all([
			// Core entities
			loadRegisters(),
			loadSchemas(),

			// Configuration dependencies
			loadOrganisations(),
			loadApplications(),

			// Extended entities
			loadViews(),
			loadSources(),
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
 * Also fetches the active organisation from the user session.
 *
 * The active organisation comes from the session, not from the list, so both
 * reads start together: waiting for the list first put the active read in a
 * second wave behind it (and behind the main-thread work the first wave
 * triggers), ~190 ms after the list on a warm Registers load.
 *
 * @return {Promise<void>}
 */
async function loadOrganisations() {
	const reads = []
	if (
		!organisationStore.organisationList
		|| organisationStore.organisationList.length === 0
	) {
		reads.push(organisationStore.refreshOrganisationList())
	}

	// Always fetch the active organisation from session
	if (!organisationStore.activeOrganisation) {
		reads.push(organisationStore.getActiveOrganisation())
	}

	await Promise.all(reads)
}

/**
 * Force load organisations (always refreshes).
 * Also refetches the active organisation from the user session, beside the list.
 *
 * @return {Promise<void>}
 */
async function forceLoadOrganisations() {
	await Promise.all([
		organisationStore.refreshOrganisationList(),
		// Always refetch the active organisation from session
		organisationStore.getActiveOrganisation(),
	])
}

/**
 * Load applications if not already loaded.
 *
 * @return {Promise<void>}
 */
async function loadApplications() {
	if (
		!applicationStore.applicationList
		|| applicationStore.applicationList.length === 0
	) {
		await applicationStore.refreshApplicationList()
	}
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
 * Load views if not already loaded.
 *
 * @return {Promise<void>}
 */
async function loadViews() {
	// Views store may not have a list property, check the store structure
	try {
		await viewsStore.fetchViews()
	} catch {
		// Swallow errors so a missing views endpoint doesn't break app startup
	}
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
 * Load sources if not already loaded.
 *
 * @return {Promise<void>}
 */
async function loadSources() {
	if (!sourceStore.sourceList || sourceStore.sourceList.length === 0) {
		await sourceStore.refreshSourceList()
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
