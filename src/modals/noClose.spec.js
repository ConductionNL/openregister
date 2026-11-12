/**
 * NcDialog reads noClose. A dialog that must be answered through its buttons
 * says so with that prop, because a close button would leave the navigation
 * store pointing at a dialog nothing shows any more.
 *
 * @spec exclude Source check on dialog markup.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { readdirSync, readFileSync } from 'fs'
import { join } from 'path'

/**
 * Every Vue file below a directory.
 *
 * @param {string} dir The directory
 * @return {string[]} The paths
 */
function vueFiles(dir) {
	return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
		const path = join(dir, entry.name)
		if (entry.isDirectory()) return vueFiles(path)
		return entry.name.endsWith('.vue') ? [path] : []
	})
}

describe('dialogs without a close button', () => {
	it('use the prop NcDialog reads', () => {
		const stale = vueFiles(join(__dirname, '..')).filter((path) =>
			readFileSync(path, 'utf8').includes('canClose'),
		)
		expect(stale).toEqual([])
	})

	it('include every confirm dialog', () => {
		const frame = readFileSync(join(__dirname, 'shared/ConfirmDialogFrame.vue'), 'utf8')
		expect(frame).toMatch(/<NcDialog[^>]*\bnoClose\b/)
	})
})
