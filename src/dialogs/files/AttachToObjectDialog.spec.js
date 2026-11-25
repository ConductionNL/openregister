/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The "Add to object" picker: what it asks the server and what it does with the answer.
 *
 * @spec openspec/changes/files-leaf-save-to-object/specs/file-actions/spec.md
 */

const mockGet = jest.fn()
const mockPost = jest.fn()
jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: {
		get: (...args) => mockGet(...args),
		post: (...args) => mockPost(...args),
	},
}))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (url, params = {}) => url.replace(/{(\w+)}/g, (m, k) => params[k]),
}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
}))
jest.mock('@nextcloud/vue', () => ({
	NcButton: {},
	NcDialog: {},
	NcLoadingIcon: {},
	NcTextField: {},
}))

const AttachToObjectDialog = require('./AttachToObjectDialog.vue').default

/**
 * A stand-in for the component instance: its data, props and an $emit spy.
 *
 * @param {object} props The props
 * @return {object}
 */
function instance(props = {}) {
	const ctx = {
		...AttachToObjectDialog.data(),
		nodeId: 42,
		targets: null,
		...props,
		$emit: jest.fn(),
	}
	for (const [name, fn] of Object.entries(AttachToObjectDialog.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	return ctx
}

beforeEach(() => {
	jest.clearAllMocks()
})

describe('find', () => {
	it('searches the typed title and keeps the offered targets', async () => {
		const answer = {
			targets: [{ schema: 'case', register: 'cases', label: 'Case' }],
			results: [{ objectUuid: 'u-1', title: 'Case 12', schema: 'case', register: 'cases' }],
		}
		mockGet.mockResolvedValue({ data: answer })
		const vm = instance()
		vm.search = ' case '

		await vm.find()

		expect(mockGet).toHaveBeenCalledWith('/apps/openregister/api/files/attach-targets', { params: { search: 'case' } })
		expect(vm.results).toEqual(answer.results)
		expect(vm.labelOf(answer.results[0])).toBe('Case')
		expect(vm.searched).toBe(true)
		expect(vm.loading).toBe(false)
	})

	it('passes a declared narrowing on', async () => {
		mockGet.mockResolvedValue({ data: { targets: [], results: [] } })
		const vm = instance({ targets: ['case', 'permit'] })
		vm.search = 'x'

		await vm.find()

		expect(mockGet.mock.calls[0][1]).toEqual({ params: { search: 'x', targets: 'case,permit' } })
	})

	it('does not ask the server for an empty search', async () => {
		const vm = instance()
		vm.search = '   '

		await vm.find()

		expect(mockGet).not.toHaveBeenCalled()
		expect(vm.searched).toBe(false)
	})

	it('shows an error and no results when the search fails', async () => {
		mockGet.mockRejectedValue(new Error('500'))
		const vm = instance()
		vm.results = [{ objectUuid: 'stale' }]
		vm.search = 'x'

		await vm.find()

		expect(vm.results).toEqual([])
		expect(vm.error).toBe('The file could not be added to the object.')
	})
})

describe('attach', () => {
	const pick = { objectUuid: 'u-1', title: 'Case 12', schema: 'case', register: 'cases' }

	it('attaches the node to the picked object and closes with the result', async () => {
		mockPost.mockResolvedValue({ data: { attached: [{ id: 7 }], skipped: 0 } })
		const vm = instance()

		await vm.attach(pick)

		expect(mockPost).toHaveBeenCalledWith('/apps/openregister/api/objects/cases/case/u-1/files/attach', { nodeId: 42 })
		expect(vm.$emit).toHaveBeenCalledWith('close', { object: pick, attached: [{ id: 7 }], skipped: 0 })
		expect(vm.saving).toBe(false)
	})

	it('stays open with the server refusal when the attach is refused', async () => {
		mockPost.mockRejectedValue({ response: { data: { error: 'You may not add files to this object.' } } })
		const vm = instance()

		await vm.attach(pick)

		expect(vm.$emit).not.toHaveBeenCalled()
		expect(vm.error).toBe('You may not add files to this object.')
	})
})
