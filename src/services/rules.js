/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The rules engine's six endpoints, in one place.
 *
 * Every rule surface in the app reads from here rather than building its own
 * URL, so the day a path moves it moves once. The shapes returned are the
 * envelopes the controller publishes, untouched: a wrapper that reshaped them
 * would be a second contract to keep in step with the first.
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const BASE = '/apps/openregister/api'

/**
 * The published vocabulary: the kinds, the verdicts and the actions.
 *
 * Read rather than hardcoded. A surface that reads it renders a rule kind it
 * has never seen; a surface that copied the list renders a blank.
 *
 * @return {Promise<object>} The three closed sets.
 *
 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
 */
export async function fetchVocabulary() {
	const { data } = await axios.get(generateUrl(`${BASE}/rules/vocabulary`))
	return data
}

/**
 * Every rule that can act on a schema's objects, in evaluation order.
 *
 * @param {string|number} schema Schema id, uuid or slug.
 * @param {number} [idleDays] The window a rule is reported idle after.
 *
 * @return {Promise<object>} The schema slug and its rules.
 *
 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
 */
export async function fetchInventory(schema, idleDays) {
	const url = generateUrl(`${BASE}/schemas/{schema}/rules`, { schema })
	const params = {}
	if (idleDays) {
		params.idleDays = idleDays
	}
	const { data } = await axios.get(url, { params })
	return data
}

/**
 * Switch one rule off or on.
 *
 * @param {string|number} schema Schema id, uuid or slug.
 * @param {string} ruleId The derived rule id.
 * @param {boolean} enabled Whether the rule should run.
 *
 * @return {Promise<object>} The rule as it now stands, with the audit entry.
 *
 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
 */
export async function setRuleEnabled(schema, ruleId, enabled) {
	const url = generateUrl(`${BASE}/schemas/{schema}/rules/{ruleId}`, { schema, ruleId })
	const { data } = await axios.patch(url, { enabled })
	return data
}

/**
 * Evaluate one rule without committing, against a sample or a stored object.
 *
 * @param {string|number} schema Schema id, uuid or slug.
 * @param {string} ruleId The derived rule id.
 * @param {object} payload Either `{ object }` or `{ register, objectId }`, plus an optional `rule` override.
 *
 * @return {Promise<object>} The verdict, the trace and the writes it would make.
 *
 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
 */
export async function evaluateRule(schema, ruleId, payload) {
	const url = generateUrl(`${BASE}/schemas/{schema}/rules/{ruleId}/evaluate`, { schema, ruleId })
	const { data } = await axios.post(url, payload)
	return data
}

/**
 * Preview a replay of one rule over objects that already exist.
 *
 * Writes nothing. It answers with a previewed bulk job, or with the refusal
 * the rule's own ceiling produced.
 *
 * @param {string|number} schema Schema id, uuid or slug.
 * @param {string} ruleId The derived rule id.
 * @param {object} payload `{ selection, justification, registerId }`.
 *
 * @return {Promise<object>} The previewed job, or the refusal.
 *
 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
 */
export async function replayRule(schema, ruleId, payload) {
	const url = generateUrl(`${BASE}/schemas/{schema}/rules/{ruleId}/replay`, { schema, ruleId })
	const { data } = await axios.post(url, payload)
	return data
}

/**
 * One rule's run log, newest first.
 *
 * @param {string} ruleId The derived rule id.
 * @param {object} [filters] `{ verdict, since, until, limit, offset }`.
 *
 * @return {Promise<object>} The runs and how many matched.
 *
 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
 */
export async function fetchRuns(ruleId, filters = {}) {
	const url = generateUrl(`${BASE}/rules/{ruleId}/runs`, { ruleId })
	const { data } = await axios.get(url, { params: filters })
	return data
}

/**
 * The published JSON-AST operator catalogue.
 *
 * It is generated from the evaluator's own dispatch table, so an operator
 * added to the engine appears here without a second edit. A picker that
 * carried its own list would tell an author an operator is unavailable on the
 * day it shipped.
 *
 * @return {Promise<Array<object>>} The operator rows, or an empty list.
 *
 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
 */
export async function fetchOperators() {
	const { data } = await axios.get(generateUrl(`${BASE}/schemas/calculation-operators`))
	return data?.operators ?? []
}

/**
 * The message behind a failed call, in words a person can act on.
 *
 * An axios failure carries the refusal envelope the controller sent, and that
 * envelope names the rule and the numbers. Falling back to "request failed"
 * throws away the only useful part.
 *
 * @param {object} error The axios error.
 * @param {string} fallback What to say when the response carries nothing.
 *
 * @return {string} The message.
 *
 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
 */
export function messageFor(error, fallback) {
	return (
		error?.response?.data?.error?.message
		|| error?.response?.data?.error
		|| error?.message
		|| fallback
	)
}
