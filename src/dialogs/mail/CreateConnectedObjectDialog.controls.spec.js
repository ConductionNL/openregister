/**
 * Which form control the connected-object dialog shows for each kind of
 * schema property.
 *
 * @spec exclude Unit test of client-side form control choice.
 */
import axios from '@nextcloud/axios'
import CreateConnectedObjectDialog, { isRelation, relationOptions } from './CreateConnectedObjectDialog.vue'

jest.mock('@nextcloud/axios', () => ({ get: jest.fn() }))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path, params) => path.replace(/\{(\w+)\}/g, (m, key) => params[key]),
}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
jest.mock('vue-material-design-icons/Plus.vue', () => ({}))
jest.mock('../../components/shared/CloseCancelButton.vue', () => ({}))

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
