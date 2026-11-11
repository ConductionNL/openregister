/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The setup wizard's close is recorded on the server through the library's
 * `setup.dismissAction` (nextcloud-vue 2.71.0). CnAppRoot posts the action the
 * manifest names, so the manifest and SetupController must agree on the id,
 * and the app must not also watch CnAppRoot's internal flag.
 *
 * @spec openspec/specs/first-time-setup/spec.md
 */

import * as fs from 'fs'
import * as path from 'path'

const ROOT = path.resolve(__dirname, '../..')
const read = (rel) => fs.readFileSync(path.join(ROOT, rel), 'utf8')

describe('setup dismiss action', () => {
	const manifest = JSON.parse(read('src/manifest.json'))

	it('names dismiss-setup as the setup dismiss action', () => {
		expect(manifest.setup.dismissAction).toBe('dismiss-setup')
	})

	it('is an action SetupController answers', () => {
		const controller = read('lib/Controller/SetupController.php')
		const id = manifest.setup.dismissAction
		expect(controller).toContain(`$actionId === '${id}'`)
	})

	it('leaves the close to the library, not an app watcher', () => {
		expect(
			fs.existsSync(path.join(ROOT, 'src/services/wizardDismissal.js')),
		).toBe(false)
		expect(read('src/App.vue')).not.toMatch(
			/setupWizardDismissed|rememberSetupDismissal/,
		)
	})
})
