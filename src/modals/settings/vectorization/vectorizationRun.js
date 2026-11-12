/**
 * The counters of a vectorization run, shared by the vectorization modals.
 *
 * @spec exclude UI plumbing shared by the vectorization modals.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

/**
 * The counters of a run that has not started.
 *
 * @return {{processing: boolean, processed: number, vectorized: number, failed: number}} The counters
 */
export function idleRun() {
	return { processing: false, processed: 0, vectorized: 0, failed: 0 }
}

/**
 * Reset the counters of a modal and mark it processing.
 *
 * @param {object} modal The modal component
 * @return {void}
 */
export function beginRun(modal) {
	Object.assign(modal, idleRun(), { processing: true })
}
