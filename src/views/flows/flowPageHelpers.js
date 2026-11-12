/**
 * The template helpers the flow overview and the run page both expose, and
 * the version label they both show.
 *
 * Spread `flowPageHelpers` into a view's `methods` so its template can call
 * the formatters from flowDetail.js by name.
 *
 * @spec openspec/specs/flow-and-run-detail-pages/spec.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import {
	formatDateTime,
	formatDuration,
	shortUuid,
	shownStatus,
	statusLabel,
	statusVariant,
	triggerLabel,
} from './flowDetail.js'

export const flowPageHelpers = {
	formatDateTime,
	formatDuration,
	shortUuid,
	shownStatus,
	statusLabel,
	statusVariant,
	triggerLabel,
}

/**
 * The semantic version a run executed, read from the flow's version list.
 *
 * @param {Array<{version: number, semver: ?string}>} versions The flow's versions.
 * @param {number|null|undefined} version The run's numeric flow version.
 * @return {string} "v1.0.0", "v1" when the version is not listed, or "" without a version.
 * @spec openspec/specs/flow-and-run-detail-pages/spec.md#requirement-a-run-has-its-own-page
 */
export function flowVersionLabel(versions, version) {
	if (version === null || version === undefined) {
		return ''
	}
	const match = versions.find((row) => row.version === version)
	return 'v' + (match?.semver || version)
}
