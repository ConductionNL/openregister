/**
 * The entries most ActionMenu lists have in common.
 *
 * @spec exclude Client-side menu entry helpers.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { translate as t } from '@nextcloud/l10n'
import Eye from 'vue-material-design-icons/Eye.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'

/**
 * The View entry.
 *
 * @param {() => void} run What the entry does
 * @param {object} state Optional `hidden`, `disabled` and `title`
 * @return {object} The entry
 */
export function viewAction(run, state = {}) {
	return { label: t('openregister', 'View'), icon: Eye, run, ...state }
}

/**
 * The Edit entry.
 *
 * @param {() => void} run What the entry does
 * @param {object} state Optional `hidden`, `disabled` and `title`
 * @return {object} The entry
 */
export function editAction(run, state = {}) {
	return { label: t('openregister', 'Edit'), icon: Pencil, run, ...state }
}

/**
 * The Delete entry.
 *
 * @param {() => void} run What the entry does
 * @param {object} state Optional `hidden`, `disabled` and `title`
 * @return {object} The entry
 */
export function deleteAction(run, state = {}) {
	return {
		label: t('openregister', 'Delete'),
		icon: TrashCanOutline,
		run,
		...state,
	}
}

/**
 * The Refresh entry.
 *
 * @param {() => void} run What the entry does
 * @return {object} The entry
 */
export function refreshAction(run) {
	return { label: t('openregister', 'Refresh'), icon: Refresh, run }
}
