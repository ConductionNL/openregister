/**
 * OpenRegister Settings Store
 *
 * Centralized state management for all settings sections using Pinia.
 * This store handles data fetching, state management, and API calls for:
 * - RBAC settings
 * - Multitenancy configuration
 * - Retention policies
 * - Cache management
 * - System statistics
 */

import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'
import {
	defaultFileOptions,
	defaultFlowOptions,
	defaultLlmOptions,
	defaultMultitenancyOptions,
	defaultRbacOptions,
	defaultRetentionOptions,
	emptyCacheStats,
	emptySystemStats,
} from './settingsDefaults.js'

// The warning counts that make the retention status unhealthy, and the
// words each count is reported with.
const RETENTION_ISSUES = [
	['auditTrailsWithoutExpiry', 'audit trails without expiry'],
	['searchTrailsWithoutExpiry', 'search trails without expiry'],
	['expiredAuditTrails', 'expired audit trails'],
	['expiredSearchTrails', 'expired search trails'],
	['expiredObjects', 'expired objects'],
]

/**
 * The retention problems the warning counts show, one phrase per count above 0.
 *
 * @param {object} warnings The warning counts from the stats.
 * @return {Array<string>} The phrases, e.g. `3 expired objects`.
 * @spec exclude Client-side wording of the retention warning counts; no backend contract.
 */
function retentionIssues(warnings) {
	return RETENTION_ISSUES.filter(([key]) => warnings[key] > 0).map(
		([key, words]) => `${warnings[key]} ${words}`,
	)
}

/**
 * The body of a mass validate request, with the defaults for what the
 * options leave out.
 *
 * @param {object} options The mass validate options.
 * @return {{batchSize: number, maxObjects: number, mode: string, collectErrors: boolean}} The body.
 * @spec exclude API passthrough to POST /api/settings/mass-validate
 */
function massValidateRequest(options) {
	return {
		batchSize: options.batchSize || 1000,
		maxObjects: options.maxObjects || 0,
		mode: options.mode || 'serial',
		collectErrors: options.collectErrors || false,
	}
}

/**
 * Ask the server to validate the objects.
 *
 * @param {object} options The mass validate options.
 * @return {Promise<object>} The axios response.
 * @spec exclude API passthrough to POST /api/settings/mass-validate
 */
function requestMassValidation(options) {
	return axios.post(
		generateUrl('/apps/openregister/api/settings/mass-validate'),
		massValidateRequest(options),
	)
}

/**
 * Tell the user how a mass validation the server answered went.
 *
 * @param {object} data The server's answer.
 * @return {void}
 * @spec exclude API passthrough to POST /api/settings/mass-validate
 */
function reportMassValidation(data) {
	if (data.success) {
		showSuccess(
			t('openregister', 'Mass validation completed successfully'),
		)
	} else {
		showMassValidateFailure(data.message)
	}
}

/**
 * Log a failed settings load and tell the user.
 *
 * @param {Error} error What went wrong.
 * @return {void}
 * @spec exclude parallel fan-out wrapper over settings-load passthroughs
 */
function showLoadFailure(error) {
	console.error('Failed to load settings:', error)
	showError(
		t('openregister', 'Failed to load settings: {error}', {
			error: error.message,
		}),
	)
}

/**
 * Tell the user a mass validation failed.
 *
 * @param {string} message Why it failed.
 * @return {void}
 * @spec exclude API passthrough to POST /api/settings/mass-validate
 */
function showMassValidateFailure(message) {
	showError(
		t('openregister', 'Mass validation failed: {error}', {
			error: message,
		}),
	)
}

/**
 * The results a failed mass validation leaves in the store.
 *
 * @param {string} message Why it failed.
 * @return {object} The results, with zero counts.
 * @spec exclude API passthrough to POST /api/settings/mass-validate
 */
function failedMassValidation(message) {
	return {
		success: false,
		message,
		error: message,
		stats: {
			total_objects: 0,
			processed_objects: 0,
			successful_saves: 0,
			failed_saves: 0,
			duration_seconds: 0,
		},
		errors: [],
	}
}

/**
 * Run a step and handle its failure: log it, show the failure message when
 * there is one, then return the fallback when there is one, or rethrow.
 *
 * @param {() => Promise<object|undefined>} step The step to run.
 * @param {object} report `log` (the console text), and optionally `failed(error)` (the message shown) and `fallback(error)` (what to return instead).
 * @return {Promise<object|undefined>} What the step, or the fallback, returns.
 * @spec exclude Client-side failure reporting shared by the settings API passthroughs.
 */
async function attempt(step, report) {
	try {
		return await step()
	} catch (error) {
		console.error(report.log, error)
		if (report.failed) {
			showError(report.failed(error))
		}
		if (report.fallback) {
			return report.fallback(error)
		}
		throw error
	}
}

/**
 * Run work with a store flag raised, and lower the flag when the work ends.
 *
 * @param {object} store The settings store.
 * @param {object} job `run()` (the work) and optionally `flag` (the state key to raise).
 * @return {Promise<object|undefined>} What the work returns.
 * @spec exclude Client-side loading flag around the settings API passthroughs.
 */
async function flagged(store, job) {
	if (!job.flag) {
		return job.run()
	}
	store[job.flag] = true
	try {
		return await job.run()
	} finally {
		store[job.flag] = false
	}
}

/**
 * Read settings into the store. A failure is logged and leaves the store as it was.
 *
 * @param {object} store The settings store.
 * @param {object} job `send()`, `apply(data)` (runs when the answer has data), `log`, and optionally `flag` and `fallback()`.
 * @return {Promise<object|undefined>} What `apply` returns, or the fallback after a failure.
 * @spec exclude API passthrough helper for the settings GET endpoints.
 */
function readSettings(store, job) {
	return flagged(store, {
		flag: job.flag,
		run: () =>
			attempt(
				async () => {
					const response = await job.send()
					return response.data ? job.apply(response.data) : undefined
				},
				{ log: job.log, fallback: job.fallback ?? (() => undefined) },
			),
	})
}

