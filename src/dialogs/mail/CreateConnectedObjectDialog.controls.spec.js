/**
 * Which form control the connected-object dialog shows for each kind of
 * schema property.
 *
 * @spec exclude Unit test of client-side form control choice.
 */
import axios from '@nextcloud/axios'
import CreateConnectedObjectDialog, { dateOf, enumLabel, groupOptions, inSchemaOrder, isGroup, isoDateOf, isRelation, loadingOptions, relationOptions } from './CreateConnectedObjectDialog.vue'

jest.mock('@nextcloud/axios', () => ({ get: jest.fn() }))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path, params) => path.replace(/\{(\w+)\}/g, (m, key) => params[key]),
	generateOcsUrl: (path) => '/ocs/v2.php/' + path,
}))
jest.mock('@nextcloud/vue', () => ({}))
const mockCatalogues = { dossiq: { Email: 'E-mail', 'Intake channel': 'Intakekanaal' }, openregister: { Phone: 'Telefoon' } }
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => mockCatalogues[app]?.[text] ?? text,
	loadTranslations: jest.fn(() => Promise.resolve()),
}))
jest.mock('vue-material-design-icons/Plus.vue', () => ({}))

const { controlFor } = CreateConnectedObjectDialog.methods

describe('CreateConnectedObjectDialog.controlFor', () => {
	it.each([
		['status', { enum: ['a', 'b'], type: 'boolean' }, 'select'],
		['status', { enum: [], type: 'boolean' }, 'checkbox'],
		['count', { type: 'number', format: 'date' }, 'number'],
		['count', { type: 'integer' }, 'number'],
		['due', { type: 'string', format: 'date' }, 'date'],
		['notes', { type: 'string', format: 'date-time' }, 'textarea'],
		['Description', { type: 'string' }, 'textarea'],
		['emailBody', {}, 'textarea'],
		['summaryText', { enum: 'not a list' }, 'textarea'],
		['title', { type: 'string' }, 'text'],
		['contentType', { format: 'date' }, 'date'],
		['caseType', { $ref: 'caseType', type: 'string', format: 'uuid' }, 'select'],
		['tags', { $ref: 'tag', type: 'array' }, 'text'],
	])('shows %s (%j) as %s', (key, def, control) => {
		expect(controlFor(key, def)).toBe(control)
	})
})

describe('CreateConnectedObjectDialog relations', () => {
	const WOO = '3c0f5a00-0000-4000-a000-00000000a002'

	it('knows a single reference from a list or an object', () => {
		expect(isRelation({ $ref: 'caseType', type: 'string' })).toBe(true)
		expect(isRelation({ $ref: 'tag', type: 'array' })).toBe(false)
		expect(isRelation({ $ref: '', type: 'string' })).toBe(false)
		expect(isRelation({ type: 'string' })).toBe(false)
	})

	it('names the related objects, drops superseded ones and keeps the current value', () => {
		const objects = [
			{ id: WOO, name: 'Woo-verzoek', supersededBy: null },
			{ id: 'old', name: 'Woo-verzoek (old)', supersededBy: WOO },
			{ '@self': { id: 'b', name: 'Bezwaar' } },
		]
		expect(relationOptions(objects, { supersededBy: 'IS NULL' }, WOO)).toEqual([
			{ value: WOO, label: 'Woo-verzoek' },
			{ value: 'b', label: 'Bezwaar' },
		])
		expect(relationOptions([], {}, WOO)).toEqual([{ value: WOO, label: WOO }])
		expect(relationOptions([], {}, '')).toEqual([])
	})

	it('reads the choices from the register and the related schema', async () => {
		axios.get.mockResolvedValue({ data: { results: [{ id: WOO, name: 'Woo-verzoek' }] } })
		const vm = {
			schema: { properties: { caseType: { $ref: 'caseType', type: 'string', 'x-relation-filter': { isDraft: false, supersededBy: 'IS NULL' } }, title: { type: 'string' } } },
			register: { id: 42 },
			initialData: { caseType: WOO, title: 'Havenpark' },
			relations: {},
		}
		await CreateConnectedObjectDialog.methods.loadRelations.call(vm)

		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(axios.get).toHaveBeenCalledWith('/apps/openregister/api/objects/42/caseType', { params: { _limit: 100, isDraft: false } })
		expect(vm.relations).toEqual({ caseType: [{ value: WOO, label: 'Woo-verzoek' }] })

		const fields = CreateConnectedObjectDialog.computed.fields.call({ ...vm, controlFor: CreateConnectedObjectDialog.methods.controlFor, humanize: CreateConnectedObjectDialog.methods.humanize })
		expect(fields[0]).toMatchObject({ key: 'caseType', control: 'select', options: [{ value: WOO, label: 'Woo-verzoek' }] })
	})
})

