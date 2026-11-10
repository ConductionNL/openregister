/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Shared wiring of a mail sidebar tab that shows data for the open message:
 * the account and message id props, and a load on creation and whenever the
 * message changes.
 *
 * @spec openspec/specs/mail-sidebar/spec.md
 */

/**
 * Options mixin for a tab that loads data for the open mail message.
 *
 * @param {string} load Name of the tab method that (re)loads its data
 * @return {object} The mixin: props, a messageId watcher and created hook
 */
export function mailMessageTab(load) {
	return {
		props: {
			accountId: { type: Number, default: null },
			messageId: { type: Number, default: null },
		},
		watch: {
			messageId() {
				this[load]()
			},
		},
		created() {
			this[load]()
		},
	}
}
