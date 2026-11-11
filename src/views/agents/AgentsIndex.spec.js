/**
 * The agents screen (ai-agent-limits-screen, task 2).
 *
 * Exercises the options objects against a synthetic `this`, as the other
 * view specs here do (the installed test-utils is the Vue 3 build).
 *
 * @spec openspec/specs/agent-tool-governance/spec.md#requirement-an-agent-is-held-to-its-tool-grant-on-every-path
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

import axios from '@nextcloud/axios'
import fs from 'fs'
import path from 'path'
import EditAgentLimits, { toSelected } from '../../modals/agent/EditAgentLimits.vue'
import AgentsIndex from './AgentsIndex.vue'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), patch: jest.fn() },
}))
jest.mock('@nextcloud/router', () => ({
	__esModule: true,
	generateUrl: jest.fn((p, params) => p.replace('{id}', params?.id ?? '{id}')),
}))
jest.mock('@nextcloud/auth', () => ({
	__esModule: true,
	getCurrentUser: () => ({ uid: 'bob' }),
}))
jest.mock(
	'@nextcloud/vue',
	() =>
		new Proxy(
			{ __esModule: true },
			{
				get: (target, prop) =>
					prop in target
						? target[prop]
						: { name: String(prop), render: () => null },
			},
		),
)

const ROOT = path.resolve(__dirname, '..', '..', '..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)

describe('the agents screen', () => {
	beforeEach(() => jest.clearAllMocks())

	it('is a page in the manifest with a menu entry under Administration', () => {
		const page = manifest.pages.find((p) => p.id === 'agents')
		expect(page).toMatchObject({
			route: '/agents',
			type: 'custom',
			component: 'AgentsIndex',
		})
		const admin = manifest.menu.find((m) => m.id === 'AdministrationGroup')
		expect(admin.children.find((c) => c.id === 'Agents').route).toBe('agents')
	})

	it('lists the agents and the tool catalogue', async () => {
		axios.get.mockImplementation((url) =>
			Promise.resolve({
				data: url.endsWith('/tools')
					? { results: { 'openregister.objects': { name: 'Objects' } } }
					: {
							results: [
								{ id: 1, name: 'Helper', owner: 'bob', tools: [] },
							],
						},
			}),
		)
		const vm = { ...AgentsIndex.data(), ...AgentsIndex.methods }
		await AgentsIndex.methods.load.call(vm)
		expect(vm.agents.map((a) => a.name)).toEqual(['Helper'])
		expect(vm.toolOptions).toEqual([
			{ id: 'openregister.objects', label: 'Objects' },
		])
		expect(vm.error).toBe('')
	})

	it('lets only the owner edit, and never a structured grant', () => {
		const vm = { ...AgentsIndex.methods }
		expect(
			AgentsIndex.methods.canEdit.call(vm, { owner: 'bob', tools: ['a'] }),
		).toBe(true)
		expect(
			AgentsIndex.methods.canEdit.call(vm, { owner: 'eve', tools: ['a'] }),
		).toBe(false)
		expect(
			AgentsIndex.methods.canEdit.call(vm, {
				owner: 'bob',
				tools: {
					openregister: {
						objects: { read: 'openregister.objects.search' },
					},
				},
			}),
		).toBe(false)
	})

	it('saves the chosen tools on the agent', async () => {
		axios.patch.mockResolvedValue({ data: { id: 7, tools: ['x'] } })
		const vm = {
			agent: { id: 7, name: 'Helper', tools: ['old'] },
			toolOptions: [{ id: 'x', label: 'X' }],
			$emit: jest.fn(),
		}
		Object.assign(vm, EditAgentLimits.data.call(vm))
		vm.selectedTools = [{ id: 'x', label: 'X' }]
		await EditAgentLimits.methods.save.call(vm)
		expect(axios.patch).toHaveBeenCalledWith('/apps/openregister/api/agents/7', {
			tools: ['x'],
			views: [],
		})
		expect(vm.$emit).toHaveBeenCalledWith('saved', { id: 7, tools: ['x'] })
	})

	it('shows the API refusal when a save is refused', async () => {
		axios.patch.mockRejectedValue({
			response: {
				data: { error: 'You do not have permission to modify this agent' },
			},
		})
		const vm = { agent: { id: 7, tools: [] }, toolOptions: [], $emit: jest.fn() }
		Object.assign(vm, EditAgentLimits.data.call(vm))
		await EditAgentLimits.methods.save.call(vm)
		expect(vm.error).toContain('permission')
		expect(vm.$emit).not.toHaveBeenCalled()
	})

	it('loads the views the owner can grant (ai-agent-view-limits)', async () => {
		axios.get.mockImplementation((url) =>
			Promise.resolve({
				data: url.endsWith('/tools')
					? { results: {} }
					: url.endsWith('/views')
						? {
								results: [
									{ id: 3, uuid: 'v-open', name: 'Open cases' },
								],
							}
						: { results: [] },
			}),
		)
		const vm = { ...AgentsIndex.data(), ...AgentsIndex.methods }
		await AgentsIndex.methods.load.call(vm)
		expect(axios.get).toHaveBeenCalledWith('/apps/openregister/api/views')
		expect(vm.viewOptions).toEqual([{ id: 'v-open', label: 'Open cases' }])
	})

	it('saves the chosen views with the tools (ai-agent-view-limits)', async () => {
		axios.patch.mockResolvedValue({ data: { id: 7, tools: [], views: [] } })
		const vm = {
			agent: {
				id: 7,
				name: 'Helper',
				tools: [],
				views: ['v-open', 'v-closed'],
			},
			toolOptions: [],
			viewOptions: [
				{ id: 'v-open', label: 'Open cases' },
				{ id: 'v-closed', label: 'Closed cases' },
			],
			$emit: jest.fn(),
		}
		Object.assign(vm, EditAgentLimits.data.call(vm))
		expect(vm.selectedViews.map((o) => o.id)).toEqual(['v-open', 'v-closed'])
		vm.selectedViews = [{ id: 'v-open', label: 'Open cases' }]
		await EditAgentLimits.methods.save.call(vm)
		expect(axios.patch).toHaveBeenCalledWith('/apps/openregister/api/agents/7', {
			tools: [],
			views: ['v-open'],
		})
	})

	it("shows an agent's views by name in the list (ai-agent-view-limits)", () => {
		const vm = { ...AgentsIndex.methods }
		expect(
			AgentsIndex.methods.labels.call(
				vm,
				['v-open'],
				[{ id: 'v-open', label: 'Open cases' }],
			),
		).toBe('Open cases')
		expect(
			fs.readFileSync(path.join(__dirname, 'AgentsIndex.vue'), 'utf8'),
		).toContain('labels(agent.views, viewOptions)')
	})

	it('keeps a stored tool id the catalogue no longer lists', () => {
		expect(toSelected(['gone'], [])).toEqual([{ id: 'gone', label: 'gone' }])
	})
})
