/**
 * What the file configuration page says about each detection backend
 * (anonymisation-discloses-itself): its measured accuracy for the current
 * detector version, or that this version is not measured, and its training
 * use and retention, editable where an administrator states them.
 *
 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-precision-and-recall-are-measured-over-a-named-corpus-and-published-with-the-detector-version-req-adi-002
 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-training-use-and-retention-are-stated-per-backend-req-adi-003
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

/** The training-use values a statement can hold. */
export const TRAINING_USES = ['excluded', 'permitted', 'unknown']

/** An ISO 8601 duration, as the server accepts it. */
const DURATION = /^P(?!$)(\d+Y)?(\d+M)?(\d+W)?(\d+D)?(T(?=\d)(\d+H)?(\d+M)?(\d+S)?)?$/

/**
 * Which version of the detector a record names: the pattern set for the
 * pattern backend, else the model with its version, else the engine's.
 *
 * @param {object|null} detector A detector version record.
 * @return {string}
 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-precision-and-recall-are-measured-over-a-named-corpus-and-published-with-the-detector-version-req-adi-002
 */
export function detectorLabel(detector) {
	if (!detector) {
		return 'unknown'
	}
	const known = (value) => typeof value === 'string' && value !== '' && value !== 'unknown'
	if (known(detector.patternSetVersion) && !known(detector.model)) {
		return `${detector.engine || 'regex'} ${detector.patternSetVersion}`
	}
	if (known(detector.model)) {
		return known(detector.modelVersion) ? `${detector.model} ${detector.modelVersion}` : detector.model
	}
	return known(detector.engineVersion) ? `${detector.engine} ${detector.engineVersion}` : (detector.engine || 'unknown')
}

/**
 * A share as a whole-number percentage, or a dash when there is none.
 *
 * @param {number|null|undefined} value The share, 0 to 1.
 * @return {string}
 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-precision-and-recall-are-measured-over-a-named-corpus-and-published-with-the-detector-version-req-adi-002
 */
export function percent(value) {
	return typeof value === 'number' && Number.isFinite(value) ? `${Math.round(value * 100)}%` : '—'
}

/**
 * The accuracy a backend shows: `measured` with its figures when the latest
 * evaluation describes the current detector, else `notMeasured`. Figures of
 * another detector version are never shown as current.
 *
 * @param {object} backend A backend from backend-state.
 * @return {object} `{ state, corpus, corpusVersion, detector, measuredAt, types }`
 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-precision-and-recall-are-measured-over-a-named-corpus-and-published-with-the-detector-version-req-adi-002
 */
export function accuracyOf(backend) {
	const evaluation = backend?.evaluation
	const current = detectorLabel(backend?.detector)
	if (!evaluation || evaluation.current !== true || !evaluation.types) {
		return { state: 'notMeasured', detector: current }
	}
	return {
		state: 'measured',
		corpus: evaluation.corpus,
		corpusVersion: evaluation.corpusVersion,
		detector: detectorLabel(evaluation.detector),
		measuredAt: String(evaluation.measuredAt || '').slice(0, 10),
		types: Object.entries(evaluation.types).map(([type, figures]) => ({
			type,
			precision: percent(figures.precision),
			recall: percent(figures.recall),
			support: figures.support ?? 0,
		})),
	}
}

/**
 * One row per backend the page describes: every backend with a statement
 * that is configured or active, except the composed hybrid one.
 *
 * @param {object|null} backendState GET backend-state.
 * @param {object} labels Display name per backend.
 * @return {Array<object>} `{ name, label, editable, statement, accuracy }`
 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-training-use-and-retention-are-stated-per-backend-req-adi-003
 */
export function disclosureRows(backendState, labels = {}) {
	const backends = Object.values(backendState?.backends || {})
	return backends
		.filter((backend) => backend.name !== 'hybrid' && backend.statement)
		.filter((backend) => backend.configured || backend.name === backendState.activeMethod)
		.map((backend) => ({
			name: backend.name,
			label: labels[backend.name] || backend.name,
			editable: backend.statement.declaredBy !== 'product',
			statement: { trainingUse: backend.statement.trainingUse, retention: backend.statement.retention },
			accuracy: accuracyOf(backend),
		}))
}

/**
 * Whether a retention value is one the server keeps: `none`, `unknown` or an ISO 8601 duration.
 *
 * @param {string} value The retention.
 * @return {boolean}
 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-training-use-and-retention-are-stated-per-backend-req-adi-003
 */
export function isRetention(value) {
	const text = String(value ?? '').trim()
	return text === 'none' || text === 'unknown' || DURATION.test(text)
}

/**
 * The `statements` to save: every statement an administrator made, with the
 * given backend's replaced. The product's own statements are never sent.
 *
 * @param {object|null} backendState GET backend-state.
 * @param {string} name The backend being stated.
 * @param {{trainingUse: string, retention: string}} statement Its new statement.
 * @return {object} `{ <backend>: { trainingUse, retention } }`
 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-training-use-and-retention-are-stated-per-backend-req-adi-003
 */
export function statementsPayload(backendState, name, statement) {
	const statements = {}
	for (const backend of Object.values(backendState?.backends || {})) {
		if (backend.statement && backend.statement.declaredBy !== 'product' && backend.name !== 'hybrid') {
			statements[backend.name] = { trainingUse: backend.statement.trainingUse, retention: backend.statement.retention }
		}
	}
	statements[name] = { trainingUse: statement.trainingUse, retention: String(statement.retention).trim() }
	return statements
}
