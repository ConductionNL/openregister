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
 * kept, whether every hop is audit-trailed, and whether the oversight gate
 * runs. `auditEnabled` and `oversightEnabled` default oppositely on purpose:
 * auditing is write volume and opt-in, oversight is what stops a running flow
 * and so must be on unless deliberately turned off.
 *
 * @spec openspec/specs/entity-management-modals/spec.md
 * @return {object}
 */
export function defaultFlowOptions() {
	return {
		retentionDays: 31,
		auditEnabled: false,
		oversightEnabled: true,
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
		enabledFileTypes: [
			'txt',
			'pdf',
			'docx',
			'xlsx',
			'pptx',
			'html',
			'md',
			'json',
		],
	}
}

/**
 * The system statistics before they are loaded.
 *
 * @spec openspec/specs/entity-management-modals/spec.md
 * @return {object}
 */
export function emptySystemStats() {
	return {
		warnings: {
			objectsWithoutOwner: 0,
			objectsWithoutOrganisation: 0,
			auditTrailsWithoutExpiry: 0,
			searchTrailsWithoutExpiry: 0,
			expiredAuditTrails: 0,
			expiredSearchTrails: 0,
			expiredObjects: 0,
		},
		totals: {
			totalObjects: 0,
			totalBlobObjects: 0,
			totalMagicObjects: 0,
			totalSize: 0,
			totalBlobSize: 0,
			totalMagicSize: 0,
			totalAuditTrails: 0,
			totalSearchTrails: 0,
			totalConfigurations: 0,
			totalDataAccessProfiles: 0,
			totalOrganisations: 0,
			totalRegisters: 0,
			totalSchemas: 0,
			totalSources: 0,
			totalWebhookLogs: 0,
			deletedObjects: 0,
		},
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
		overview: {
			totalCacheSize: 0,
			totalCacheEntries: 0,
			overallHitRate: 0.0,
			averageResponseTime: 0.0,
			cacheEfficiency: 0.0,
		},
		services: {
			object: emptyServiceCacheStats(),
			schema: emptyServiceCacheStats(),
			facet: emptyServiceCacheStats(),
		},
		distributed: {
			type: 'unknown',
			backend: 'Unknown',
			available: false,
		},
		performance: {
			averageHitTime: 0,
			averageMissTime: 0,
			performanceGain: 0,
			optimalHitRate: 85.0,
			currentTrend: 'unknown',
		},
		names: {
			cache_size: 0,
			hit_rate: 0.0,
			hits: 0,
			misses: 0,
			warmups: 0,
			enabled: false,
		},
		lastUpdated: null,
		unavailable: true,
		errorMessage: 'Loading...',
	}
}
