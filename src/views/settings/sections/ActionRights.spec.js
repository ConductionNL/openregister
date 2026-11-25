import axios from '@nextcloud/axios'
/**
 * ActionRights: lists the action rights by area with the groups that hold
 * each, and stores the groups an administrator grants.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-the-action-rights-are-administered-and-published
 */
import { flushPromises, mount } from '@vue/test-utils'
import ActionRights, {
	areaOf,
	chosenOptions,
	EVERYONE,
	groupByArea,
} from './ActionRights.vue'
import { loadNextcloudGroupsInto } from '../../../services/groupSearch.js'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), put: jest.fn() },
}))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) =>
		text.replace(/{(\w+)}/g, (match, key) => (key in vars ? vars[key] : match)),
}))
jest.mock('../../../services/groupSearch.js', () => ({
	loadNextcloudGroupsInto: jest.fn(),
}))
jest.mock('@nextcloud/vue', () => {
	const { h } = require('vue')
	const stub = (name) => ({
		name,
		inheritAttrs: false,
		render() {
			const slots = Object.values(this.$slots).map((s) => s({}))
			return h('div', { ...this.$attrs, class: `stub-${name}` }, slots)
		},
	})
	return {
		__esModule: true,
		NcButton: stub('NcButton'),
		NcNoteCard: stub('NcNoteCard'),
		NcSelect: stub('NcSelect'),
	}
})

const URL = '/apps/openregister/api/settings/action-rights'

/**
 * The template's translator: the English text with its placeholders filled.
 *
 * @param {string} app The app id.
 * @param {string} text The English text.
 * @param {object} [vars] The placeholder values.
 * @return {string} The text.
 */
function tMock(app, text, vars = {}) {
	return text.replace(/{(\w+)}/g, (match, key) =>
		key in vars ? vars[key] : match,
	)
}

const actions = [
	{
		action: 'flow.create',
		app: 'openregister',
		description: 'Create a flow, or import one from BPMN.',
		nodeTypes: [],
		groups: [EVERYONE],
	},
	{
		action: 'flow.node.send-email',
		app: 'openregister',
		description: 'Put an e-mail step in a flow.',
		nodeTypes: ['openregister.send-email'],
		groups: ['admin'],
	},
	{
		action: 'object.correct',
		app: 'openregister',
		description: 'Correct a recorded value of an object.',
		nodeTypes: [],
		groups: ['gone'],
	},
]

/**
 * Mount the section with the given groups and rights.
 *
 * @param {object} [answer] What `GET` answers.
 * @return {Promise<object>} The wrapper.
 */
async function mountIt(answer = { actions, nodeRights: {} }) {
	loadNextcloudGroupsInto.mockImplementation(async (vm) => {
		vm.nextcloudGroups = [
			{ id: 'admin', name: 'admin', userCount: 0 },
			{ id: 'mail-senders', name: 'mail-senders', userCount: 0 },
		]
	})
	axios.get.mockResolvedValue({ data: answer })
	const wrapper = mount(ActionRights, { global: { mocks: { t: tMock } } })
	await flushPromises()
	return wrapper
}

