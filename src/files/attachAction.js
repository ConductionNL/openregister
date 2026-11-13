/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Files action "Add to object": on a file or a folder, open the picker and
 * attach the node to the register object the caller picks.
 *
 * Registered from the Files script entry (files-sidebar.js), so it exists on
 * every instance with OpenRegister enabled.
 *
 * @spec openspec/changes/files-leaf-save-to-object/specs/file-actions/spec.md#requirement-a-files-action-attaches-a-file-to-a-register-object
 */

import { showError, showSuccess } from '@nextcloud/dialogs'
import { Permission, registerFileAction } from '@nextcloud/files'
import { translate as t } from '@nextcloud/l10n'
import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import AttachToObjectDialog from '../dialogs/files/AttachToObjectDialog.vue'

export const ATTACH_ACTION_ID = 'openregister-add-to-object'

// database-plus-outline (MDI)
const icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M18 14H20V17H23V19H20V22H18V19H15V17H18V14M12 3C16.42 3 20 4.79 20 7V12.08C19.67 12.03 19.34 12 19 12C18.66 12 18.33 12.03 18 12.08V9.64C16.53 10.47 14.39 11 12 11S7.47 10.47 6 9.64V12.45C7.3 13.4 9.58 14 12 14C12.3 14 12.6 14 12.88 13.97C12.5 14.6 12.24 15.3 12.11 16H12C9.72 16 7.61 15.55 6 14.77V17C6 17.5 8.13 19 12 19H12.08C12.2 19.7 12.45 20.39 12.81 21H12C7.58 21 4 19.21 4 17V7C4 4.79 7.58 3 12 3M12 5C8.13 5 6 6.5 6 7S8.13 9 12 9 18 7.5 18 7 15.87 5 12 5Z" /></svg>'

/**
 * The Files action.
 *
 * Offered on exactly one readable node: attaching copies the node, so a caller
 * who cannot read it has nothing to attach.
 *
 * @type {import('@nextcloud/files').IFileAction}
 */
export const attachAction = {
	id: ATTACH_ACTION_ID,
	displayName: () => t('openregister', 'Add to object'),
	iconSvgInline: () => icon,
	order: 60,
	enabled: ({ nodes }) => Array.isArray(nodes)
		&& nodes.length === 1
		&& (nodes[0].permissions & Permission.READ) !== 0
		&& Number.isInteger(nodes[0].fileid),
	async exec({ nodes }) {
		const node = nodes[0]
		const attached = await spawnDialog(AttachToObjectDialog, { nodeId: node.fileid })
		if (!attached) {
			// Closed without picking: not a failure, nothing to report.
			return null
		}
		if (!Array.isArray(attached.attached) || attached.attached.length === 0) {
			showError(t('openregister', 'The file could not be added to the object.'))
			return false
		}
		showSuccess(t('openregister', 'Added to {title}', { title: attached.object.title }))
		return true
	},
}

/**
 * Register the action with Files. Safe to call more than once.
 *
 * @return {void}
 */
export function registerAttachAction() {
	try {
		registerFileAction(attachAction)
	} catch {
		// Already registered (the entry loaded twice): the action exists.
	}
}
