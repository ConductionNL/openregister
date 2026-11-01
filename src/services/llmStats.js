/**
 * Turns the LLM settings, vector statistics and database answers into the
 * state the LLM configuration section shows.
 *
 * @spec exclude UI plumbing, display mapping of settings and statistics answers
 */

/** Per provider, the config block and field its embedding and chat model sit in. */
const MODEL_FIELDS = {
	embedding: new Map([
		['openai', ['openaiConfig', 'model']],
		['fireworks', ['fireworksConfig', 'embeddingModel']],
		['ollama', ['ollamaConfig', 'model']],
	]),
	chat: new Map([
		['openai', ['openaiConfig', 'chatModel']],
		['fireworks', ['fireworksConfig', 'chatModel']],
		['ollama', ['ollamaConfig', 'chatModel']],
	]),
}

/** Display field to answer field, for the file and object statistics. */
const FILE_STAT_FIELDS = {
	totalFiles: 'total_files',
	filesExtracted: 'files_extracted',
	vectorizedFiles: 'vectorized_files',
	pendingFiles: 'pending_files',
	totalChunks: 'total_chunks',
}

const OBJECT_STAT_FIELDS = {
	totalObjects: 'total_objects',
	vectorizedObjects: 'vectorized_objects',
	pendingObjects: 'pending_objects',
	progressPercentage: 'percentage_complete',
}

/**
 * Copy the answer's counts onto the display fields, 0 when a count is missing.
 *
 * @param {object} source The answer block.
 * @param {object} fields Display field to answer field.
 * @return {object} The counts by display field.
 */
function counts(source, fields) {
	return Object.fromEntries(
		Object.entries(fields).map(([field, key]) => [field, source[key] || 0]),
	)
}

/**
 * Where a provider keeps its model of one kind, if the provider is known.
 *
 * @param {object} settings The stored LLM settings.
 * @param {string} kind `embedding` or `chat`.
 * @return {Array<string>|undefined} The config block and field, or undefined.
 */
function modelLocation(settings, kind) {
	return MODEL_FIELDS[kind].get(settings[`${kind}Provider`])
}

/**
 * Set the providers on a provider config, and each model whose provider is known.
 * A model whose provider is unknown keeps its current value.
 *
 * @param {object} providerConfig The provider config to update.
 * @param {object} settings The stored LLM settings.
 *
 * @spec exclude UI plumbing, display mapping of the stored LLM settings
 */
export function applyProviderConfig(providerConfig, settings) {
	providerConfig.embeddingProvider = settings.embeddingProvider || null
	providerConfig.chatProvider = settings.chatProvider || null
	Object.assign(providerConfig, knownModels(settings))
}

/**
 * The model at a location in the settings, or null.
 *
 * @param {object} settings The stored LLM settings.
 * @param {Array<string>} location The config block and field.
 * @return {string|null} The model.
 */
function modelAt(settings, location) {
	return (settings[location[0]] || {})[location[1]] || null
}

/**
 * The embedding and chat models, for each kind whose provider is known.
 *
 * @param {object} settings The stored LLM settings.
 * @return {object} embeddingModel and chatModel, where known.
 */
function knownModels(settings) {
	const models = {}
	for (const kind of ['embedding', 'chat']) {
		const location = modelLocation(settings, kind)
		if (location) {
			models[`${kind}Model`] = modelAt(settings, location)
		}
	}
	return models
}

/**
 * The first set count, else 0.
 *
 * @param {Array<number|undefined>} values The candidate counts, in order.
 * @return {number} The first truthy value, or 0.
 */
function firstCount(values) {
	return values.find(Boolean) || 0
}

/**
 * The storage size in MB with one decimal, '0.0' when unknown or zero-length.
 *
 * @param {object|undefined} storage The storage block.
 * @return {string} The size.
 */
function storageMB(storage) {
	return storage?.total_mb?.toFixed(1) || '0.0'
}

/**
 * The vector counts and storage of a vector statistics block.
 *
 * @param {object} stats The statistics block.
 * @return {object} totalVectors, objectVectors, fileVectors and storageMB.
 *
 * @spec exclude UI plumbing, display mapping of the vector statistics answer
 */
export function vectorStatsFrom(stats) {
	const byType = stats.by_type || {}
	return {
		totalVectors: stats.total_vectors || 0,
		objectVectors: firstCount([byType.object, stats.object_vectors]),
		fileVectors: firstCount([byType.file, stats.file_vectors]),
		storageMB: storageMB(stats.storage),
	}
}

/**
 * Apply a vector statistics answer to the section: its connection status,
 * vector statistics, and the file and object statistics it carries.
 *
 * @param {object} target The section.
 * @param {object} response The vector statistics answer.
 *
 * @spec exclude UI plumbing, display mapping of the vector statistics answer
 */
export function applyVectorAnswer(target, response) {
	target.llmConnectionStatus = response.success ? 'Connected' : 'Disconnected'
	Object.assign(target.vectorStats, vectorStatsFrom(response.stats || response))
	applyFileAndObjectStats(target, response)
}

/**
 * Copy the file and object counts of a vector statistics answer onto the
 * section's file and object statistics, for each block the answer has.
 *
 * @param {object} target The section, holding fileStats and objectStats.
 * @param {object} response The vector statistics answer.
 */
function applyFileAndObjectStats(target, response) {
	if (response.files) {
		Object.assign(target.fileStats, counts(response.files, FILE_STAT_FIELDS))
	}
	if (response.objects) {
		Object.assign(
			target.objectStats,
			counts(response.objects, OBJECT_STAT_FIELDS),
		)
	}
}

/**
 * The vector fields of the database info: with the database vector backend
 * on a database that supports it, a note and plugin saying it is in use.
 *
 * @param {object} db The database answer.
 * @param {string|undefined} backend The configured vector backend.
 * @return {object} vectorSupport, recommendedPlugin and performanceNote.
 */
function vectorFields(db, backend) {
	if (backend === 'database' && db.vectorSupport) {
		return {
			vectorSupport: true,
			recommendedPlugin: db.recommendedPlugin + ' (active ✓)',
			performanceNote: `✅ Using ${db.type} with native vector operations for fast similarity search.`,
		}
	}
	return {
		vectorSupport: db.vectorSupport,
		recommendedPlugin: db.recommendedPlugin,
		performanceNote: db.performanceNote,
	}
}

/**
 * The extensions, hybrid search readiness and update time of a database answer.
 *
 * @param {object} db The database answer.
 * @return {object} extensions, hybridSearch and lastUpdated.
 */
function detailFields(db) {
	return {
		extensions: db.extensions || [],
		hybridSearch: db.hybridSearch || null,
		lastUpdated: db.lastUpdated || null,
	}
}

/**
 * The database info the section shows, from the database answer and the
 * configured vector backend.
 *
 * @param {object} db The database answer.
 * @param {string|undefined} backend The configured vector backend, if known.
 * @return {object} The database info.
 *
 * @spec exclude UI plumbing, display mapping of the database answer
 */
export function databaseInfoFrom(db, backend) {
	const vector = vectorFields(db, backend)
	return {
		type: db.type || 'Unknown',
		version: db.version || 'Unknown',
		vectorSupport: vector.vectorSupport,
		recommendedPlugin: vector.recommendedPlugin || null,
		performanceNote: vector.performanceNote,
		...detailFields(db),
	}
}