describe('ActionRights', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.put.mockReset()
		loadNextcloudGroupsInto.mockReset()
	})

	it('shows each right with the groups that hold it, open grants and vanished groups included', async () => {
		const wrapper = await mountIt()

		expect(axios.get).toHaveBeenCalledWith(URL)
		expect(wrapper.vm.selected).toEqual({
			'flow.create': [{ id: EVERYONE, label: 'Every signed-in user' }],
			'flow.node.send-email': [{ id: 'admin', label: 'admin' }],
			'object.correct': [{ id: 'gone', label: 'gone' }],
		})
		expect(wrapper.vm.loading).toBe(false)
		expect(wrapper.text()).toContain('Needed by: openregister.send-email')
	})

	it('grants a right to a group and stores the groups of every right', async () => {
		const granted = actions.map((action) =>
			action.action === 'flow.node.send-email'
				? { ...action, groups: ['admin', 'mail-senders'] }
				: action,
		)
		axios.put.mockResolvedValue({ data: { actions: granted, nodeRights: {} } })
		const wrapper = await mountIt()

		wrapper.vm.selected['flow.node.send-email'] = [
			...wrapper.vm.selected['flow.node.send-email'],
			{ id: 'mail-senders', label: 'mail-senders' },
		]
		await wrapper.vm.save()

		expect(axios.put).toHaveBeenCalledWith(URL, {
			groups: {
				'flow.create': [EVERYONE],
				'flow.node.send-email': ['admin', 'mail-senders'],
				'object.correct': ['gone'],
			},
		})
		expect(Object.keys(axios.put.mock.calls[0][1])).toEqual(['groups'])
		expect(wrapper.vm.selected['flow.node.send-email']).toEqual([
			{ id: 'admin', label: 'admin' },
			{ id: 'mail-senders', label: 'mail-senders' },
		])
		expect(wrapper.vm.saved).toBe(true)
		expect(wrapper.vm.error).toBeNull()
	})

	it('stores an empty list for a right with no group chosen: administrators alone', async () => {
		axios.put.mockResolvedValue({ data: { actions, nodeRights: {} } })
		const wrapper = await mountIt()

		wrapper.vm.selected['flow.create'] = []
		await wrapper.vm.save()

		expect(axios.put.mock.calls[0][1].groups['flow.create']).toEqual([])
	})

	it("shows the server's refusal when a save is refused", async () => {
		axios.put.mockRejectedValue({
			response: { data: { error: 'Unknown action right: flow.bogus.' } },
		})
		const wrapper = await mountIt()

		await wrapper.vm.save()

		expect(wrapper.vm.error).toBe('Unknown action right: flow.bogus.')
		expect(wrapper.vm.saved).toBe(false)
		expect(wrapper.vm.saving).toBe(false)
	})

	it('says so when a save fails without an answer', async () => {
		axios.put.mockRejectedValue(new Error('network'))
		const wrapper = await mountIt()

		await wrapper.vm.save()

		expect(wrapper.vm.error).toBe('The action rights could not be saved.')
	})

	it('says so when the rights cannot be loaded, and offers nothing to save', async () => {
		loadNextcloudGroupsInto.mockResolvedValue(undefined)
		axios.get.mockRejectedValue(new Error('403'))
		const wrapper = mount(ActionRights, { global: { mocks: { t: tMock } } })
		await flushPromises()

		expect(wrapper.vm.error).toBe('The action rights could not be loaded.')
		expect(wrapper.vm.actions).toEqual([])
		expect(wrapper.vm.loading).toBe(false)
	})
})

describe('ActionRights helpers', () => {
	it('puts a right under its area', () => {
		expect(areaOf('flow.node.send-email').id).toBe('flow.node')
		expect(areaOf('flow.create').id).toBe('flow')
		expect(areaOf('object.correct').id).toBe('object')
		expect(areaOf('mail.send')).toEqual({ id: 'other', label: 'Other' })
	})

	it('groups the rights in the order flows, flow steps, objects, other', () => {
		const areas = groupByArea([
			{ action: 'object.correct' },
			{
				action: 'flow.node.send-email',
				nodeTypes: ['openregister.send-email'],
			},
			{ action: 'mail.send' },
			{ action: 'flow.run' },
			{ action: 'flow.create' },
		])

		expect(areas.map((area) => area.id)).toEqual([
			'flow',
			'flow.node',
			'object',
			'other',
		])
		expect(areas[0].rights.map((right) => right.action)).toEqual([
			'flow.run',
			'flow.create',
		])
		expect(areas[2].rights[0].nodeTypes).toEqual([])
	})

	it('keeps a group it does not know under its id', () => {
		const options = [{ id: 'admin', label: 'Administrators' }]

		expect(chosenOptions(['admin', 'gone'], options)).toEqual([
			{ id: 'admin', label: 'Administrators' },
			{ id: 'gone', label: 'gone' },
		])
		expect(chosenOptions(undefined, options)).toEqual([])
	})
})