/**
 * Write settings and report the outcome: merge the answer into the store and
 * show the success message, or log and show the failure and rethrow it.
 *
 * @param {object} store The settings store.
 * @param {object} job `send()`, `log`, `failed(error)`, and optionally `flag`, `apply(data)` and `done` (the success message).
 * @return {Promise<object>} The answer's data.
 * @spec exclude API passthrough helper for the settings write endpoints.
 */
function writeSettings(store, job) {
	return flagged(store, {
		flag: job.flag,
		run: () =>
			attempt(async () => {
				const response = await job.send()
				if (response.data && job.apply) {
					job.apply(response.data)
				}
				if (job.done) {
					showSuccess(job.done)
				}
				return response.data
			}, job),
	})
}

/**
 * Merge freshly loaded statistics into the store and stamp them.
 *
 * @param {object} store The settings store.
 * @param {object} job `flag`, `key` (the state key), `path`, `log` and `failed(error)`.
 * @return {Promise<void>}
 * @spec exclude API passthrough helper for the settings statistics endpoints.
 */
function loadStatistics(store, job) {
	return flagged(store, {
		flag: job.flag,
		run: () =>
			attempt(
				async () => {
					const response = await axios.get(api(job.path))
					if (response.data) {
						store[job.key] = { ...store[job.key], ...response.data }
						store[job.key].lastUpdated = new Date().toISOString()
					}
				},
				{ log: job.log, failed: job.failed, fallback: () => undefined },
			),
	})
}

/**
 * Delete every record of one kind, tell the user how many went, and close
 * the confirmation dialog.
 *
 * @param {object} store The settings store.
 * @param {object} job `flag`, `path`, `hide` (the dialog's hide action), `done(count)`, `failed(reason)` and `log`.
 * @return {Promise<void>}
 * @spec exclude API passthrough helper for the clear-all endpoints.
 */
function clearAll(store, job) {
	return flagged(store, {
		flag: job.flag,
		run: () =>
			attempt(
				async () => {
					const response = await axios.delete(api(job.path))
					if (response.data.success) {
						showSuccess(job.done(response.data.deleted || 0))
						store[job.hide]()
					} else {
						showError(job.failed(response.data.error || 'Unknown error'))
					}
				},
				{
					log: job.log,
					failed: (error) => job.failed(error.message),
					fallback: () => undefined,
				},
			),
	})
}

/**
 * Test the connection to a file processing service. A failure is answered,
 * not thrown.
 *
 * @param {object} job `path` (the test endpoint under settings/files) and `log`.
 * @param {object} connectionData The connection to test.
 * @return {Promise<object>} The test result.
 * @spec exclude API passthrough helper for POST /api/settings/files/test-*
 */
function testConnection(job, connectionData) {
	return attempt(
		() => dataOf(axios.post(api(`settings/files/${job.path}`), connectionData)),
		{
			log: job.log,
			fallback: (error) => ({
				success: false,
				error: error.response?.data?.error || error.message,
			}),
		},
	)
}

/**
 * Turn an `{id: label}` map into select options, or keep the current options
 * when the answer has no map.
 *
 * @param {object|undefined} map The map from the answer.
 * @param {Array<object>} current The current options.
 * @return {Array<{id: string, label: string}>} The options.
 * @spec exclude Client-side shaping of select options.
 */
function idLabelOptions(map, current) {
	return map ? Object.entries(map).map(([id, label]) => ({ id, label })) : current
}

/**
 * Merge answered values into the store's objects, one state key per entry.
 *
 * @param {object} store The settings store.
 * @param {object} parts The values to merge, keyed by state key.
 * @return {void}
 * @spec exclude Client-side merge of answered settings into the store.
 */
function merge(store, parts) {
	for (const [key, values] of Object.entries(parts)) {
		store[key] = { ...store[key], ...values }
	}
}

/**
 * The data of a request's answer.
 *
 * @param {Promise<object>} request The request.
 * @return {Promise<object>} The answer's data.
 * @spec exclude Client-side unwrapping of an API answer.
 */
async function dataOf(request) {
	const response = await request
	return response.data
}

/**
 * The URL of an OpenRegister API path.
 *
 * @param {string} path The path below `/apps/openregister/api/`.
 * @return {string} The URL.
 * @spec exclude Client-side URL building for the settings API passthroughs.
 */
function api(path) {
	return generateUrl(`/apps/openregister/api/${path}`)
}

/**
 * Whether an LLM settings patch only toggles `enabled`, which saves without a
 * success message.
 *
 * @param {object} partialData The patch.
 * @return {boolean} True when `enabled` is the only key.
 * @spec exclude Client-side choice of the LLM settings success message.
 */
function onlyTogglesEnabled(partialData) {
	return (
		Object.keys(partialData).length <= 1 && Object.hasOwn(partialData, 'enabled')
	)
}

/**
 * The probe result for an anonymisation backend that could not be reached.
 *
 * @param {Error} error Why the probe failed.
 * @return {object} The result.
 * @spec exclude Client-side fallback of POST /api/admin/anonymisation/test-connection
 */
function unreachableBackend(error) {
	return {
		reachable: false,
		latencyMs: null,
		error: error.response?.data?.error || error.message,
		probedAt: null,
	}
}

/**
 * The memory prediction shown when none could be loaded.
 *
 * @return {object} The prediction.
 * @spec exclude Client-side fallback of POST /api/settings/mass-validate/memory-prediction
 */
function unknownMemoryPrediction() {
	return {
		success: false,
		prediction_safe: true,
		formatted: {
			total_predicted: 'Unknown',
			available: 'Unknown',
		},
	}
}

/**
 * Send a request whose answer says whether it worked, and report the outcome:
 * run the success step, or show why the request was refused or failed.
 * A failure is logged, shown and rethrown.
 *
 * @param {object} job `send()`, `ok(data)`, `reason(data)` (why it was refused), `succeeded(data)`, `failed(reason)` (the message shown), `log`, and optionally `settled()` (runs after either outcome).
 * @return {Promise<object>} The answer's data.
 * @spec exclude API passthrough helper for the settings actions that answer with a success flag.
 */
function confirmed(job) {
	return attempt(
		async () => {
			const response = await job.send()
			if (job.ok(response.data)) {
				await job.succeeded(response.data)
			} else {
				showError(job.failed(job.reason(response.data) || 'Unknown error'))
			}
			await job.settled?.()
			return response.data
		},
		{ log: job.log, failed: (error) => job.failed(error.message) },
	)
}

