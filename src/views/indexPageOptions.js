/**
 * Settings the CnIndexPage list views share.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec exclude Presentational list-view configuration shared by the index pages.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { translate as t } from '@nextcloud/l10n'
import { navigationStore } from '../store/store.js'

/**
 * CnIndexPage's own row and mass actions, all switched off: these list views
 * bring their own action menus. Bind with `v-bind`.
 */
export const BUILTIN_ACTIONS_OFF = Object.freeze({
	showEditAction: false,
	showCopyAction: false,
	showDeleteAction: false,
	showMassImport: false,
	showMassExport: false,
	showMassCopy: false,
	showMassDelete: false,
})

/**
 * The sortable created and updated columns that close a list table.
 *
 * @param {object} [extra] Further settings for both columns, such as a `type`.
 * @return {Array<object>} The two column definitions.
 */
export function timestampColumns(extra = {}) {
	return [
		{ key: 'created', label: t('openregister', 'Created'), sortable: true, ...extra },
		{ key: 'updated', label: t('openregister', 'Updated'), sortable: true, ...extra },
	]
}

/**
 * The `openModal` and `openDialog` methods of a list view: select the row in
 * its store, then open the named modal or dialog on it.
 *
 * @param {function(object): void} selectItem Puts a row into its store as the current item.
 * @return {{openModal: function(object, string): void, openDialog: function(object, string): void}} The two methods.
 */
export function rowOpeners(selectItem) {
	return {
		openModal(item, modal) {
			selectItem(item)
			navigationStore.setModal(modal)
		},
		openDialog(item, dialog) {
			selectItem(item)
			navigationStore.setDialog(dialog)
		},
	}
}
