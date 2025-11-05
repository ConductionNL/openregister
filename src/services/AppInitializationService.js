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
} from '../store/store.js'

/**
 * Hot-load all essential application data.
 * 
 * This function is called once at application startup to pre-load
 * data that is frequently needed across the application.
 * 
 * @returns {Promise<void>}
 */
export async function initializeAppData() {
	console.log('[AppInit] Starting application data initialization...')
	
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
		])
		
		const endTime = performance.now()
		console.log(`[AppInit] ✓ All data loaded successfully in ${Math.round(endTime - startTime)}ms`)
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
 * @returns {Promise<void>}
 */
export async function reloadAppData() {
	console.log('[AppInit] Reloading all application data...')
	
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
		])
		
		const endTime = performance.now()
		console.log(`[AppInit] ✓ All data reloaded successfully in ${Math.round(endTime - startTime)}ms`)
	} catch (error) {
		console.error('[AppInit] ✗ Error during reload:', error)
		// Don't throw - allow app to continue even if some data fails to reload
	}
}

/**
 * Load registers if not already loaded.
 * 
 * @returns {Promise<void>}
 */
async function loadRegisters() {
	if (registerStore.registerList.length === 0) {
		console.log('[AppInit] Loading registers...')
		await registerStore.refreshRegisterList()
		console.log(`[AppInit] ✓ Loaded ${registerStore.registerList.length} registers`)
	} else {
		console.log(`[AppInit] ↷ Registers already loaded (${registerStore.registerList.length})`)
	}
}

/**
 * Force load registers (always refreshes).
 * 
 * @returns {Promise<void>}
 */
async function forceLoadRegisters() {
	console.log('[AppInit] Reloading registers...')
	await registerStore.refreshRegisterList()
	console.log(`[AppInit] ✓ Reloaded ${registerStore.registerList.length} registers`)
}

/**
 * Load schemas if not already loaded.
 * 
 * @returns {Promise<void>}
 */
async function loadSchemas() {
	if (schemaStore.schemaList.length === 0) {
		console.log('[AppInit] Loading schemas...')
		await schemaStore.refreshSchemaList()
		console.log(`[AppInit] ✓ Loaded ${schemaStore.schemaList.length} schemas`)
	} else {
		console.log(`[AppInit] ↷ Schemas already loaded (${schemaStore.schemaList.length})`)
	}
}

/**
 * Force load schemas (always refreshes).
 * 
 * @returns {Promise<void>}
 */
async function forceLoadSchemas() {
	console.log('[AppInit] Reloading schemas...')
	await schemaStore.refreshSchemaList()
	console.log(`[AppInit] ✓ Reloaded ${schemaStore.schemaList.length} schemas`)
}

/**
 * Load organisations if not already loaded.
 * 
 * @returns {Promise<void>}
 */
async function loadOrganisations() {
	if (!organisationStore.organisationList || organisationStore.organisationList.length === 0) {
		console.log('[AppInit] Loading organisations...')
		await organisationStore.refreshOrganisationList()
		console.log(`[AppInit] ✓ Loaded ${organisationStore.organisationList?.length || 0} organisations`)
	} else {
		console.log(`[AppInit] ↷ Organisations already loaded (${organisationStore.organisationList.length})`)
	}
}

/**
 * Force load organisations (always refreshes).
 * 
 * @returns {Promise<void>}
 */
async function forceLoadOrganisations() {
	console.log('[AppInit] Reloading organisations...')
	await organisationStore.refreshOrganisationList()
	console.log(`[AppInit] ✓ Reloaded ${organisationStore.organisationList?.length || 0} organisations`)
}

/**
 * Load applications if not already loaded.
 * 
 * @returns {Promise<void>}
 */
async function loadApplications() {
	if (!applicationStore.applicationList || applicationStore.applicationList.length === 0) {
		console.log('[AppInit] Loading applications...')
		await applicationStore.refreshApplicationList()
		console.log(`[AppInit] ✓ Loaded ${applicationStore.applicationList?.length || 0} applications`)
	} else {
		console.log(`[AppInit] ↷ Applications already loaded (${applicationStore.applicationList.length})`)
	}
}

/**
 * Force load applications (always refreshes).
 * 
 * @returns {Promise<void>}
 */
async function forceLoadApplications() {
	console.log('[AppInit] Reloading applications...')
	await applicationStore.refreshApplicationList()
	console.log(`[AppInit] ✓ Reloaded ${applicationStore.applicationList?.length || 0} applications`)
}

/**
 * Load views if not already loaded.
 * 
 * @returns {Promise<void>}
 */
async function loadViews() {
	// Views store may not have a list property, check the store structure
	try {
		console.log('[AppInit] Loading views...')
		await viewsStore.fetchViews()
		console.log('[AppInit] ✓ Views loaded')
	} catch (error) {
		console.warn('[AppInit] ⚠ Could not load views:', error.message)
	}
}

/**
 * Force load views (always refreshes).
 * 
 * @returns {Promise<void>}
 */
async function forceLoadViews() {
	try {
		console.log('[AppInit] Reloading views...')
		await viewsStore.fetchViews()
		console.log('[AppInit] ✓ Views reloaded')
	} catch (error) {
		console.warn('[AppInit] ⚠ Could not reload views:', error.message)
	}
}

/**
 * Load agents if not already loaded.
 * 
 * @returns {Promise<void>}
 */
async function loadAgents() {
	if (!agentStore.agentList || agentStore.agentList.length === 0) {
		console.log('[AppInit] Loading agents...')
		await agentStore.refreshAgentList()
		console.log(`[AppInit] ✓ Loaded ${agentStore.agentList?.length || 0} agents`)
	} else {
		console.log(`[AppInit] ↷ Agents already loaded (${agentStore.agentList.length})`)
	}
}

/**
 * Force load agents (always refreshes).
 * 
 * @returns {Promise<void>}
 */
async function forceLoadAgents() {
	console.log('[AppInit] Reloading agents...')
	await agentStore.refreshAgentList()
	console.log(`[AppInit] ✓ Reloaded ${agentStore.agentList?.length || 0} agents`)
}

/**
 * Load sources if not already loaded.
 * 
 * @returns {Promise<void>}
 */
async function loadSources() {
	if (!sourceStore.sourceList || sourceStore.sourceList.length === 0) {
		console.log('[AppInit] Loading sources...')
		await sourceStore.refreshSourceList()
		console.log(`[AppInit] ✓ Loaded ${sourceStore.sourceList?.length || 0} sources`)
	} else {
		console.log(`[AppInit] ↷ Sources already loaded (${sourceStore.sourceList.length})`)
	}
}

/**
 * Force load sources (always refreshes).
 * 
 * @returns {Promise<void>}
 */
async function forceLoadSources() {
	console.log('[AppInit] Reloading sources...')
	await sourceStore.refreshSourceList()
	console.log(`[AppInit] ✓ Reloaded ${sourceStore.sourceList?.length || 0} sources`)
}

/**
 * Check if all essential data is loaded.
 * 
 * @returns {boolean} True if all data is loaded
 */
export function isAppDataLoaded() {
	return Boolean(
		registerStore.registerList.length > 0 &&
		schemaStore.schemaList.length > 0 &&
		organisationStore.organisationList?.length >= 0 && // Allow 0 organisations
		applicationStore.applicationList?.length >= 0 // Allow 0 applications
	)
}

export default {
	initializeAppData,
	reloadAppData,
	isAppDataLoaded,
}