/**
 * How the names cache size changed during a warmup.
 *
 * @param {number} oldSize The size before.
 * @param {number} newSize The size after.
 * @return {string} The sentence.
 * @spec exclude Client-side wording of the names cache warmup result.
 */
function cacheSizeChange(oldSize, newSize) {
	const sizes = { old: oldSize, new: newSize }
	if (newSize > oldSize) {
		return t('openregister', 'Cache grew from {old} to {new} entries.', sizes)
	}
	if (newSize < oldSize) {
		return t('openregister', 'Cache shrunk from {old} to {new} entries.', sizes)
	}
	return t('openregister', 'Cache stayed the same at {size} entries.', {
		size: newSize,
	})
}

/**
 * The success message of a names cache warmup.
 *
 * @param {object} data The warmup answer.
 * @return {string} The message.
 * @spec exclude Client-side wording of the names cache warmup result.
 */
function warmupMessage(data) {
	const oldSize = data.old_cache?.distributed_name_cache_size || 0
	const newSize = data.new_cache?.distributed_name_cache_size || 0
	return t(
		'openregister',
		'Names cache warmed up successfully: {count} names loaded in {time}. {message}',
		{
			count: data.loaded_names || 0,
			time: data.execution_time || '0ms',
			message: cacheSizeChange(oldSize, newSize),
		},
	)
}

/**
 * The settings store's loading flags and cached stats, before anything loads.
 *
 * @return {object} The state.
 * @spec exclude Client-side initial state of the settings store.
 */
function settingsLoadingState() {
	return {
		loading: false,
		loadingInProgress: false,
		saving: false,
		rebasing: false,
		loadingStats: false,
		loadingCacheStats: false,
		loadingVersionInfo: false,
		extractionStats: null,
		vectorStats: null,
	}
}

/**
 * The settings store's cache management state.
 *
 * @return {object} The state.
 * @spec exclude Client-side initial state of the settings store.
 */
function settingsCacheState() {
	return {
		clearingCache: false,
		warmingUpCache: false,
		showClearCacheConfirmation: false,
		clearCacheType: 'all',
		clearingAppStoreCache: false,
		warmupInterval: 3600,
		warmupLastRun: null,
		loadingWarmupInterval: false,
		savingWarmupInterval: false,
	}
}

/**
 * The settings store's mass validation and clear-logs state.
 *
 * @return {object} The state.
 * @spec exclude Client-side initial state of the settings store.
 */
function settingsMaintenanceState() {
	return {
		massValidating: false,
		showMassValidateConfirmation: false,
		massValidateResults: null,
		clearingAuditTrails: false,
		clearingSearchTrails: false,
		clearingBlobObjects: false,
		showClearAuditTrailsConfirmation: false,
		showClearSearchTrailsConfirmation: false,
		showClearBlobObjectsConfirmation: false,
	}
}

/**
 * The settings store's settings sections, before they load.
 *
 * @return {object} The state.
 * @spec exclude Client-side initial state of the settings store.
 */
function settingsSectionsState() {
	return {
		rbacOptions: defaultRbacOptions(),
		multitenancyOptions: defaultMultitenancyOptions(),
		flowOptions: defaultFlowOptions(),
		retentionOptions: defaultRetentionOptions(),
		llmOptions: defaultLlmOptions(),
		fileOptions: defaultFileOptions(),
		loadingLlmSettings: false,
		loadingFileSettings: false,
	}
}

/**
 * The settings store's version, select options and statistics, before they load.
 *
 * @return {object} The state.
 * @spec exclude Client-side initial state of the settings store.
 */
function settingsOptionsState() {
	return {
		versionInfo: { appName: 'Open Register', appVersion: '0.2.3' },
		groupOptions: [],
		userOptions: [],
		tenantOptions: [],
		stats: emptySystemStats(),
		cacheStats: emptyCacheStats(),
		showRebaseConfirmation: false,
	}
}

