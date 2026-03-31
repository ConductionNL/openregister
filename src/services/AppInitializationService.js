/**
 * App Initialization Service
 *
 * Handles hot-loading of essential data at application startup.
 * This ensures that frequently used data is available immediately
 * without requiring API calls when modals/components open.
 */

import {
	registerStore,
	schemaStore,
	organisationStore,
	applicationStore,
	viewsStore,
	agentStore,
	sourceStore,
	conversationStore,
} from '../store/store.js'

/**
 * Hot-load all essential application data.
 *
 * This function is called once at application startup to pre-load
 * data that is frequently needed across the application.
 *
 * @return {Promise<void>}
 */
export async function initializeAppData() {
	console.info('[AppInit] Starting application data initialization...')

	const startTime = performance.now()

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
			loadAgents(),
			loadSources(),
			loadConversations(),
		])

		const endTime = performance.now()
		console.info(`[AppInit] ✓ All data loaded successfully in ${Math.round(endTime - startTime)}ms`)
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
 */
export async function reloadAppData() {
	console.info('[AppInit] Reloading all application data...')

	const startTime = performance.now()

	try {
		// Force reload all data in parallel
		await Promise.all([
			forceLoadRegisters(),
			forceLoadSchemas(),
			forceLoadOrganisations(),
			forceLoadApplications(),
			forceLoadViews(),
			forceLoadAgents(),
			forceLoadSources(),
			forceLoadConversations(),
		])

		const endTime = performance.now()
		console.info(`[AppInit] ✓ All data reloaded successfully in ${Math.round(endTime - startTime)}ms`)
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
	if (registerStore.list.length === 0) {
		console.info('[AppInit] Loading registers...')
		await registerStore.refreshList()
		console.info(`[AppInit] ✓ Loaded ${registerStore.list.length} registers`)
	} else {
		console.info(`[AppInit] ↷ Registers already loaded (${registerStore.list.length})`)
	}
}

/**
 * Force load registers (always refreshes).
 *
 * @return {Promise<void>}
 */
async function forceLoadRegisters() {
	console.info('[AppInit] Reloading registers...')
	await registerStore.refreshList()
	console.info(`[AppInit] ✓ Reloaded ${registerStore.list.length} registers`)
}

/**
 * Load schemas if not already loaded.
 *
 * @return {Promise<void>}
 */
async function loadSchemas() {
	if (schemaStore.list.length === 0) {
		console.info('[AppInit] Loading schemas...')
		await schemaStore.refreshList()
		console.info(`[AppInit] ✓ Loaded ${schemaStore.list.length} schemas`)
	} else {
		console.info(`[AppInit] ↷ Schemas already loaded (${schemaStore.list.length})`)
	}
}

/**
 * Force load schemas (always refreshes).
 *
 * @return {Promise<void>}
 */
async function forceLoadSchemas() {
	console.info('[AppInit] Reloading schemas...')
	await schemaStore.refreshList()
	console.info(`[AppInit] ✓ Reloaded ${schemaStore.list.length} schemas`)
}

/**
 * Load organisations if not already loaded.
 * Also fetches the active organisation from the user session.
 *
 * @return {Promise<void>}
 */
async function loadOrganisations() {
	if (!organisationStore.list || organisationStore.list.length === 0) {
		console.info('[AppInit] Loading organisations...')
		await organisationStore.refreshList()
		console.info(`[AppInit] ✓ Loaded ${organisationStore.list?.length || 0} organisations`)
	} else {
		console.info(`[AppInit] ↷ Organisations already loaded (${organisationStore.list.length})`)
	}

	// Always fetch the active organisation from session
	if (!organisationStore.activeOrganisation) {
		console.info('[AppInit] Fetching active organisation from session...')
		await organisationStore.getActiveOrganisation()
		console.info(`[AppInit] ✓ Active organisation: ${organisationStore.activeOrganisation?.name || 'none'}`)
	} else {
		console.info(`[AppInit] ↷ Active organisation already set: ${organisationStore.activeOrganisation.name}`)
	}
}

/**
 * Force load organisations (always refreshes).
 * Also refetches the active organisation from the user session.
 *
 * @return {Promise<void>}
 */
async function forceLoadOrganisations() {
	console.info('[AppInit] Reloading organisations...')
	await organisationStore.refreshList()
	console.info(`[AppInit] ✓ Reloaded ${organisationStore.list?.length || 0} organisations`)

	// Always refetch the active organisation from session
	console.info('[AppInit] Refetching active organisation from session...')
	await organisationStore.getActiveOrganisation()
	console.info(`[AppInit] ✓ Active organisation: ${organisationStore.activeOrganisation?.name || 'none'}`)
}

/**
 * Load applications if not already loaded.
 *
 * @return {Promise<void>}
 */
async function loadApplications() {
	if (!applicationStore.list || applicationStore.list.length === 0) {
		console.info('[AppInit] Loading applications...')
		await applicationStore.refreshList()
		console.info(`[AppInit] ✓ Loaded ${applicationStore.list?.length || 0} applications`)
	} else {
		console.info(`[AppInit] ↷ Applications already loaded (${applicationStore.list.length})`)
	}
}

/**
 * Force load applications (always refreshes).
 *
 * @return {Promise<void>}
 */
async function forceLoadApplications() {
	console.info('[AppInit] Reloading applications...')
	await applicationStore.refreshList()
	console.info(`[AppInit] ✓ Reloaded ${applicationStore.list?.length || 0} applications`)
}

/**
 * Load views if not already loaded.
 *
 * @return {Promise<void>}
 */
async function loadViews() {
	// Views store may not have a list property, check the store structure
	try {
		console.info('[AppInit] Loading views...')
		await viewsStore.refreshList()
		console.info('[AppInit] ✓ Views loaded')
	} catch (error) {
		console.warn('[AppInit] ⚠ Could not load views:', error.message)
	}
}

/**
 * Force load views (always refreshes).
 *
 * @return {Promise<void>}
 */
async function forceLoadViews() {
	try {
		console.info('[AppInit] Reloading views...')
		await viewsStore.refreshList()
		console.info('[AppInit] ✓ Views reloaded')
	} catch (error) {
		console.warn('[AppInit] ⚠ Could not reload views:', error.message)
	}
}

/**
 * Load agents if not already loaded.
 *
 * @return {Promise<void>}
 */
async function loadAgents() {
	if (!agentStore.list || agentStore.list.length === 0) {
		console.info('[AppInit] Loading agents...')
		await agentStore.refreshList()
		console.info(`[AppInit] ✓ Loaded ${agentStore.list?.length || 0} agents`)
	} else {
		console.info(`[AppInit] ↷ Agents already loaded (${agentStore.list.length})`)
	}
}

/**
 * Force load agents (always refreshes).
 *
 * @return {Promise<void>}
 */
async function forceLoadAgents() {
	console.info('[AppInit] Reloading agents...')
	await agentStore.refreshList()
	console.info(`[AppInit] ✓ Reloaded ${agentStore.list?.length || 0} agents`)
}

/**
 * Load sources if not already loaded.
 *
 * @return {Promise<void>}
 */
async function loadSources() {
	if (!sourceStore.list || sourceStore.list.length === 0) {
		console.info('[AppInit] Loading sources...')
		await sourceStore.refreshList()
		console.info(`[AppInit] ✓ Loaded ${sourceStore.list?.length || 0} sources`)
	} else {
		console.info(`[AppInit] ↷ Sources already loaded (${sourceStore.list.length})`)
	}
}

/**
 * Force load sources (always refreshes).
 *
 * @return {Promise<void>}
 */
async function forceLoadSources() {
	console.info('[AppInit] Reloading sources...')
	await sourceStore.refreshList()
	console.info(`[AppInit] ✓ Reloaded ${sourceStore.list?.length || 0} sources`)
}

/**
 * Load conversations if not already loaded.
 *
 * @return {Promise<void>}
 */
async function loadConversations() {
	if (!conversationStore.list || conversationStore.list.length === 0) {
		console.info('[AppInit] Loading conversations...')
		await conversationStore.refreshList()
		console.info(`[AppInit] ✓ Loaded ${conversationStore.list?.length || 0} conversations`)
	} else {
		console.info(`[AppInit] ↷ Conversations already loaded (${conversationStore.list.length})`)
	}
}

/**
 * Force load conversations (always refreshes).
 *
 * @return {Promise<void>}
 */
async function forceLoadConversations() {
	console.info('[AppInit] Reloading conversations...')
	await conversationStore.refreshList()
	console.info(`[AppInit] ✓ Reloaded ${conversationStore.list?.length || 0} conversations`)
}

/**
 * Check if all essential data is loaded.
 *
 * @return {boolean} True if all data is loaded
 */
export function isAppDataLoaded() {
	return Boolean(
		registerStore.list.length > 0
		&& schemaStore.list.length > 0
		&& organisationStore.list?.length >= 0 // Allow 0 organisations
		&& applicationStore.list?.length >= 0 // Allow 0 applications
	)
}

export default {
	initializeAppData,
	reloadAppData,
	isAppDataLoaded,
}
