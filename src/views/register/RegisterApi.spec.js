/**
 * RegisterApi: lists a register's schema operations from its OpenAPI
 * description, runs one as the signed-in user and shows the status, the
 * response and the curl sample for the same call.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 * @spec openspec/changes/api-explorer-in-the-app/specs/graphql-api/spec.md#requirement-req-apix-001-the-api-can-be-tried-from-inside-the-app
 */
import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import RegisterApi, { emptyPathValues, responseText } from './RegisterApi.vue'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), request: jest.fn() },
}))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) =>
		'/index.php'
		+ path.replace(/{(\w+)}/g, (match, key) =>
			key in params ? encodeURIComponent(params[key]) : match,
		),
}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) =>
		text.replace(/{(\w+)}/g, (match, key) => (key in vars ? vars[key] : match)),
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
		NcAppContent: stub('NcAppContent'),
		NcButton: stub('NcButton'),
		NcEmptyContent: stub('NcEmptyContent'),
		NcLoadingIcon: stub('NcLoadingIcon'),
		NcNoteCard: stub('NcNoteCard'),
		NcTextArea: stub('NcTextArea'),
		NcTextField: stub('NcTextField'),
	}
})

const OAS = {
	servers: [{ url: 'https://cloud.example.org/apps/openregister/api' }],
	paths: {
		'/objects/zaken/person': {
			get: { summary: 'Get all Person objects', tags: ['Person'] },
			post: { summary: 'Create a new Person object', tags: ['Person'] },
		},
		'/objects/zaken/person/{id}': {
			delete: { summary: 'Delete a Person object', tags: ['Person'] },
		},
	},
}

/**
 * The template's translator, with placeholders filled.
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

/**
 * Mount the page for register 4.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountIt() {
	const wrapper = mount(RegisterApi, {
		global: { mocks: { t: tMock, $route: { params: { id: '4' } } } },
	})
	await flushPromises()
	return wrapper
}

const LIST = 'get /objects/zaken/person'
const CREATE = 'post /objects/zaken/person'
const DELETE = 'delete /objects/zaken/person/{id}'

describe('RegisterApi', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.request.mockReset()
	})

	it("reads the register's own description and lists its operations by schema", async () => {
		axios.get.mockResolvedValue({ data: OAS })
		const wrapper = await mountIt()

		expect(axios.get).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/registers/4/oas',
		)
		expect(wrapper.vm.groups.map((group) => group.tag)).toEqual(['Person'])
		expect(wrapper.findAll('summary').map((summary) => summary.text())).toEqual([
			'GET/objects/zaken/personGet all Person objects',
			'POST/objects/zaken/personCreate a new Person object',
			'DELETE/objects/zaken/person/{id}Delete a Person object',
		])
		expect(wrapper.vm.pathValues[DELETE]).toEqual({ id: '' })
	})

	it('runs the list operation as the signed-in user and shows the response and its curl sample', async () => {
		axios.get.mockResolvedValue({ data: OAS })
		axios.request.mockResolvedValue({
			status: 200,
			data: { results: [{ name: 'Ada' }], total: 1 },
		})
		const wrapper = await mountIt()
		const list = wrapper.vm.groups[0].operations[0]

		await wrapper.vm.run(list)
		await flushPromises()

		expect(axios.request).toHaveBeenCalledWith({
			method: 'get',
			url: '/index.php/apps/openregister/api/objects/zaken/person',
		})
		expect(wrapper.vm.results[LIST]).toEqual({
			status: 200,
			body: JSON.stringify({ results: [{ name: 'Ada' }], total: 1 }, null, 2),
		})
		expect(wrapper.text()).toContain('Response: 200')
		expect(wrapper.text()).toContain('"name": "Ada"')
		expect(wrapper.vm.samples(list).curl).toContain(
			"'https://cloud.example.org/apps/openregister/api/objects/zaken/person'",
		)
	})

	it('sends the typed body of a create call, and refuses one that is not JSON without calling', async () => {
		axios.get.mockResolvedValue({ data: OAS })
		axios.request.mockResolvedValue({ status: 201, data: { id: 'x' } })
		const wrapper = await mountIt()
		const create = wrapper.vm.groups[0].operations[1]

		wrapper.vm.bodies[CREATE] = '{name:'
		await wrapper.vm.run(create)
		expect(axios.request).not.toHaveBeenCalled()
		expect(wrapper.vm.bodyErrors[CREATE]).toBe(true)

		wrapper.vm.bodies[CREATE] = '{"name": "Ada"}'
		await wrapper.vm.run(create)

		expect(axios.request).toHaveBeenCalledWith({
			method: 'post',
			url: '/index.php/apps/openregister/api/objects/zaken/person',
			data: { name: 'Ada' },
		})
		expect(wrapper.vm.bodyErrors[CREATE]).toBe(false)
		expect(wrapper.vm.samples(create).curl).toContain('-X POST')
	})

	it('runs a call with a path parameter only once it has a value, and fills it in', async () => {
		axios.get.mockResolvedValue({ data: OAS })
		axios.request.mockResolvedValue({ status: 204, data: '' })
		const wrapper = await mountIt()
		const remove = wrapper.vm.groups[0].operations[2]

		expect(wrapper.vm.canRun(remove)).toBe(false)
		await wrapper.vm.run(remove)
		expect(axios.request).not.toHaveBeenCalled()

		wrapper.vm.pathValues[DELETE].id = 'a b'
		await wrapper.vm.run(remove)

		expect(axios.request).toHaveBeenCalledWith({
			method: 'delete',
			url: '/index.php/apps/openregister/api/objects/zaken/person/a%20b',
		})
		expect(wrapper.vm.results[DELETE]).toEqual({ status: 204, body: '' })
	})

	it('shows an error answer as a result, with its status and body', async () => {
		axios.get.mockResolvedValue({ data: OAS })
		axios.request.mockRejectedValue({
			response: { status: 403, data: { message: 'Forbidden' } },
		})
		const wrapper = await mountIt()

		await wrapper.vm.run(wrapper.vm.groups[0].operations[0])

		expect(wrapper.vm.results[LIST]).toEqual({
			status: 403,
			body: JSON.stringify({ message: 'Forbidden' }, null, 2),
		})
		expect(wrapper.vm.running[LIST]).toBe(false)
	})

	it('says so when the description cannot be read', async () => {
		axios.get.mockRejectedValue(new Error('404'))
		const wrapper = await mountIt()

		expect(wrapper.vm.loadError).toBe(true)
		expect(wrapper.vm.groups).toEqual([])
		expect(wrapper.text()).toContain('The API description could not be loaded.')
	})

	it('links the bundled GraphQL explorer on this instance', async () => {
		axios.get.mockResolvedValue({ data: { paths: {} } })
		const wrapper = await mountIt()

		expect(wrapper.find('a.register-api__explorer').attributes('href')).toBe(
			'/index.php/apps/openregister/api/graphql/explorer',
		)
		expect(wrapper.find('.stub-NcEmptyContent').attributes('name')).toBe(
			'This register has no schema operations yet.',
		)
	})
})

describe('RegisterApi helpers', () => {
	it('shows JSON indented and text as it came', () => {
		expect(responseText({ a: 1 })).toBe('{\n  "a": 1\n}')
		expect(responseText('plain')).toBe('plain')
		expect(responseText(undefined)).toBe('null')
	})

	it('starts every path parameter empty', () => {
		expect(
			emptyPathValues([
				{ operations: [{ key: 'k', pathParams: ['id', 'version'] }] },
			]),
		).toEqual({ k: { id: '', version: '' } })
	})
})
