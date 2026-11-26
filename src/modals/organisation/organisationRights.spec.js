/**
 * The special rights the organisation editor offers, and how a stored
 * `grant_manage` list with narrowed entries is split, shown and kept.
 *
 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import SpecialRightsTable from './SpecialRightsTable.vue'
import { isNarrowedEntry, mergeEntries, narrowedEntryLabel, SPECIAL_RIGHTS, splitEntries } from './organisationRights.js'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => text.replace(/{(\w+)}/g, (m, k) => (k in vars ? String(vars[k]) : m)),
}))
jest.mock('@nextcloud/vue', () => ({ NcSelect: {} }))

const NARROWED = { group: 'records-service', register: 'records', schemas: ['private-note', 'memo'] }

describe('organisationRights', () => {
	it('offers grant_manage beside agent_use and llm_use, without everyone', () => {
		expect(SPECIAL_RIGHTS.map((row) => row.right)).toEqual(['agent_use', 'llm_use', 'grant_manage'])
		const grantManage = SPECIAL_RIGHTS.find((row) => row.right === 'grant_manage')
		expect(grantManage.allowsEveryone).toBe(false)
		expect(grantManage.description()).toBe('Manage the grants on records the group does not own')
		expect(SPECIAL_RIGHTS.filter((row) => row.allowsEveryone === false)).toHaveLength(1)
	})

	it('splits a stored list into the plain group ids and the narrowed entries', () => {
		expect(splitEntries(['staff', NARROWED, '*', 7, null])).toEqual({ plain: ['staff', '*'], narrowed: [NARROWED] })
		expect(splitEntries(undefined)).toEqual({ plain: [], narrowed: [] })
		expect(isNarrowedEntry(NARROWED)).toBe(true)
		expect(isNarrowedEntry(['a'])).toBe(false)
		expect(isNarrowedEntry('staff')).toBe(false)
	})

	it('keeps the narrowed entries in front of the picked groups', () => {
		expect(mergeEntries([NARROWED], ['admin'])).toEqual([NARROWED, 'admin'])
		expect(mergeEntries(undefined, ['admin'])).toEqual(['admin'])
	})

	it('names what a narrowed entry is limited to', () => {
		expect(narrowedEntryLabel(NARROWED)).toBe('records-service, only for private-note, memo in register records')
		expect(narrowedEntryLabel({ group: 'g', register: 'records' })).toBe('g, only in register records')
		expect(narrowedEntryLabel({ group: 'g', schemas: ['memo'] })).toBe('g, only for memo')
		expect(narrowedEntryLabel({ group: 'g', schemas: [] })).toBe('g')
	})
})

describe('SpecialRightsTable', () => {
	const groups = [{ id: 'admin', name: 'Admins' }]
	const table = (narrowed = {}) => {
		const vm = { groups, narrowed }
		Object.defineProperty(vm, 'options', { get: SpecialRightsTable.computed.options.bind(vm) })
		for (const [name, method] of Object.entries(SpecialRightsTable.methods)) {
			vm[name] = method.bind(vm)
		}
		return vm
	}

	it('offers everyone only under a right the server gives to everyone', () => {
		const vm = table()
		const [agentUse, , grantManage] = SPECIAL_RIGHTS

		expect(vm.optionsFor(agentUse).map((option) => option.id)).toEqual(['*', 'admin'])
		expect(vm.optionsFor(grantManage).map((option) => option.id)).toEqual(['admin'])
	})

	it('lists the narrowed entries of a right and none of another', () => {
		const vm = table({ grant_manage: [NARROWED] })

		expect(vm.narrowedOf({ right: 'grant_manage' })).toEqual([NARROWED])
		expect(vm.narrowedOf({ right: 'llm_use' })).toEqual([])
	})
})
