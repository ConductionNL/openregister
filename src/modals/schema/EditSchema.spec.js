import { flushPromises, mount } from '@vue/test-utils'
import EditSchema from './EditSchema.vue'
import { navigationStore, registerStore, schemaStore } from '../../store/store.js'

jest.mock('@nextcloud/l10n', () => ({
	__esModule: true,
	translate: (app, s) => s,
	translatePlural: (app, s, p, c) => (c === 1 ? s : p),
}))
jest.mock('../../store/store.js', () => {
	const { reactive } = require('vue')
	return {
		__esModule: true,
		navigationStore: { setModal: jest.fn(), setDialog: jest.fn() },
		registerStore: reactive({ registerList: [], refreshRegisterList: jest.fn() }),
		schemaStore: reactive({
			schemaItem: null,
			editorSchemaItem: null,
			schemaList: [],
			draftMode: false,
			refreshSchemaList: jest.fn(),
			saveSchema: jest.fn(),
			saveSchemaDraft: jest.fn(),
			setDraftMode: jest.fn(),
			setSchemaItem: jest.fn(),
		}),
	}
})
// The shared form dialog is the network-free edge: a stand-in that keeps the
// setResult() method this modal calls through its ref.
jest.mock('@conduction/nextcloud-vue', () => {
	class SchemaBreakingChangeError extends Error {
		constructor(changes) {
			super('breaking')
			this.changes = changes
		}
	}
	return {
		__esModule: true,
		SchemaBreakingChangeError,
		describeSchemaChange: jest.fn((change) => `described:${change.path}`),
		CnSchemaFormDialog: {
			name: 'CnSchemaFormDialog',
			inheritAttrs: false,
			methods: { setResult: jest.fn() },
			template: '<div class="form-dialog-stub" />',
		},
	}
})
// vue3-jest merges this SFC's two <script> blocks so that both `vue` and
// `@nextcloud/vue` land on one `_vue` binding, and the template then reads
// NcDialog / NcButton off the `vue` module. The stand-ins are therefore
// exposed on both modules; production webpack builds are unaffected.
jest.mock('@nextcloud/vue', () => ({ __esModule: true, ...mockNcStubs() }))
jest.mock('vue', () => ({ ...jest.requireActual('vue'), ...mockNcStubs() }))

/**
 * Stand-ins for NcDialog and NcButton (a hoisted declaration, so the hoisted
 * module factories above can call it).
 *
 * @return {object}
 */
function mockNcStubs() {
	return {
		NcDialog: {
			name: 'NcDialog',
			props: ['name'],
			emits: ['closing'],
			template: '<section class="nc-dialog-stub"><h2>{{ name }}</h2><slot /><slot name="actions" /></section>',
		},
		NcButton: {
			name: 'NcButton',
			props: ['disabled'],
			emits: ['click'],
			template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
		},
	}
}

const { SchemaBreakingChangeError, CnSchemaFormDialog } = jest.requireMock('@conduction/nextcloud-vue')
const setResult = CnSchemaFormDialog.methods.setResult

/**
 * Answer the groups and tags requests made on mount.
 *
 * @param {object} opts { groups, tags }
 */
function answerFetch({ groups = { ok: true, body: { ocs: { data: { groups: ['admin', 'staff'] } } } }, tags = { ok: true, body: ['a', 'b'] } } = {}) {
	global.fetch = jest.fn((url) => {
		const answer = url.includes('cloud/groups') ? groups : tags
		if (answer instanceof Error) return Promise.reject(answer)
		return Promise.resolve({ ok: answer.ok, json: () => Promise.resolve(answer.body) })
	})
}

/**
 * Mount the modal and let its mount-time loads settle.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountModal() {
	const wrapper = mount(EditSchema)
	await flushPromises()
	return wrapper
}

/**
 * The props the form dialog received.
 *
 * @param {object} wrapper The wrapper.
 * @return {object}
 */
const formProps = (wrapper) => wrapper.findComponent({ name: 'CnSchemaFormDialog' }).vm.$attrs

