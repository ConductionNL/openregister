/**
 * The Rights tab of a schema: the grid it builds from the matrix, a save that
 * changes `authorization.matrix` and nothing else, a refusal answered next to
 * the cell it names, and the per-user preview.
 *
 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import SchemaRightsTab from './SchemaRightsTab.vue'
import {
	fetchMatrixPreview,
	findingsByCell,
	gridFromSchema,
	rowsFromGrid,
	schemaWithMatrix,
} from '../../services/rightsMatrix.js'
import { schemaStore } from '../../store/store.js'

jest.mock('@nextcloud/l10n', () => ({
	__esModule: true,
	translate: (app, text, vars) => text.replace(/\{(\w+)\}/g, (all, name) => (vars && name in vars ? vars[name] : all)),
}))
jest.mock('@nextcloud/vue', () => ({
	__esModule: true,
	NcAppContentDetails: { name: 'NcAppContentDetails', render() { return require('vue').h('div', this.$slots.default()) } },
	NcLoadingIcon: { name: 'NcLoadingIcon', render: () => null },
	NcButton: {
		name: 'NcButton',
		emits: ['click'],
		render() {
			return require('vue').h('button', { onClick: () => this.$emit('click') }, this.$slots.default())
		},
	},
	NcSelect: { name: 'NcSelect', props: ['modelValue', 'inputLabel', 'options'], emits: ['update:modelValue', 'search'], render: () => null },
}))
jest.mock('../../components/shared/SaveButton.vue', () => ({
	__esModule: true,
	default: {
		name: 'SaveButton',
		emits: ['click'],
		render() {
			return require('vue').h('button', { class: 'save', onClick: () => this.$emit('click') }, 'Save')
		},
	},
}))
jest.mock('../../store/store.js', () => ({ __esModule: true, schemaStore: { saveSchema: jest.fn() } }))
jest.mock('../../services/rightsMatrix.js', () => ({
	...jest.requireActual('../../services/rightsMatrix.js'),
	fetchMatrixPreview: jest.fn(),
	searchUsers: jest.fn(),
}))

/**
 * A schema with a matrix on `department`, an enum, a row the grid cannot
 * show, and other authorization keys a save must leave alone.
 *
 * @return {object} The schema.
 */
function schema() {
	return {
		id: 7,
		title: 'Case',
		properties: { department: { type: 'string', enum: ['VTH', 'Belastingen'] }, title: { type: 'string' } },
		authorization: {
			read: ['admin'],
			matrix: {
				field: 'department',
				userSource: { groupPrefix: 'dept:' },
				fileVerbs: true,
				rows: [
					{ value: 'VTH', group: 'handlers', actions: ['read', 'handle'] },
					{ value: '$self', group: 'handlers', actions: ['read'] },
					{ value: 'VTH', group: 'clerks', actions: ['read'], where: { status: 'open' } },
				],
			},
		},
	}
}

describe('rights matrix grid', () => {
	it('builds the grid from the enum and the rows, keeping rows it cannot show', () => {
		const grid = gridFromSchema(schema())
		expect(grid.values).toEqual(['VTH', 'Belastingen', '$self'])
		expect(grid.groups).toEqual(['handlers'])
		expect(grid.actions).toEqual(['read', 'update', 'handle', 'delete'])
		expect(grid.kept).toEqual([{ value: 'VTH', group: 'clerks', actions: ['read'], where: { status: 'open' } }])
	})

	it('writes the kept rows first, then one row per granting cell', () => {
		const grid = gridFromSchema(schema())
		grid.cells[JSON.stringify(['Belastingen', 'handlers'])] = ['delete', 'read']
		const { rows, cellsByRow } = rowsFromGrid(grid)
		expect(rows).toEqual([
			{ value: 'VTH', group: 'clerks', actions: ['read'], where: { status: 'open' } },
			{ value: 'VTH', group: 'handlers', actions: ['read', 'handle'] },
			{ value: 'Belastingen', group: 'handlers', actions: ['read', 'delete'] },
			{ value: '$self', group: 'handlers', actions: ['read'] },
		])
		expect(cellsByRow).toEqual([null, '["VTH","handlers"]', '["Belastingen","handlers"]', '["$self","handlers"]'])
	})

	it('changes authorization.matrix rows and nothing else', () => {
		const before = schema()
		const grid = gridFromSchema(before)
		grid.cells[JSON.stringify(['VTH', 'handlers'])] = []
		const saved = schemaWithMatrix(before, grid)
		const { authorization, ...rest } = saved
		const { authorization: beforeAuthorization, ...beforeRest } = schema()
		expect(rest).toEqual(beforeRest)
		expect(authorization.read).toEqual(beforeAuthorization.read)
		const { rows, ...matrixRest } = authorization.matrix
		const { rows: beforeRows, ...beforeMatrixRest } = beforeAuthorization.matrix
		expect(matrixRest).toEqual(beforeMatrixRest)
		expect(rows).toEqual([beforeRows[2], beforeRows[1]])
		expect(before).toEqual(schema())
	})

	it('puts a finding on the cell its row came from', () => {
		const text = 'authorization.matrix: Row 1 grants no action. Row 0 names no role group. The matrix field "x" is not a property of this schema.'
		expect(findingsByCell(text, [null, '["VTH","handlers"]'])).toEqual({
			general: ['Row 0 names no role group. The matrix field "x" is not a property of this schema.'],
			cells: { '["VTH","handlers"]': ['Row 1 grants no action.'] },
		})
	})
})

