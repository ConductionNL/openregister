/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Talk entry point: registers OpenRegister's message action "Save chat to
 * object" with Talk. Added to Talk's page only, by TalkAppScriptListener.
 *
 * Talk installs `OCA.Talk.registerMessageAction` with its own script, so the
 * registration waits for the page to have loaded.
 *
 * @spec openspec/changes/files-leaf-save-to-object/specs/file-actions/spec.md#requirement-a-talk-action-saves-a-conversation-to-a-register-object
 */

import { registerSaveChatAction } from './talk/saveChatAction.js'

if (document.readyState === 'loading') {
	window.addEventListener('DOMContentLoaded', () => registerSaveChatAction())
} else {
	registerSaveChatAction()
}
