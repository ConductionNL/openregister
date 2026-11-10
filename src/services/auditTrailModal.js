/**
 * What the audit trail changes and details modals share: reading the changed
 * data of the selected trail, formatting it, copying it and closing.
 *
 * @spec exclude Client-side helpers of the audit trail modals.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { auditTrailStore, navigationStore } from '../store/store.js'

/**
 * Whether a changed value holds any data.
 *
 * @param {*} changed The changed value of a trail
 * @return {boolean} True when it holds data
 */
function holdsData(changed) {
	if (Array.isArray(changed)) {
		return changed.length > 0
	}
	if (changed && typeof changed === 'object') {
		return Object.keys(changed).length > 0
	}
	return !!changed
}

/**
 * Pretty-print a JSON string, or return it unchanged when it is not JSON.
 *
 * @param {string} text The text
 * @return {string} The printed text
 */
function prettyJson(text) {
	try {
		return JSON.stringify(JSON.parse(text), null, 2)
	} catch {
		return text
	}
}

export default {
	computed: {
		/**
		 * Whether the selected trail has changes data.
		 *
		 * @return {boolean} True if it has changes
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		hasChanges() {
			return holdsData(auditTrailStore.auditTrailItem?.changed)
		},
	},

	methods: {
		/**
		 * Close the dialog.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		closeDialog() {
			navigationStore.setDialog(false)
		},

		/**
		 * Format a date for display.
		 *
		 * @param {string} dateString Date string to format
		 * @return {string} Formatted date
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		formatDate(dateString) {
			if (!dateString) return '-'
			return new Date(dateString).toLocaleString()
		},

		/**
		 * Format changes data for display.
		 *
		 * @param {*} changes Changes data
		 * @return {string} Formatted changes
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		formatChanges(changes) {
			if (!changes) return ''
			if (typeof changes === 'string') return prettyJson(changes)
			try {
				return JSON.stringify(changes, null, 2)
			} catch {
				return String(changes)
			}
		},

		/**
		 * Copy the changes of the selected trail to the clipboard.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		async copyChanges() {
			try {
				await navigator.clipboard.writeText(
					this.formatChanges(auditTrailStore.auditTrailItem.changed),
				)
				OC.Notification.showSuccess(
					this.t('openregister', 'Changes copied to clipboard'),
				)
			} catch {
				OC.Notification.showError(
					this.t('openregister', 'Failed to copy changes'),
				)
			}
		},
	},
}