describe('CreateConnectedObjectDialog dates', () => {
	it('reads a stored calendar date or timestamp as a local date', () => {
		const date = dateOf('2026-11-23')
		expect([date.getFullYear(), date.getMonth(), date.getDate()]).toEqual([2026, 10, 23])
		expect(dateOf('2026-11-23T09:05:00+01:00').getDate()).toBe(23)
		expect(dateOf('')).toBeNull()
		expect(dateOf(null)).toBeNull()
		expect(dateOf('23/11/2026')).toBeNull()
	})

	it('stores a picked date as a calendar date, and a cleared one as empty', () => {
		expect(isoDateOf(new Date(2026, 0, 5))).toBe('2026-01-05')
		expect(isoDateOf(null)).toBe('')
		expect(isoDateOf(new Date('nonsense'))).toBe('')
	})
})

describe('CreateConnectedObjectDialog enum labels', () => {
	it('reads a label map, then a parallel list, else the value', () => {
		const def = { enum: ['email', 'post', 'zgw-api'], 'x-enum-labels': { email: 'Email', post: 'Letter' } }
		expect(enumLabel(def, 'email', 0)).toBe('Email')
		expect(enumLabel(def, 'zgw-api', 2)).toBe('zgw-api')
		expect(enumLabel({ enum: ['a', 'b'], enumNames: ['Alpha', ''] }, 'a', 0)).toBe('Alpha')
		expect(enumLabel({ enum: ['a', 'b'], enumNames: ['Alpha', ''] }, 'b', 1)).toBe('b')
		expect(enumLabel({ enum: ['a'], enumLabels: ['Alpha'] }, 'a', 0)).toBe('Alpha')
		expect(enumLabel({ enum: ['a'], enumLabels: { a: 'Alpha' } }, 'a', 0)).toBe('Alpha')
		expect(enumLabel({ enum: [3] }, 3, 0)).toBe('3')
	})

	it('translates through the owning app first, then OpenRegister, else keeps the English', () => {
		const { translateLabel } = CreateConnectedObjectDialog.methods
		const ctx = { register: { application: 'dossiq' }, catalogueLoaded: 0 }
		expect(translateLabel.call(ctx, 'Email')).toBe('E-mail')
		expect(translateLabel.call(ctx, 'Phone')).toBe('Telefoon')
		expect(translateLabel.call(ctx, 'Web form')).toBe('Web form')
		expect(translateLabel.call({ register: null, catalogueLoaded: 0 }, 'Email')).toBe('Email')
	})

	it('loads the owning app catalogue once the dialog opens, and not OpenRegister\'s own', async () => {
		const { loadTranslations } = require('@nextcloud/l10n')
		const { loadOwnerCatalogue } = CreateConnectedObjectDialog.methods
		const ctx = { register: { application: 'dossiq' }, catalogueLoaded: 0 }
		await loadOwnerCatalogue.call(ctx)
		expect(loadTranslations).toHaveBeenCalledWith('dossiq')
		expect(ctx.catalogueLoaded).toBe(1)
		loadTranslations.mockClear()
		await loadOwnerCatalogue.call({ register: { application: 'openregister' }, catalogueLoaded: 0 })
		await loadOwnerCatalogue.call({ register: null, catalogueLoaded: 0 })
		expect(loadTranslations).not.toHaveBeenCalled()
	})

	it('keeps English labels when the catalogue cannot load', async () => {
		const { loadTranslations } = require('@nextcloud/l10n')
		loadTranslations.mockImplementationOnce(() => Promise.reject(new Error('404')))
		const ctx = { register: { application: 'dossiq' }, catalogueLoaded: 0 }
		await CreateConnectedObjectDialog.methods.loadOwnerCatalogue.call(ctx)
		expect(ctx.catalogueLoaded).toBe(0)
	})
})

