/**
 * Golden record of the quota fields of the application form: the MB shown and
 * what each field stores, also on an item without a quota yet.
 *
 * @spec exclude Golden record of client-side quota form bindings.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import EditApplication from './EditApplication.vue'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/Cancel.vue', () => ({}))
jest.mock('vue-material-design-icons/Cog.vue', () => ({}))
jest.mock('vue-material-design-icons/ContentSaveOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Database.vue', () => ({}))
jest.mock('vue-material-design-icons/Plus.vue', () => ({}))
jest.mock('vue-material-design-icons/Shield.vue', () => ({}))
jest.mock('../../components/RbacTable.vue', () => ({}))
jest.mock('../../components/tabs/AppTab.vue', () => ({}))
jest.mock('../../components/tabs/AppTabs.vue', () => ({}))
jest.mock('../../services/groupSearch.js', () => ({}))
jest.mock('../../store/store.js', () => ({
	applicationStore: {},
	navigationStore: {},
}))

const VALUES = [undefined, '', 0, '0', '5', 7, '2.9', 'x']
const SETTERS = [
	'updateStorageQuota',
	'updateBandwidthQuota',
	'updateRequestQuota',
	'updateUserQuota',
	'updateGroupQuota',
]

/**
 * A bare form context with the quota bindings of the component.
 *
 * @param {object} item The form item
 * @return {object} The context
 */
function context(item) {
	const parts = [...(EditApplication.mixins ?? []), EditApplication]
	const ctx = { applicationItem: item }
	for (const part of parts) {
		for (const [name, method] of Object.entries(part.methods ?? {})) {
			ctx[name] = method.bind(ctx)
		}
		for (const [name, getter] of Object.entries(part.computed ?? {})) {
			Object.defineProperty(ctx, name, {
				get: () => getter.call(ctx),
				configurable: true,
			})
		}
	}
	return ctx
}

describe('application quota fields golden', () => {
	it.each(SETTERS)('%s stores what the field says', (setter) => {
		const stored = VALUES.map((value) => {
			const ctx = context({})
			ctx[setter](value)
			return [value, ctx.applicationItem.quota]
		})
		expect(stored).toMatchSnapshot()
	})

	it('keeps the other quotas when one changes', () => {
		const ctx = context({ quota: { storage: 1, groups: 2 } })
		ctx.updateUserQuota('3')
		expect(ctx.applicationItem.quota).toEqual({
			storage: 1,
			groups: 2,
			users: 3,
		})
	})

	it.each([
		[undefined, 0, 0],
		[{}, 0, 0],
		[{ storage: 1572864, bandwidth: 5242880 }, 2, 5],
		[{ storage: 0, bandwidth: 1 }, 0, 0],
	])('shows %j as MB', (quota, storage, bandwidth) => {
		const ctx = context({ quota })
		expect([ctx.storageQuotaMB, ctx.bandwidthQuotaMB]).toEqual([
			storage,
			bandwidth,
		])
	})
})
