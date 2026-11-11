import SchemaDraftBar from './SchemaDraftBar.vue'
import { schemaStore } from '../../store/store.js'

// modelling-schema-draft: the bar publishes and discards through the store,
// and a breaking change is a question it asks, never one it answers.

jest.mock('../../store/store.js', () => ({
	schemaStore: {
		publishSchemaDraft: jest.fn(),
		discardSchemaDraft: jest.fn(),
	},
}))
jest.mock('@conduction/nextcloud-vue', () => ({
	describeSchemaChange: jest.fn((change) => change.property),
}))
jest.mock('@nextcloud/vue', () => ({ NcButton: {}, NcNoteCard: {} }))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, s) => s }))

/**
 * A bare component context: the options object's methods and data, without a mount.
 *
 * @return {object} The context
 */
function context() {
	return {
		schema: { id: 7, draft: { required: ['name', 'email'] } },
		...SchemaDraftBar.data(),
	}
}

describe('SchemaDraftBar', () => {
	beforeEach(() => jest.clearAllMocks())

	it('asks before publishing a breaking draft, then publishes it acknowledged', async () => {
		const ctx = context()
		schemaStore.publishSchemaDraft.mockRejectedValueOnce({
			response: {
				status: 409,
				data: { error: 'breaking', changes: [{ property: 'email' }] },
			},
		})

		await SchemaDraftBar.methods.publish.call(ctx, false)

		expect(schemaStore.publishSchemaDraft).toHaveBeenCalledWith(7, {
			acknowledgeBreaking: false,
		})
		expect(ctx.breakingChanges).toEqual([{ property: 'email' }])
		expect(ctx.busy).toBe(false)

		schemaStore.publishSchemaDraft.mockResolvedValueOnce({})
		await SchemaDraftBar.methods.publish.call(ctx, true)

		expect(schemaStore.publishSchemaDraft).toHaveBeenLastCalledWith(7, {
			acknowledgeBreaking: true,
		})
		expect(ctx.breakingChanges).toEqual([])
	})

	it('shows the server error when a publish fails for another reason', async () => {
		const ctx = context()
		schemaStore.publishSchemaDraft.mockRejectedValueOnce({
			response: {
				status: 403,
				data: {
					error: 'User does not have permission to manage this schema',
				},
			},
		})

		await SchemaDraftBar.methods.publish.call(ctx, false)

		expect(ctx.errorMessage).toBe(
			'User does not have permission to manage this schema',
		)
		expect(ctx.breakingChanges).toEqual([])
	})

	it('discards the draft through the store', async () => {
		const ctx = context()
		schemaStore.discardSchemaDraft.mockResolvedValueOnce({})

		await SchemaDraftBar.methods.discard.call(ctx)

		expect(schemaStore.discardSchemaDraft).toHaveBeenCalledWith(7)
		expect(ctx.errorMessage).toBe('')
	})
})
