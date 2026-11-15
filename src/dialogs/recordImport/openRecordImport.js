/**
 * Hand a record file to the record-import dialog.
 *
 * The one entry point a drop target uses: it passes the file and the
 * register and schema it was dropped on, and nothing else. Reading rows,
 * mapping columns and writing objects all happen behind the import surface.
 *
 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md#requirement-the-widget-must-delegate-not-implement
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */
import { isRecordFile } from '../../services/recordImport.js'
import { navigationStore } from '../../store/store.js'

/** The dialog's name in the navigation store. */
export const RECORD_IMPORT_DIALOG = 'recordImport'

/**
 * Open the record-import dialog for a file, when it is a record file and the target is known.
 *
 * @param {{file: File, register: string|number, schema: string|number}} handoff The file and its target.
 * @return {boolean} True when the dialog was opened.
 *
 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md#requirement-the-widget-must-delegate-not-implement
 */
export function openRecordImport({ file, register, schema }) {
	if (!isRecordFile(file) || !register || !schema) {
		return false
	}

	navigationStore.setTransferData({ recordImport: { file, register, schema } })
	navigationStore.setDialog(RECORD_IMPORT_DIALOG)
	return true
}

/**
 * The file and target handed to the dialog, or null.
 *
 * @return {{file: File, register: string|number, schema: string|number}|null} The handoff.
 *
 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md#requirement-the-widget-must-delegate-not-implement
 */
export function recordImportHandoff() {
	return navigationStore.getTransferData()?.recordImport ?? null
}
