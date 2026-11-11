/**
 * Initial state of the settings store.
 *
 * Each function returns a fresh object, so two stores never share nested state.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

/**
 * The cache statistics of one cache service before they are loaded.
 *
 * @spec openspec/specs/entity-management-modals/spec.md
 * @return {object}
 */
function emptyServiceCacheStats() {
	return { entries: 0, hits: 0, requests: 0, memoryUsage: 0 }
}

/**
 * The RBAC settings before they are loaded.
 *
 * @spec openspec/specs/entity-management-modals/spec.md
 * @return {object}
 */
export function defaultRbacOptions() {
	return {
		enabled: false,
		anonymousGroup: 'public',
		defaultNewUserGroup: 'viewer',
		defaultObjectOwner: '',
		adminOverride: true,
	}
}

/**
 * The multitenancy settings before they are loaded.
 *
 * @spec openspec/specs/entity-management-modals/spec.md
 * @return {object}
 */
export function defaultMultitenancyOptions() {
	return {
		enabled: false,
		defaultUserTenant: '',
		defaultObjectTenant: '',
		adminOverride: true,
	}
}

/**
 * The flow-engine instance policy before it is loaded.
 *
 * Administrator settings, not personal preferences: how long run history is
 * kept and whether every hop is audit-trailed, which is write volume and opt-in.
 *
 * @spec openspec/specs/entity-management-modals/spec.md
 * @return {object}
 */
export function defaultFlowOptions() {
	return {
		retentionDays: 31,
		auditEnabled: false,
		killSwitch: false,
	}
}

/**
 * The retention settings before they are loaded, in milliseconds.
 *
 * @spec openspec/specs/entity-management-modals/spec.md
 * @return {object}
 */
export function defaultRetentionOptions() {
	return {
		objectArchiveRetention: 31536000000, // 1 year
		objectDeleteRetention: 63072000000, // 2 years
		searchTrailRetention: 2592000000, // 1 month
		createLogRetention: 2592000000, // 1 month
		readLogRetention: 86400000, // 24 hours
		updateLogRetention: 604800000, // 1 week
		deleteLogRetention: 2592000000, // 1 month
		auditTrailsEnabled: true, // Audit trails enabled by default
		searchTrailsEnabled: true, // Search trails enabled by default
	}
}

/**
 * The LLM settings before they are loaded.
 *
 * @spec openspec/specs/entity-management-modals/spec.md
 * @return {object}
 */
export function defaultLlmOptions() {
	return {
		enabled: false,
		providerId: 'none',
		apiEndpoint: '',
		apiKey: '',
		model: null,
		temperature: 0.7,
		maxTokens: 2000,
		enabledFeatures: [],
	}
}

/**
 * The file extraction settings before they are loaded.
 *
 * @spec openspec/specs/entity-management-modals/spec.md
 * @return {object}
 */
export function defaultFileOptions() {
	return {
		extractionScope: 'objects', // none, all, folders, objects
		extractionMode: 'background', // background, immediate, manual
		maxFileSize: 100,
		batchSize: 10,
		enabledFileTypes: defaultFileTypes(),
	}
}

/**
 * The file types text is extracted from by default.
 *
 * @return {Array<string>} The extensions
 */
function defaultFileTypes() {
	return [
		'txt',
		'pdf',
		'docx',
		'xlsx',
		'pptx',
		'html',
		'md',
		'json',
	]
}

// The warning counts and totals of the system statistics, in display order.
const SYSTEM_WARNING_COUNTS = [
	'objectsWithoutOwner',
	'objectsWithoutOrganisation',
	'auditTrailsWithoutExpiry',
	'searchTrailsWithoutExpiry',
	'expiredAuditTrails',
	'expiredSearchTrails',
	'expiredObjects',
]
const SYSTEM_TOTAL_COUNTS = [
	'totalObjects',
	'totalBlobObjects',
	'totalMagicObjects',
	'totalSize',
	'totalBlobSize',
	'totalMagicSize',
	'totalAuditTrails',
	'totalSearchTrails',
	'totalConfigurations',
	'totalDataAccessProfiles',
	'totalOrganisations',
	'totalRegisters',
	'totalSchemas',
	'totalSources',
	'totalWebhookLogs',
	'deletedObjects',
]

/**
 * Every count of a list at 0.
 *
 * @param {Array<string>} keys The counts.
 * @return {object} The counts, each 0.
 * @spec openspec/specs/entity-management-modals/spec.md
 */
function zeroCounts(keys) {
	return Object.fromEntries(keys.map((key) => [key, 0]))
}

/**
 * The system statistics before they are loaded.
 *
 * @spec openspec/specs/entity-management-modals/spec.md
 * @return {object}
 */
export function emptySystemStats() {
	return {
		warnings: zeroCounts(SYSTEM_WARNING_COUNTS),
		totals: zeroCounts(SYSTEM_TOTAL_COUNTS),
		lastUpdated: null,
	}
}

/**
 * The cache statistics before they are loaded.
 *
 * @spec openspec/specs/entity-management-modals/spec.md
 * @return {object}
 */
export function emptyCacheStats() {
	return {
		overview: emptyCacheOverview(),
		services: emptyServicesCacheStats(),
		distributed: { type: 'unknown', backend: 'Unknown', available: false },
		performance: emptyCachePerformance(),
		names: emptyNamesCacheStats(),
		lastUpdated: null,
		unavailable: true,
		errorMessage: 'Loading...',
	}
}

/**
 * The per-service cache figures before they are loaded.
 *
 * @return {object}
 * @spec openspec/specs/entity-management-modals/spec.md
 */
function emptyServicesCacheStats() {
	return {
		object: emptyServiceCacheStats(),
		schema: emptyServiceCacheStats(),
		facet: emptyServiceCacheStats(),
	}
}

/**
 * The cache overview before it is loaded.
 *
 * @return {object}
 * @spec openspec/specs/entity-management-modals/spec.md
 */
function emptyCacheOverview() {
	return {
		totalCacheSize: 0,
		totalCacheEntries: 0,
		overallHitRate: 0.0,
		averageResponseTime: 0.0,
		cacheEfficiency: 0.0,
	}
}

/**
 * The cache performance figures before they are loaded.
 *
 * @return {object}
 * @spec openspec/specs/entity-management-modals/spec.md
 */
function emptyCachePerformance() {
	return {
		averageHitTime: 0,
		averageMissTime: 0,
		performanceGain: 0,
		optimalHitRate: 85.0,
		currentTrend: 'unknown',
	}
}

/**
 * The names cache figures before they are loaded.
 *
 * @return {object}
 * @spec openspec/specs/entity-management-modals/spec.md
 */
function emptyNamesCacheStats() {
	return {
		cache_size: 0,
		hit_rate: 0.0,
		hits: 0,
		misses: 0,
		warmups: 0,
		enabled: false,
	}
}