describe('EditSchema', () => {
	let errorSpy

	beforeEach(() => {
		jest.clearAllMocks()
		errorSpy = jest.spyOn(console, 'error').mockImplementation(() => {})
		Object.assign(registerStore, { registerList: [{ id: 1 }] })
		Object.assign(schemaStore, { schemaItem: null, editorSchemaItem: null, schemaList: [{ id: 2 }], draftMode: false })
		answerFetch()
	})

	afterEach(() => {
		errorSpy.mockRestore()
		delete global.fetch
	})

	describe('mount-time loading', () => {
		it('loads registers and schemas only when their lists are empty', async () => {
			await mountModal()
			expect(registerStore.refreshRegisterList).not.toHaveBeenCalled()
			expect(schemaStore.refreshSchemaList).not.toHaveBeenCalled()

			registerStore.registerList = []
			schemaStore.schemaList = []
			await mountModal()
			expect(registerStore.refreshRegisterList).toHaveBeenCalledTimes(1)
			expect(schemaStore.refreshSchemaList).toHaveBeenCalledTimes(1)
		})

		it('logs a failing register load', async () => {
			registerStore.registerList = []
			const boom = new Error('down')
			registerStore.refreshRegisterList.mockRejectedValueOnce(boom)
			await mountModal()
			expect(errorSpy).toHaveBeenCalledWith('Error loading registers and schemas:', boom)
		})

		it('maps the OCS groups into options and passes them with the tags', async () => {
			const wrapper = await mountModal()
			expect(global.fetch).toHaveBeenCalledWith('/ocs/v1.php/cloud/groups?format=json', { headers: { 'OCS-APIRequest': 'true' } })
			expect(global.fetch).toHaveBeenCalledWith('/index.php/apps/openregister/api/tags')
			expect(formProps(wrapper).userGroups).toEqual([
				{ id: 'admin', displayname: 'admin' },
				{ id: 'staff', displayname: 'staff' },
			])
			expect(formProps(wrapper).loadingGroups).toBe(false)
			expect(formProps(wrapper).availableTags).toEqual(['a', 'b'])
		})

		it.each([
			['an answer without groups', { ok: true, body: { ocs: {} } }],
			['an HTTP error', { ok: false, body: {} }],
			['a network failure', new Error('offline')],
		])('falls back to the default groups on %s', async (_, groups) => {
			answerFetch({ groups })
			const wrapper = await mountModal()
			expect(formProps(wrapper).userGroups.map((g) => g.id)).toEqual(['users', 'editors', 'managers', 'viewers'])
			expect(formProps(wrapper).loadingGroups).toBe(false)
		})

		it.each([
			['a non-array body', { ok: true, body: { tags: [] } }],
			['an HTTP error', { ok: false, body: ['x'] }],
			['a network failure', new Error('offline')],
		])('offers no tags on %s', async (_, tags) => {
			answerFetch({ tags })
			const wrapper = await mountModal()
			expect(formProps(wrapper).availableTags).toEqual([])
		})
	})

	describe('what it hands the form dialog', () => {
		it('labels a new schema as Add / Create / created', async () => {
			const wrapper = await mountModal()
			expect(formProps(wrapper).dialogTitle).toBe('Add Schema')
			expect(formProps(wrapper).confirmLabel).toBe('Create')
			expect(formProps(wrapper).successText).toBe('Schema successfully created')
			expect(formProps(wrapper).objectCount).toBe(0)
		})

		it('labels an existing schema as Edit / Save / updated with its object count', async () => {
			schemaStore.schemaItem = { id: 2, stats: { objects: { total: 12 } } }
			const wrapper = await mountModal()
			expect(formProps(wrapper).dialogTitle).toBe('Edit Schema')
			expect(formProps(wrapper).confirmLabel).toBe('Save')
			expect(formProps(wrapper).successText).toBe('Schema successfully updated')
			expect(formProps(wrapper).objectCount).toBe(12)
		})

		it('labels draft mode as Edit draft / Save draft', async () => {
			schemaStore.schemaItem = { id: 2 }
			schemaStore.draftMode = true
			const wrapper = await mountModal()
			expect(formProps(wrapper).dialogTitle).toBe('Edit draft')
			expect(formProps(wrapper).confirmLabel).toBe('Save draft')
			expect(formProps(wrapper).successText).toBe('Draft saved. Records are still checked against the published schema.')
		})

		it('offers every other schema, never the one being edited, with derived labels and references', async () => {
			schemaStore.schemaItem = { id: 1, uuid: 'u1', slug: 's1' }
			schemaStore.schemaList = [
				{ id: 1, uuid: 'u1', slug: 's1', title: 'Self' },
				{ id: 2, uuid: 'u2', slug: 'person', title: 'Person', description: 'A person', properties: { name: {} } },
				{ id: 3, uuid: 'u3', name: 'Named', summary: 'Sum' },
				{ id: 4, uuid: 'u4' },
				{ id: 5, uuid: 'u1' },
				{ id: 1, uuid: 'other', slug: 'other' },
				{ id: 6, uuid: 'u6', slug: 's1' },
			]
			const wrapper = await mountModal()
			expect(formProps(wrapper).availableSchemas).toEqual([
				{ id: 2, title: 'Person', label: 'Person', description: 'A person', slug: 'person', properties: { name: {} }, reference: '#/components/schemas/person' },
				{ id: 3, title: 'Named', label: 'Named', description: 'Sum', slug: undefined, properties: {}, reference: '#/components/schemas/3' },
				{ id: 4, title: 'Schema 4', label: 'Schema 4', description: '', slug: undefined, properties: {}, reference: '#/components/schemas/4' },
			])
		})

		it('labels registers by title, else name, else id', async () => {
			registerStore.registerList = [{ id: 1, title: 'T' }, { id: 2, name: 'N' }, { id: 3 }]
			const wrapper = await mountModal()
			expect(formProps(wrapper).availableRegisters).toEqual([
				{ id: 1, label: 'T' },
				{ id: 2, label: 'N' },
				{ id: 3, label: 3 },
			])
		})

		it('merges the properties of every allOf parent, by id, uuid or slug', async () => {
			schemaStore.schemaList = [
				{ id: 10, properties: { a: { type: 'string' } } },
				{ id: 11, uuid: 'u11', properties: { b: { type: 'number' } } },
				{ id: 12, slug: 'base', properties: { a: { type: 'integer' } } },
				{ id: 13 },
			]
			schemaStore.schemaItem = { id: 99, allOf: [10, { id: 'u11' }, 'base', 13, 'missing'] }
			const wrapper = await mountModal()
			expect(formProps(wrapper).inheritedProperties).toEqual({ a: { type: 'integer' }, b: { type: 'number' } })
		})

		it('inherits nothing without allOf', async () => {
			schemaStore.schemaItem = { id: 99 }
			const wrapper = await mountModal()
			expect(formProps(wrapper).inheritedProperties).toEqual({})
		})
	})

	describe('saving', () => {
		const form = (wrapper) => wrapper.findComponent({ name: 'CnSchemaFormDialog' })

		it('saves without acknowledging and reports success', async () => {
			schemaStore.saveSchema.mockResolvedValue({ response: { ok: true } })
			const wrapper = await mountModal()
			form(wrapper).vm.$emit('confirm', { title: 'S' })
			await flushPromises()
			expect(schemaStore.saveSchema).toHaveBeenCalledWith({ title: 'S' }, { acknowledgeBreaking: false })
			expect(setResult).toHaveBeenCalledWith({ success: true, error: undefined })
		})

		it('reports a refused response as a failure', async () => {
			schemaStore.saveSchema.mockResolvedValue({ response: { ok: false } })
			const wrapper = await mountModal()
			form(wrapper).vm.$emit('confirm', {})
			await flushPromises()
			expect(setResult).toHaveBeenCalledWith({ success: false, error: 'Failed to save schema' })
		})

		it.each([
			[new Error('Title taken'), 'Title taken'],
			[{}, 'An error occurred while saving the schema'],
		])('reports a thrown save error', async (error, message) => {
			schemaStore.saveSchema.mockRejectedValue(error)
			const wrapper = await mountModal()
			form(wrapper).vm.$emit('confirm', {})
			await flushPromises()
			expect(setResult).toHaveBeenCalledWith({ error: message })
			expect(wrapper.find('.nc-dialog-stub').exists()).toBe(false)
		})

		it('asks before a breaking change, listing each change, then saves acknowledged on Save anyway', async () => {
			schemaStore.saveSchema
				.mockRejectedValueOnce(new SchemaBreakingChangeError([{ path: 'name' }, { path: 'age' }]))
				.mockResolvedValueOnce({ response: { ok: true } })
			const wrapper = await mountModal()
			form(wrapper).vm.$emit('confirm', { title: 'S' })
			await flushPromises()

			expect(setResult).not.toHaveBeenCalled()
			const dialog = wrapper.find('.nc-dialog-stub')
			expect(dialog.find('h2').text()).toBe('Breaking change')
			expect(dialog.findAll('.breaking-changes li').map((li) => li.text())).toEqual(['described:name', 'described:age'])

			const saveAnyway = dialog.findAll('button').find((b) => b.text() === 'Save anyway')
			await saveAnyway.trigger('click')
			await flushPromises()

			expect(schemaStore.saveSchema).toHaveBeenLastCalledWith({ title: 'S' }, { acknowledgeBreaking: true })
			expect(setResult).toHaveBeenCalledWith({ success: true, error: undefined })
			expect(wrapper.find('.nc-dialog-stub').exists()).toBe(false)
		})

		it('Back to editing dismisses the question without saving', async () => {
			schemaStore.saveSchema.mockRejectedValueOnce(new SchemaBreakingChangeError([{ path: 'x' }]))
			const wrapper = await mountModal()
			form(wrapper).vm.$emit('confirm', {})
			await flushPromises()
			const back = wrapper.findAll('.nc-dialog-stub button').find((b) => b.text() === 'Back to editing')
			await back.trigger('click')
			expect(wrapper.find('.nc-dialog-stub').exists()).toBe(false)
			expect(schemaStore.saveSchema).toHaveBeenCalledTimes(1)
		})

		it('confirmBreaking does nothing without a pending question', async () => {
			const wrapper = await mountModal()
			await wrapper.vm.confirmBreaking()
			expect(schemaStore.saveSchema).not.toHaveBeenCalled()
		})

		it('saves a draft with the current schema id instead of saving the schema', async () => {
			schemaStore.draftMode = true
			schemaStore.schemaItem = { id: 7 }
			schemaStore.saveSchemaDraft.mockResolvedValue({})
			const wrapper = await mountModal()
			form(wrapper).vm.$emit('confirm', { title: 'D' })
			await flushPromises()
			expect(schemaStore.saveSchemaDraft).toHaveBeenCalledWith({ title: 'D', id: 7 })
			expect(schemaStore.saveSchema).not.toHaveBeenCalled()
			expect(setResult).toHaveBeenCalledWith({ success: true })
		})

		it.each([
			[{ response: { data: { error: 'Draft locked' } }, message: 'm' }, 'Draft locked'],
			[{ message: 'm' }, 'm'],
			[{}, 'An error occurred while saving the draft'],
		])('reports a failed draft save', async (error, message) => {
			schemaStore.draftMode = true
			schemaStore.saveSchemaDraft.mockRejectedValue(error)
			const wrapper = await mountModal()
			form(wrapper).vm.$emit('confirm', {})
			await flushPromises()
			expect(setResult).toHaveBeenCalledWith({ error: message })
		})
	})

	describe('form actions', () => {
		const form = (wrapper) => wrapper.findComponent({ name: 'CnSchemaFormDialog' })

		it('close leaves draft mode and closes modal and dialog', async () => {
			const wrapper = await mountModal()
			form(wrapper).vm.$emit('close')
			expect(schemaStore.setDraftMode).toHaveBeenCalledWith(false)
			expect(navigationStore.setModal).toHaveBeenCalledWith(false)
			expect(navigationStore.setDialog).toHaveBeenCalledWith(false)
		})

		it('extendSchema starts a new schema that extends the current one', async () => {
			schemaStore.schemaItem = { id: 4, title: 'Person' }
			const wrapper = await mountModal()
			form(wrapper).vm.$emit('extendSchema')
			expect(schemaStore.setSchemaItem).toHaveBeenCalledWith({
				title: 'Extended Person',
				description: 'Schema extending Person',
				allOf: [4],
				properties: {},
				required: [],
			})
		})

		it.each([
			['analyzeProperties', 'exploreSchema'],
			['validateObjects', 'validateSchema'],
			['deleteObjects', 'deleteSchemaObjects'],
			['deleteSchema', 'deleteSchema'],
		])('%s opens the %s dialog', async (event, dialog) => {
			const wrapper = await mountModal()
			form(wrapper).vm.$emit(event)
			expect(navigationStore.setDialog).toHaveBeenCalledWith(dialog)
		})
	})
})