export const useSettingsStore = defineStore('settings', {
	state: () => ({
		...settingsLoadingState(),
		...settingsCacheState(),
		...settingsMaintenanceState(),
		...settingsSectionsState(),
		...settingsOptionsState(),
	}),

	getters: {
		/**
		 * Check if there are any warning items requiring attention
		 *
		 * @param {object} state - The state of the stats
		 */
		hasWarnings: (state) => {
			const warnings = state.stats.warnings
			return Object.values(warnings).some((count) => count > 0)
		},

		/**
		 * Get retention status information
		 *
		 * @param {object} state - The state of the retention settings
		 */
		retentionStatusClass: (state) =>
			retentionIssues(state.stats.warnings).length > 0
				? 'warning-status'
				: 'healthy-status',

		retentionStatusTextClass: (state) =>
			retentionIssues(state.stats.warnings).length > 0
				? 'status-warning'
				: 'status-healthy',

		retentionStatusMessage: (state) => {
			const issues = retentionIssues(state.stats.warnings)
			return issues.length > 0
				? `Issues found: ${issues.join(', ')}`
				: 'All retention policies are properly configured and applied'
		},
	},

	actions: {
		/**
		 * Load all settings data
		 *
		 * @spec exclude parallel fan-out wrapper over settings-load passthroughs
		 */
		async loadSettings() {
			// Prevent multiple simultaneous calls
			if (this.loading && this.loadingInProgress) {
				return
			}

			this.loading = true
			this.loadingInProgress = true

			try {
				await this.loadAllSections()
			} catch (error) {
				showLoadFailure(error)
			} finally {
				this.loading = false
				this.loadingInProgress = false
			}
		},

		/**
		 * Load every settings section, in parallel for better performance.
		 *
		 * @return {Promise<Array<object>>} The settled loads
		 * @spec exclude parallel fan-out wrapper over settings-load passthroughs
		 */
		loadAllSections() {
			return Promise.allSettled([
				this.loadRbacSettings(),
				this.loadMultitenancySettings(),
				this.loadRetentionSettings(),
				this.loadVersionInfo(),
				this.loadAvailableOptions(),
			])
		},

		/**
		 * Mass validate objects with advanced configuration
		 *
		 * @param {object} options - The options for the mass validate operation
		 * @spec exclude API passthrough to POST /api/settings/mass-validate
		 */
		async massValidate(options = {}) {
			this.massValidating = true
			this.massValidateResults = null

			try {
				const response = await requestMassValidation(options)
				this.massValidateResults = response.data
				reportMassValidation(response.data)
				return response.data
			} catch (error) {
				this.massValidationFailed(error)
				throw error
			} finally {
				this.massValidating = false
			}
		},
		/**
		 * Log a failed mass validation, say why it failed and keep its zero
		 * results.
		 *
		 * @param {Error} error What went wrong
		 * @return {void}
		 * @spec exclude API passthrough to POST /api/settings/mass-validate
		 */
		massValidationFailed(error) {
			console.error('Mass validation failed:', error)
			const errorMessage = error.response?.data?.message || error.message
			showMassValidateFailure(errorMessage)
			this.massValidateResults = failedMassValidation(errorMessage)
		},


		/**
		 * Load memory prediction for mass validation
		 *
		 * @param {number} maxObjects - The maximum number of objects to validate
		 * @spec exclude API passthrough to POST /api/settings/mass-validate/memory-prediction
		 */
		async loadMassValidateMemoryPrediction(maxObjects = 0) {
			return attempt(
				() =>
					dataOf(
						axios.post(api('settings/mass-validate/memory-prediction'), {
							maxObjects,
						}),
					),
				{
					log: 'Failed to load memory prediction:',
					fallback: unknownMemoryPrediction,
				},
			)
		},

		/**
		 * Show mass validate confirmation dialog
		 *
		 * @spec exclude store setter (local dialog-visibility toggle)
		 */
		showMassValidateDialog() {
			this.showMassValidateConfirmation = true
		},

		/**
		 * Hide mass validate confirmation dialog
		 *
		 * @spec exclude store setter (local dialog-visibility toggle)
		 */
		hideMassValidateDialog() {
			this.showMassValidateConfirmation = false
			this.massValidateResults = null
		},

		/**
		 * Confirm mass validate operation
		 *
		 * @param {object} options - The options for the mass validate operation
		 * @spec exclude dialog-confirm wrapper over massValidate (API passthrough)
		 */
		async confirmMassValidate(options = {}) {
			this.hideMassValidateDialog()
			return this.massValidate(options)
		},

		/**
		 * Load RBAC settings
		 *
		 * @spec exclude API passthrough to GET /api/settings/rbac
		 */
		async loadRbacSettings() {
			return readSettings(this, {
				send: () => axios.get(api('settings/rbac')),
				apply: (data) => {
					merge(this, { rbacOptions: data.rbac })
					this.groupOptions = idLabelOptions(
						data.availableGroups,
						this.groupOptions,
					)
					this.userOptions = idLabelOptions(
						data.availableUsers,
						this.userOptions,
					)
				},
				log: 'Failed to load RBAC settings:',
			})
		},

		/**
		 * Update RBAC settings
		 *
		 * @param {object} rbacData - The RBAC settings to save
		 * @spec exclude API passthrough to PUT /api/settings/rbac
		 */
		async updateRbacSettings(rbacData) {
			return writeSettings(this, {
				flag: 'saving',
				send: () => axios.put(api('settings/rbac'), rbacData),
				apply: (data) => merge(this, { rbacOptions: data.rbac }),
				done: t('openregister', 'RBAC settings updated successfully'),
				log: 'Failed to update RBAC settings:',
				failed: (error) =>
					t('openregister', 'Failed to update RBAC settings: {error}', {
						error: error.message,
					}),
			})
		},

		/**
		 * Load Multitenancy settings
		 *
		 * @spec exclude API passthrough to GET /api/settings/multitenancy
		 */
		async loadMultitenancySettings() {
			return readSettings(this, {
				send: () => axios.get(api('settings/multitenancy')),
				apply: (data) => {
					merge(this, { multitenancyOptions: data.multitenancy })
					this.tenantOptions = idLabelOptions(
						data.availableTenants,
						this.tenantOptions,
					)
				},
				log: 'Failed to load Multitenancy settings:',
			})
		},

		/**
		 * Update Multitenancy settings
		 *
		 * @param {object} multitenancyData - The multitenancy settings to save
		 * @spec exclude API passthrough to PUT /api/settings/multitenancy
		 */
		async updateMultitenancySettings(multitenancyData) {
			return writeSettings(this, {
				flag: 'saving',
				send: () =>
					axios.put(api('settings/multitenancy'), multitenancyData),
				apply: (data) =>
					merge(this, { multitenancyOptions: data.multitenancy }),
				done: t(
					'openregister',
					'Multitenancy settings updated successfully',
				),
				log: 'Failed to update Multitenancy settings:',
				failed: (error) =>
					t(
						'openregister',
						'Failed to update Multitenancy settings: {error}',
						{ error: error.message },
					),
			})
		},

		/**
		 * Load Retention settings
		 *
		 * @spec exclude API passthrough to GET /api/settings/retention
		 */
		async loadRetentionSettings() {
			return readSettings(this, {
				send: () => axios.get(api('settings/retention')),
				apply: (data) => merge(this, { retentionOptions: data }),
				log: 'Failed to load Retention settings:',
			})
		},

		/**
		 * Load the flow-engine instance policy.
		 *
		 * Reads the whole settings envelope, because the flow block is served by
		 * the generic GET rather than a dedicated endpoint. A failure leaves the
		 * store defaults in place rather than blanking the form — showing 0 days
		 * for a setting we could not read would invite an administrator to
		 * "correct" it to something destructive.
		 *
		 * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
		 * @return {Promise<void>}
		 */
		async loadFlowSettings() {
			return readSettings(this, {
				send: () => axios.get(api('settings')),
				apply: (data) => {
					if (data.flow) {
						merge(this, { flowOptions: data.flow })
					}
				},
				log: 'Failed to load flow settings:',
			})
		},

		/**
		 * Persist the flow-engine instance policy.
		 *
		 * @param {object} flowData The flow settings block.
		 * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
		 * @return {Promise<object>} The stored settings.
		 */
		async updateFlowSettings(flowData) {
			return writeSettings(this, {
				flag: 'saving',
				send: () => axios.put(api('settings'), { flow: flowData }),
				apply: (data) => {
					if (data.flow) {
						merge(this, { flowOptions: data.flow })
					}
				},
				done: t('openregister', 'Flow settings updated'),
				log: 'Failed to update flow settings:',
				failed: (error) =>
					t('openregister', 'Failed to update flow settings: {error}', {
						error: error.message,
					}),
			})
		},

		/**
		 * Read which groups see who read a record, with the groups to pick from.
		 * Nothing is kept in the store: the section holds its own copy.
		 *
		 * @spec openspec/changes/audit-read-events-visible-to/specs/audit-trail-immutable/spec.md#requirement-read-entries-are-shown-only-to-the-groups-named-for-them
		 * @return {Promise<{readEventsVisibleTo: Array<string>, groups: Array<{id: string, label: string}>}|undefined>} The setting and the group options.
		 */
		async loadAuditSettings() {
			return readSettings(this, {
				send: () => axios.get(api('settings')),
				apply: (data) => ({
					readEventsVisibleTo: Array.isArray(data.audit?.readEventsVisibleTo)
						? data.audit.readEventsVisibleTo
						: [],
					groups: idLabelOptions(data.availableGroups, []),
				}),
				log: 'Failed to load the read entry setting:',
			})
		},

		/**
		 * Store which groups see who read a record.
		 *
		 * @param {{readEventsVisibleTo: Array<string>}} auditData The group ids.
		 * @spec openspec/changes/audit-read-events-visible-to/specs/audit-trail-immutable/spec.md#requirement-read-entries-are-shown-only-to-the-groups-named-for-them
		 * @return {Promise<object>} The stored settings.
		 */
		async updateAuditSettings(auditData) {
			return writeSettings(this, {
				flag: 'saving',
				send: () => axios.put(api('settings'), { audit: auditData }),
				done: t('openregister', 'Read entry visibility updated'),
				log: 'Failed to update the read entry setting:',
				failed: (error) =>
					t('openregister', 'Failed to update the read entry setting: {error}', {
						error: error.message,
					}),
			})
		},

		/**
		 * Update Retention settings
		 *
		 * @param {object} retentionData - The retention settings to save
		 * @spec exclude API passthrough to PUT /api/settings/retention
		 */
		async updateRetentionSettings(retentionData) {
			return writeSettings(this, {
				flag: 'saving',
				send: () => axios.put(api('settings/retention'), retentionData),
				apply: (data) => merge(this, { retentionOptions: data }),
				done: t('openregister', 'Retention settings updated successfully'),
				log: 'Failed to update Retention settings:',
				failed: (error) =>
					t(
						'openregister',
						'Failed to update Retention settings: {error}',
						{ error: error.message },
					),
			})
		},

		/**
		 * Get LLM settings
		 *
		 * @spec exclude API passthrough to GET /api/settings/llm
		 */
		async getLlmSettings() {
			return readSettings(this, {
				flag: 'loadingLlmSettings',
				send: () => axios.get(api('settings/llm')),
				apply: (data) => {
					merge(this, { llmOptions: data })
					return this.llmOptions
				},
				log: 'Failed to load LLM settings:',
				fallback: () => this.llmOptions,
			})
		},

		/**
		 * Save LLM settings (full update - use patchLlmSettings for partial updates)
		 *
		 * @param {object} llmData - The LLM settings to save
		 * @spec exclude API passthrough to PATCH /api/settings/llm
		 */
		async saveLlmSettings(llmData) {
			return writeSettings(this, {
				send: () => axios.patch(api('settings/llm'), llmData),
				apply: (data) => merge(this, { llmOptions: data }),
				done: t('openregister', 'LLM settings saved successfully'),
				log: 'Failed to save LLM settings:',
				failed: (error) =>
					t('openregister', 'Failed to save LLM settings: {error}', {
						error: error.message,
					}),
			})
		},

		/**
		 * Patch LLM settings (partial update)
		 *
		 * @param {object} partialData - Partial LLM data to update
		 * @spec exclude API passthrough to PATCH /api/settings/llm (partial)
		 */
		async patchLlmSettings(partialData) {
			const announce = !onlyTogglesEnabled(partialData)
			return writeSettings(this, {
				send: () => axios.patch(api('settings/llm'), partialData),
				apply: (data) => {
					if (data.data) {
						merge(this, { llmOptions: data.data })
					}
				},
				done: announce
					? t('openregister', 'LLM settings updated successfully')
					: null,
				log: 'Failed to update LLM settings:',
				failed: (error) =>
					t('openregister', 'Failed to update LLM settings: {error}', {
						error: error.message,
					}),
			})
		},

		/**
		 * Get vector statistics
		 *
		 * @return {Promise<object>} Vector statistics including counts by type
		 * @spec exclude API passthrough to GET /api/vectors/stats (stat loader)
		 */
		async getVectorStats() {
			const stats = await attempt(
				() => dataOf(axios.get(api('vectors/stats'))),
				{
					log: 'Failed to load vector statistics:',
					// Empty stats instead of an error, so the UI keeps working.
					fallback: () => ({
						total_vectors: 0,
						by_type: { object: 0, file: 0 },
						connection_status: 'Error',
					}),
				},
			)
			this.vectorStats = stats
			return stats
		},

		/**
		 * Test LLM connection
		 *
		 * @param {object} connectionData - The connection data to test
		 * @spec exclude API passthrough to POST /api/settings/llm/test
		 */
		async testLlmConnection(connectionData) {
			return attempt(
				() => dataOf(axios.post(api('settings/llm/test'), connectionData)),
				{ log: 'Failed to test LLM connection:' },
			)
		},

		/**
		 * Get LLM usage statistics
		 *
		 * @spec exclude API passthrough to GET /api/settings/llm/usage
		 */
		async getLlmUsageStats() {
			return attempt(() => dataOf(axios.get(api('settings/llm/usage'))), {
				log: 'Failed to load LLM usage stats:',
				fallback: () => null,
			})
		},

		/**
		 * Get file settings
		 *
		 * @spec exclude API passthrough to GET /api/settings/files
		 */
		async getFileSettings() {
			return readSettings(this, {
				flag: 'loadingFileSettings',
				send: () => axios.get(api('settings/files')),
				apply: (data) => {
					merge(this, { fileOptions: data })
					return this.fileOptions
				},
				log: 'Failed to load file settings:',
				fallback: () => this.fileOptions,
			})
		},

		/**
		 * Save file settings
		 *
		 * @param {object} fileData - The file settings to save
		 * @spec exclude API passthrough to PUT /api/settings/files
		 */
		async saveFileSettings(fileData) {
			return writeSettings(this, {
				send: () => axios.put(api('settings/files'), fileData),
				apply: (data) => merge(this, { fileOptions: data }),
				done: t('openregister', 'File settings saved successfully'),
				log: 'Failed to save file settings:',
				failed: (error) =>
					t('openregister', 'Failed to save file settings: {error}', {
						error: error.message,
					}),
			})
		},

		/**
		 * Get file extraction statistics
		 *
		 * @spec exclude API passthrough to GET /api/files/stats (stat loader)
		 */
		async getExtractionStats() {
			return attempt(
				async () => {
					const response = await axios.get(api('files/stats'))
					const stats = response.data.data || response.data
					this.extractionStats = stats
					return stats
				},
				{ log: 'Failed to load extraction stats:', fallback: () => null },
			)
		},

		/**
		 * Discover files in Nextcloud that aren't tracked yet
		 *
		 * @spec exclude API passthrough to POST /api/files/discover
		 */
		async discoverFiles() {
			return attempt(
				() => dataOf(axios.post(api('files/discover'), { limit: 100 })),
				{
					log: 'Failed to discover files:',
					failed: (error) =>
						t('openregister', 'Failed to discover files: {error}', {
							error: error.message,
						}),
				},
			)
		},

		/**
		 * Trigger file extraction for pending or failed files
		 *
		 * @param {string} type - 'pending' or 'failed'
		 * @spec exclude API passthrough to POST /api/files/extract|retry-failed
		 */
		async triggerFileExtraction(type = 'pending') {
			const endpoint =
				type === 'failed' ? 'files/retry-failed' : 'files/extract'
			return attempt(() => dataOf(axios.post(api(endpoint), { limit: 100 })), {
				log: `Failed to trigger ${type} file extraction:`,
				failed: (error) =>
					t(
						'openregister',
						'Failed to start processing {type} files: {error}',
						{ type, error: error.message },
					),
			})
		},

		/**
		 * Test Dolphin API connection
		 *
		 * @param {object} connectionData - API endpoint and key
		 * @spec exclude API passthrough to POST /api/settings/files/test-dolphin
		 */
		async testDolphinConnection(connectionData) {
			return testConnection(
				{ path: 'test-dolphin', log: 'Failed to test Dolphin connection:' },
				connectionData,
			)
		},

		/**
		 * Test Presidio API connection
		 *
		 * @param {object} connectionData - API endpoint
		 * @spec exclude API passthrough to POST /api/settings/files/test-presidio
		 */
		async testPresidioConnection(connectionData) {
			return testConnection(
				{
					path: 'test-presidio',
					log: 'Failed to test Presidio connection:',
				},
				connectionData,
			)
		},

		/**
		 * Test OpenAnonymiser API connection
		 *
		 * @param {object} connectionData - API endpoint
		 * @spec exclude API passthrough to POST /api/settings/files/test-openanonymiser
		 */
		async testOpenAnonymiserConnection(connectionData) {
			return testConnection(
				{
					path: 'test-openanonymiser',
					log: 'Failed to test OpenAnonymiser connection:',
				},
				connectionData,
			)
		},

		/**
		 * Get the resolved anonymisation backend state (single source of truth).
		 *
		 * @spec exclude API passthrough to GET /api/admin/anonymisation/backend-state
		 */
		async getAnonymisationBackendState() {
			return attempt(
				() => dataOf(axios.get(api('admin/anonymisation/backend-state'))),
				{
					log: 'Failed to load anonymisation backend state:',
					fallback: () => null,
				},
			)
		},

		/**
		 * Probe a single anonymisation backend, bypassing the cache.
		 *
		 * @param {string} method - One of regex/presidio/openanonymiser/llm/hybrid
		 * @spec exclude API passthrough to POST /api/admin/anonymisation/test-connection
		 */
		async testAnonymisationBackend(method) {
			return attempt(
				() =>
					dataOf(
						axios.post(api('admin/anonymisation/test-connection'), {
							method,
						}),
					),
				{
					log: 'Failed to probe anonymisation backend:',
					fallback: unreachableBackend,
				},
			)
		},

		/**
		 * Load version information
		 *
		 * @spec exclude API passthrough to GET /api/settings/version
		 */
		async loadVersionInfo() {
			return readSettings(this, {
				flag: 'loadingVersionInfo',
				send: () => axios.get(api('settings/version')),
				apply: (data) => {
					merge(this, { versionInfo: data })
				},
				log: 'Failed to load version info:',
			})
		},

		/**
		 * Load available options (groups, users, tenants)
		 *
		 * @spec exclude no-op placeholder (options loaded by sibling settings actions)
		 */
		async loadAvailableOptions() {
			try {
				// These are loaded as part of the individual settings sections
				// This method exists for consistency and future extensibility
			} catch (error) {
				console.error('Failed to load available options:', error)
			}
		},

		/**
		 * Load system statistics
		 *
		 * @spec exclude API passthrough to GET /api/settings/statistics (stat loader)
		 */
		async loadStats() {
			return loadStatistics(this, {
				flag: 'loadingStats',
				key: 'stats',
				path: 'settings/statistics',
				log: 'Failed to load statistics:',
				failed: (error) =>
					t('openregister', 'Failed to load statistics: {error}', {
						error: error.message,
					}),
			})
		},

		/**
		 * Load cache statistics
		 *
		 * @spec exclude API passthrough to GET /api/settings/cache (stat loader)
		 */
		async loadCacheStats() {
			return loadStatistics(this, {
				flag: 'loadingCacheStats',
				key: 'cacheStats',
				path: 'settings/cache',
				log: 'Failed to load cache statistics:',
				failed: (error) =>
					t('openregister', 'Failed to load cache statistics: {error}', {
						error: error.message,
					}),
			})
		},

		/**
		 * Get chat and agent statistics
		 *
		 * @return {Promise<object>} Chat statistics including agents, conversations, and messages
		 * @spec exclude API passthrough to GET /api/chat/stats
		 */
		async getChatStats() {
			return attempt(() => dataOf(axios.get(api('chat/stats'))), {
				log: 'Failed to load chat statistics:',
				// Empty stats while the chat API is not available.
				fallback: () => ({
					total_agents: 0,
					total_conversations: 0,
					total_messages: 0,
				}),
			})
		},

		/**
		 * Clear specific cache type
		 *
		 * @param {string} cacheType - The type of cache to clear
		 * @spec exclude API passthrough to DELETE /api/settings/cache
		 */
		async clearSpecificCache(cacheType) {
			this.clearingCache = cacheType
			try {
				return await confirmed({
					send: () =>
						axios.delete(api('settings/cache'), {
							data: { type: cacheType },
						}),
					ok: (data) => data.success !== false,
					reason: (data) => data.message,
					succeeded: async () => {
						showSuccess(
							t('openregister', '{type} cache cleared successfully', {
								type: cacheType,
							}),
						)
						await this.loadCacheStats()
					},
					log: `Failed to clear ${cacheType} cache:`,
					failed: (reason) =>
						t('openregister', 'Failed to clear {type} cache: {error}', {
							type: cacheType,
							error: reason,
						}),
				})
			} finally {
				this.clearingCache = false
			}
		},

		/**
		 * Clear all caches
		 *
		 * @spec exclude convenience wrapper over clearSpecificCache (API passthrough)
		 */
		async clearAllCaches() {
			return this.clearSpecificCache('all')
		},

		/**
		 * Warmup names cache
		 *
		 * @spec exclude API passthrough to POST /api/settings/cache/warmup-names
		 */
		async warmupNamesCache() {
			return flagged(this, {
				flag: 'warmingUpCache',
				run: () =>
					confirmed({
						send: () => axios.post(api('settings/cache/warmup-names')),
						ok: (data) => data.success,
						reason: (data) => data.error,
						succeeded: (data) => showSuccess(warmupMessage(data)),
						settled: () => this.loadCacheStats(),
						log: 'Failed to warmup names cache:',
						failed: (reason) =>
							t(
								'openregister',
								'Failed to warmup names cache: {error}',
								{ error: reason },
							),
					}),
			})
		},

		/**
		 * Load cache warmup interval setting
		 *
		 * @spec exclude API passthrough to GET /api/settings/cache/warmup-interval
		 */
		async loadWarmupInterval() {
			return flagged(this, {
				flag: 'loadingWarmupInterval',
				run: () =>
					attempt(
						async () => {
							const response = await axios.get(
								api('settings/cache/warmup-interval'),
							)
							if (response.data) {
								this.warmupInterval = response.data.interval ?? 3600
								this.warmupLastRun = response.data.last_run ?? null
							}
							return response.data
						},
						{
							log: 'Failed to load warmup interval:',
							fallback: () => undefined,
						},
					),
			})
		},

		/**
		 * Save cache warmup interval setting
		 *
		 * @param {number} interval - The interval in seconds (0 = disabled)
		 * @spec exclude API passthrough to PUT /api/settings/cache/warmup-interval
		 */
		async saveWarmupInterval(interval) {
			return flagged(this, {
				flag: 'savingWarmupInterval',
				run: () =>
					confirmed({
						send: () =>
							axios.put(api('settings/cache/warmup-interval'), {
								interval,
							}),
						ok: (data) => data.success,
						reason: (data) => data.error,
						succeeded: (data) => {
							this.warmupInterval = data.interval
							showSuccess(data.message)
						},
						log: 'Failed to save warmup interval:',
						failed: (reason) =>
							t(
								'openregister',
								'Failed to save warmup interval: {error}',
								{ error: reason },
							),
					}),
			})
		},

		/**
		 * Rebase all objects and logs
		 *
		 * @spec exclude API passthrough to POST /api/settings/rebase
		 */
		async rebase() {
			return flagged(this, {
				flag: 'rebasing',
				run: () =>
					confirmed({
						send: () => axios.post(api('settings/rebase')),
						ok: (data) => data.success !== false,
						reason: (data) => data.message,
						succeeded: async () => {
							showSuccess(
								t(
									'openregister',
									'Rebase operation completed successfully',
								),
							)
							await this.loadStats()
						},
						log: 'Rebase operation failed:',
						failed: (reason) =>
							t('openregister', 'Rebase operation failed: {error}', {
								error: reason,
							}),
					}),
			})
		},

		/**
		 * Save general settings (legacy method for backwards compatibility)
		 *
		 * @param {object} data - The data to save
		 * @spec exclude dispatcher over update*Settings passthroughs + legacy PUT /api/settings
		 */
		async saveSettings(data) {
			return flagged(this, {
				flag: 'saving',
				run: () =>
					attempt(
						async () => {
							// Route to the save action of the section the data holds.
							if (data.rbac) {
								return this.updateRbacSettings(data.rbac)
							} else if (data.multitenancy) {
								return this.updateMultitenancySettings(
									data.multitenancy,
								)
							} else if (data.retention) {
								return this.updateRetentionSettings(data.retention)
							}
							const response = await axios.put(api('settings'), data)
							showSuccess(
								t('openregister', 'Settings updated successfully'),
							)
							return response.data
						},
						{
							log: 'Failed to save settings:',
							failed: (error) =>
								t(
									'openregister',
									'Failed to save settings: {error}',
									{ error: error.message },
								),
						},
					),
			})
		},

		/**
		 * Show rebase confirmation dialog
		 *
		 * @spec exclude store setter (local dialog-visibility toggle)
		 */
		showRebaseDialog() {
			this.showRebaseConfirmation = true
		},

		/**
		 * Hide rebase confirmation dialog
		 *
		 * @spec exclude store setter (local dialog-visibility toggle)
		 */
		hideRebaseDialog() {
			this.showRebaseConfirmation = false
		},

		/**
		 * Confirm and execute rebase
		 *
		 * @spec exclude dialog-confirm wrapper over rebase (API passthrough)
		 */
		async confirmRebase() {
			this.hideRebaseDialog()
			await this.rebase()
		},

		/**
		 * Clear cache of specified type
		 *
		 * @param {string} type - The type of cache to clear
		 * @spec exclude API passthrough to DELETE /api/settings/cache
		 */
		async clearCache(type = 'all') {
			return flagged(this, {
				flag: 'clearingCache',
				run: () =>
					attempt(
						async () => {
							const response = await axios.delete(
								api('settings/cache'),
								{ data: { type } },
							)
							if (response.data.success) {
								await this.loadCacheStats()
							}
						},
						{
							log: 'Failed to clear cache:',
							failed: (error) =>
								t('openregister', 'Failed to clear cache: {error}', {
									error: error.message,
								}),
							fallback: () => undefined,
						},
					),
			})
		},

		/**
		 * Show clear audit trails confirmation dialog
		 *
		 * @spec exclude store setter (local dialog-visibility toggle)
		 */
		showClearAuditTrailsDialog() {
			this.showClearAuditTrailsConfirmation = true
		},

		/**
		 * Hide clear audit trails confirmation dialog
		 *
		 * @spec exclude store setter (local dialog-visibility toggle)
		 */
		hideClearAuditTrailsDialog() {
			this.showClearAuditTrailsConfirmation = false
		},

		/**
		 * Clear all audit trails
		 *
		 * @spec exclude API passthrough to DELETE /api/audit-trails/clear-all
		 */
		async clearAllAuditTrails() {
			return clearAll(this, {
				flag: 'clearingAuditTrails',
				path: 'audit-trails/clear-all',
				hide: 'hideClearAuditTrailsDialog',
				done: (count) =>
					t('openregister', 'Successfully cleared {count} audit trails', {
						count,
					}),
				log: 'Failed to clear audit trails:',
				failed: (error) =>
					t('openregister', 'Failed to clear audit trails: {error}', {
						error,
					}),
			})
		},

		/**
		 * Show clear search trails confirmation dialog
		 *
		 * @spec exclude store setter (local dialog-visibility toggle)
		 */
		showClearSearchTrailsDialog() {
			this.showClearSearchTrailsConfirmation = true
		},

		/**
		 * Hide clear search trails confirmation dialog
		 *
		 * @spec exclude store setter (local dialog-visibility toggle)
		 */
		hideClearSearchTrailsDialog() {
			this.showClearSearchTrailsConfirmation = false
		},

		/**
		 * Clear all search trails
		 *
		 * @spec exclude API passthrough to DELETE /api/search-trails/clear-all
		 */
		async clearAllSearchTrails() {
			return clearAll(this, {
				flag: 'clearingSearchTrails',
				path: 'search-trails/clear-all',
				hide: 'hideClearSearchTrailsDialog',
				done: (count) =>
					t('openregister', 'Successfully cleared {count} search trails', {
						count,
					}),
				log: 'Failed to clear search trails:',
				failed: (error) =>
					t('openregister', 'Failed to clear search trails: {error}', {
						error,
					}),
			})
		},

		/**
		 * Show clear blob objects confirmation dialog
		 *
		 * @spec exclude store setter (local dialog-visibility toggle)
		 */
		showClearBlobObjectsDialog() {
			this.showClearBlobObjectsConfirmation = true
		},

		/**
		 * Hide clear blob objects confirmation dialog
		 *
		 * @spec exclude store setter (local dialog-visibility toggle)
		 */
		hideClearBlobObjectsDialog() {
			this.showClearBlobObjectsConfirmation = false
		},

		/**
		 * Clear all blob storage objects
		 *
		 * @spec exclude API passthrough to DELETE /api/objects/clear-blob
		 */
		async clearAllBlobObjects() {
			return clearAll(this, {
				flag: 'clearingBlobObjects',
				path: 'objects/clear-blob',
				hide: 'hideClearBlobObjectsDialog',
				done: (count) =>
					t(
						'openregister',
						'Successfully cleared {count} blob storage objects',
						{ count },
					),
				log: 'Failed to clear blob objects:',
				failed: (error) =>
					t('openregister', 'Failed to clear blob objects: {error}', {
						error,
					}),
			})
		},

		/**
		 * Show clear cache confirmation dialog
		 *
		 * @spec exclude store setter (local dialog-visibility toggle)
		 */
		showClearCacheDialog() {
			this.showClearCacheConfirmation = true
		},

		/**
		 * Hide clear cache confirmation dialog
		 *
		 * @spec exclude store setter (local dialog-visibility toggle)
		 */
		hideClearCacheDialog() {
			this.showClearCacheConfirmation = false
		},

		/**
		 * Perform cache clearing with current type selection
		 *
		 * @spec exclude dialog-confirm wrapper over clearCache (API passthrough)
		 */
		async performClearCache() {
			await this.clearCache(this.clearCacheType)
			this.hideClearCacheDialog()
		},

		// ========================================
		// App Store Cache Actions
		// ========================================

		/**
		 * Invalidate Nextcloud app store cache
		 * Forces Nextcloud to fetch fresh app data from apps.nextcloud.com
		 * by setting the cache timestamp to 0 (expired)
		 *
		 * @param {string} type - Type of cache to invalidate: 'apps', 'categories', 'discover', or 'all'
		 * @return {Promise<object>} The API response
		 * @spec exclude API passthrough to DELETE /api/settings/cache/appstore
		 */
		async clearAppStoreCache(type = 'all') {
			return flagged(this, {
				flag: 'clearingAppStoreCache',
				run: () =>
					confirmed({
						send: () =>
							axios.delete(api('settings/cache/appstore'), {
								data: { type },
							}),
						ok: (data) => data.success,
						reason: (data) => data.error,
						succeeded: (data) => {
							const invalidated =
								data.invalidated?.join(', ') || 'cache'
							showSuccess(
								t(
									'openregister',
									'App store cache invalidated: {invalidated}',
									{ invalidated },
								),
							)
						},
						log: 'Failed to invalidate app store cache:',
						failed: (reason) =>
							t(
								'openregister',
								'Failed to invalidate app store cache: {error}',
								{ error: reason },
							),
					}),
			})
		},
	},
})
