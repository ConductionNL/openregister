/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Files action "Add to object": when it is offered and what it reports.
 *
 * @spec openspec/changes/files-leaf-save-to-object/specs/file-actions/spec.md
 */

const mockRegisterFileAction = jest.fn()
jest.mock('@nextcloud/files', () => ({
	Permission: { NONE: 0, READ: 1, UPDATE: 2 },
	registerFileAction: (action) => mockRegisterFileAction(action),
}), { virtual: true })
const mockSpawn = jest.fn()
jest.mock('@nextcloud/vue/functions/dialog', () => ({
	spawnDialog: (...args) => mockSpawn(...args),
}), { virtual: true })
const mockSuccess = jest.fn()
const mockError = jest.fn()
jest.mock('@nextcloud/dialogs', () => ({
	showSuccess: (msg) => mockSuccess(msg),
	showError: (msg) => mockError(msg),
}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) => text.replace(/{(\w+)}/g, (m, k) => (vars && k in vars ? vars[k] : m)),
}))
jest.mock('../dialogs/files/AttachToObjectDialog.vue', () => ({ name: 'AttachToObjectDialog' }))

const { ATTACH_ACTION_ID, attachAction, registerAttachAction } = require('./attachAction.js')

const node = (permissions = 1, fileid = 42) => ({ permissions, fileid })

beforeEach(() => {
	jest.clearAllMocks()
})

describe('enabled', () => {
	it('is offered on one readable node', () => {
		expect(attachAction.enabled({ nodes: [node()] })).toBe(true)
	})

	it('is not offered on a node the caller cannot read', () => {
		expect(attachAction.enabled({ nodes: [node(2)] })).toBe(false)
	})

	it('is not offered on several nodes, none, or a node without an id', () => {
		expect(attachAction.enabled({ nodes: [node(), node()] })).toBe(false)
		expect(attachAction.enabled({ nodes: [] })).toBe(false)
		expect(attachAction.enabled({ nodes: [{ permissions: 1 }] })).toBe(false)
	})
})

describe('exec', () => {
	it('opens the picker for the node and names the object it was added to', async () => {
		mockSpawn.mockResolvedValue({ object: { title: 'Case 12' }, attached: [{ id: 1 }], skipped: 0 })

		await expect(attachAction.exec({ nodes: [node(1, 42)] })).resolves.toBe(true)

		expect(mockSpawn).toHaveBeenCalledWith({ name: 'AttachToObjectDialog' }, { nodeId: 42 })
		expect(mockSuccess).toHaveBeenCalledWith('Added to Case 12')
		expect(mockError).not.toHaveBeenCalled()
	})

	it('reports nothing when the picker is closed without a pick', async () => {
		mockSpawn.mockResolvedValue(undefined)

		await expect(attachAction.exec({ nodes: [node()] })).resolves.toBeNull()

		expect(mockSuccess).not.toHaveBeenCalled()
		expect(mockError).not.toHaveBeenCalled()
	})

	it('reports a failure when nothing was attached (an empty folder)', async () => {
		mockSpawn.mockResolvedValue({ object: { title: 'Case 12' }, attached: [], skipped: 3 })

		await expect(attachAction.exec({ nodes: [node()] })).resolves.toBe(false)

		expect(mockError).toHaveBeenCalledWith('The file could not be added to the object.')
	})
})

describe('registerAttachAction', () => {
	it('registers the action with Files under its id', () => {
		registerAttachAction()

		expect(mockRegisterFileAction).toHaveBeenCalledTimes(1)
		expect(mockRegisterFileAction.mock.calls[0][0].id).toBe(ATTACH_ACTION_ID)
		expect(mockRegisterFileAction.mock.calls[0][0].displayName()).toBe('Add to object')
	})

	it('does not throw when Files refuses a second registration', () => {
		mockRegisterFileAction.mockImplementationOnce(() => {
			throw new Error('already registered')
		})

		expect(() => registerAttachAction()).not.toThrow()
	})
})