describe('CreateConnectedObjectDialog order and groups', () => {
	it('puts fields in the schema order, unordered ones last in template order', () => {
		const props = { title: { order: 2 }, caseType: { order: 1 }, team: {}, notes: {} }
		const fields = ['title', 'team', 'caseType', 'notes'].map((key) => ({ key }))
		expect(inSchemaOrder(fields, props).map((field) => field.key)).toEqual(['caseType', 'title', 'team', 'notes'])
	})

	it('knows a group field by its markers', () => {
		expect(isGroup({ referenceType: 'nextcloud-group' })).toBe(true)
		expect(isGroup({ format: 'nc-group' })).toBe(true)
		expect(isGroup({ widget: 'group' })).toBe(true)
		expect(isGroup({ type: 'string' })).toBe(false)
		expect(controlFor('assignedGroup', { referenceType: 'nextcloud-group', enum: ['x'] })).toBe('group')
	})

	it('names the groups by display name and keeps the current id', () => {
		const groups = [{ id: 'woo-en-informatie', label: 'Woo and information' }, { id: 'admin' }]
		expect(groupOptions(groups, 'woo-en-informatie')).toEqual([
			{ value: 'woo-en-informatie', label: 'Woo and information' },
			{ value: 'admin', label: 'admin' },
		])
		expect(groupOptions([], 'gone')).toEqual([{ value: 'gone', label: 'gone' }])
		expect(groupOptions(null, '')).toEqual([])
	})

	it('reads the groups only for a template with a group field, and keeps going when the read fails', async () => {
		const { loadGroups } = CreateConnectedObjectDialog.methods
		axios.get.mockResolvedValueOnce({ data: { ocs: { data: [{ id: 'g', label: 'G', source: 'groups' }, { id: 'u', label: 'U', source: 'users' }] } } })
		const ctx = { schema: { properties: { team: { referenceType: 'nextcloud-group' } } }, initialData: { team: 'g' }, groups: [] }
		await loadGroups.call(ctx)
		expect(ctx.groups).toEqual([{ id: 'g', label: 'G', source: 'groups' }])
		axios.get.mockClear()
		await loadGroups.call({ schema: { properties: {} }, initialData: { title: 'x' }, groups: [] })
		expect(axios.get).not.toHaveBeenCalled()
		axios.get.mockRejectedValueOnce(new Error('down'))
		const failing = { ...ctx, groups: [] }
		await loadGroups.call(failing)
		expect(failing.groups).toEqual([])
	})

	it('shows the start of the first focused text field once per opening', () => {
		const { showFieldStart } = CreateConnectedObjectDialog.methods
		const input = { type: 'text', scrollLeft: 120, setSelectionRange: jest.fn() }
		const ctx = { startShown: false }
		showFieldStart.call(ctx, { target: input })
		expect(input.setSelectionRange).toHaveBeenCalledWith(0, 0)
		expect(input.scrollLeft).toBe(0)
		input.scrollLeft = 80
		showFieldStart.call(ctx, { target: input })
		expect(input.scrollLeft).toBe(80)
	})
})

describe('CreateConnectedObjectDialog loading names', () => {
	it('shows the current value under Loading… until its name is read, never the raw id', () => {
		expect(loadingOptions('3c0f5a00-0000-4000-a000-00000000a002')).toEqual([{ value: '3c0f5a00-0000-4000-a000-00000000a002', label: 'Loading…' }])
		expect(loadingOptions('')).toEqual([])
	})

	it('marks the group list read when the read ends, also when it fails', async () => {
		const { loadGroups } = CreateConnectedObjectDialog.methods
		axios.get.mockRejectedValueOnce(new Error('down'))
		const ctx = { schema: { properties: { team: { referenceType: 'nextcloud-group' } } }, initialData: { team: 'g' }, groups: [], groupsLoaded: false }
		await loadGroups.call(ctx)
		expect(ctx.groupsLoaded).toBe(true)
	})
})
