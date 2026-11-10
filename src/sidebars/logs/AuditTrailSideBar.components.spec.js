/**
 * Every component the audit trail sidebar's template uses is registered, so
 * none of them silently renders as an unknown element (the delete action's
 * icon once did).
 *
 * @spec exclude Client-side component registration.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { readFileSync } from 'fs'
import { join } from 'path'
import AuditTrailSideBar from './AuditTrailSideBar.vue'

jest.mock(
	'@nextcloud/vue',
	() =>
		new Proxy(
			{ __esModule: true },
			{
				get: (target, key) =>
					key in target ? target[key] : { name: String(key) },
			},
		),
)
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, one) => one,
}))
jest.mock('../../store/store.js', () => ({}))
jest.mock('vue-material-design-icons/ChartLine.vue', () => ({ name: 'ChartLine' }))
jest.mock('vue-material-design-icons/CogOutline.vue', () => ({ name: 'CogOutline' }))
jest.mock('vue-material-design-icons/Delete.vue', () => ({ name: 'Delete' }))
jest.mock('vue-material-design-icons/Eye.vue', () => ({ name: 'Eye' }))
jest.mock('vue-material-design-icons/FilterOffOutline.vue', () => ({
	name: 'FilterOffOutline',
}))
jest.mock('vue-material-design-icons/FilterOutline.vue', () => ({
	name: 'FilterOutline',
}))
jest.mock('vue-material-design-icons/Pencil.vue', () => ({ name: 'Pencil' }))
jest.mock('vue-material-design-icons/Plus.vue', () => ({ name: 'Plus' }))

/**
 * The PascalCase component tags in a single-file component's template.
 *
 * @param {string} source The .vue source
 * @return {string[]} The tag names
 */
function templateComponents(source) {
	const template = source.slice(
		source.indexOf('<template>'),
		source.lastIndexOf('</template>'),
	)
	return [
		...new Set([...template.matchAll(/<([A-Z][A-Za-z0-9]*)/g)].map((m) => m[1])),
	]
}

describe('AuditTrailSideBar components', () => {
	it('registers every component its template uses', () => {
		const source = readFileSync(join(__dirname, 'AuditTrailSideBar.vue'), 'utf8')
		const registered = [
			...(AuditTrailSideBar.mixins || []).flatMap((mixin) =>
				Object.keys(mixin.components || {})),
			...Object.keys(AuditTrailSideBar.components),
		]
		const missing = templateComponents(source).filter(
			(name) => !registered.includes(name),
		)
		expect(missing).toEqual([])
	})
})