describe('SchemaRightsTab', () => {
	beforeEach(() => {
		schemaStore.saveSchema.mockReset()
		fetchMatrixPreview.mockReset()
	})

	it('renders one row per value and a checkbox per action', () => {
		const wrapper = mount(SchemaRightsTab, { props: { schema: schema() } })
		const rows = wrapper.findAll('table.rightsGrid tbody tr')
		expect(rows.map((row) => row.find('th').text())).toEqual(['VTH', 'Belastingen', '$self'])
		const checked = rows[0].findAll('input[type=checkbox]').map((box) => box.element.checked)
		expect(checked).toEqual([true, false, true, false])
	})

	it('saves the toggled cell through the schema store, only in the matrix', async () => {
		schemaStore.saveSchema.mockResolvedValue({})
		const wrapper = mount(SchemaRightsTab, { props: { schema: schema() } })
		await wrapper.findAll('table.rightsGrid tbody tr')[1].findAll('input[type=checkbox]')[0].setValue(true)
		await wrapper.find('button.save').trigger('click')
		await flushPromises()

		expect(schemaStore.saveSchema).toHaveBeenCalledTimes(1)
		const sent = schemaStore.saveSchema.mock.calls[0][0]
		const { authorization, ...rest } = sent
		const { authorization: beforeAuthorization, ...beforeRest } = schema()
		expect(rest).toEqual(beforeRest)
		expect({ ...authorization, matrix: undefined }).toEqual({ ...beforeAuthorization, matrix: undefined })
		expect(authorization.matrix.rows).toContainEqual({ value: 'Belastingen', group: 'handlers', actions: ['read'] })
	})

	it('answers a refusal next to the cell it names', async () => {
		schemaStore.saveSchema.mockRejectedValue({ response: { data: { error: 'authorization.matrix: Row 1 names the action "x", which is not one this engine resolves.' } } })
		const wrapper = mount(SchemaRightsTab, { props: { schema: schema() } })
		await wrapper.find('button.save').trigger('click')
		await flushPromises()

		const firstCell = wrapper.findAll('table.rightsGrid tbody tr')[0].find('td')
		expect(firstCell.find('.refusal').text()).toBe('Row 1 names the action "x", which is not one this engine resolves.')
	})

	it('previews the scopes for the chosen user', async () => {
		fetchMatrixPreview.mockResolvedValue({
			schema: 7,
			user: 'alice',
			values: ['VTH'],
			scopes: { read: [{ group: 'handlers', match: { department: { $in: ['VTH'] } } }] },
		})
		const wrapper = mount(SchemaRightsTab, { props: { schema: schema() } })
		const select = wrapper.findComponent({ name: 'NcSelect' })
		expect(select.props('inputLabel')).toBe('Select a user')
		await select.vm.$emit('update:modelValue', { id: 'alice', label: 'alice' })
		await flushPromises()

		expect(fetchMatrixPreview).toHaveBeenCalledWith(7, 'alice')
		const preview = wrapper.find('[data-testid=matrix-preview]')
		expect(preview.text()).toContain('Own values: VTH')
		expect(preview.find('li').text()).toContain('{"department":{"$in":["VTH"]}}')
	})
})
