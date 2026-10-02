import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { Schema } from '../../entities/index.js'
import { useSchemaStore } from './schema.js'

// modelling-schema-draft: the store speaks the three draft routes, and the
// Schema entity keeps the draft the server returns, or the badge never shows.

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: {
		put: jest.fn(),
		post: jest.fn(),
		delete: jest.fn(),
	},
}))

jest.mock('@nextcloud/router', () => ({
	__esModule: true,
	generateUrl: jest.fn((path) => `/index.php${path}`),
}))

const published = {
	id: 7,
	title: 'Contact',
	version: '1.0.0',
	properties: { name: { type: 'string' }, email: { type: 'string' } },
	required: ['name'],
}

describe('Schema drafts in the store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		jest.clearAllMocks()
	})

	it('keeps the draft on the Schema entity', () => {
		const schema = new Schema({
			...published,
			draft: { required: ['name', 'email'] },
		})
		expect(schema.draft).toEqual({ required: ['name', 'email'] })
		expect(new Schema(published).draft).toBeNull()
	})

	it('saves a draft with PUT ?draft=true and leaves the published definition in place', async () => {
		const store = useSchemaStore()
		const draft = { ...published, required: ['name', 'email'] }
		axios.put.mockResolvedValue({ data: { ...published, draft } })

		await store.saveSchemaDraft({ ...draft, stats: {}, draft: { stale: true } })

		expect(axios.put).toHaveBeenCalledTimes(1)
		const [url, body] = axios.put.mock.calls[0]
		expect(url).toBe('/index.php/apps/openregister/api/schemas/7?draft=true')
		// The normal save payload shape: the required list travels as per-property flags.
		expect(body.properties.email.required).toBe(true)
		expect(body).not.toHaveProperty('draft')
		expect(store.schemaItem.required).toEqual(['name'])
		expect(store.schemaItem.draft.required).toEqual(['name', 'email'])
	})

	it('publishes with POST .../draft/publish and passes the breaking acknowledgement', async () => {
		const store = useSchemaStore()
		const refresh = jest.spyOn(store, 'refreshSchemaList').mockResolvedValue()
		axios.post.mockResolvedValue({
			data: {
				...published,
				required: ['name', 'email'],
				version: '2.0.0',
				draft: null,
			},
		})

		await store.publishSchemaDraft(7, { acknowledgeBreaking: true })

		expect(axios.post).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/schemas/7/draft/publish',
			{ acknowledgeBreaking: true },
		)
		expect(refresh).toHaveBeenCalled()
		expect(store.schemaItem.version).toBe('2.0.0')
		expect(store.schemaItem.draft).toBeNull()
	})

	it('discards with DELETE .../draft', async () => {
		const store = useSchemaStore()
		axios.delete.mockResolvedValue({ data: { ...published, draft: null } })

		await store.discardSchemaDraft(7)

		expect(axios.delete).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/schemas/7/draft',
		)
		expect(store.schemaItem.draft).toBeNull()
	})

	it('opens the editor on the draft body in draft mode', () => {
		const store = useSchemaStore()
		store.setSchemaItem({
			...published,
			draft: { ...published, required: ['name', 'email'] },
		})

		expect(store.editorSchemaItem.required).toEqual(['name'])
		store.setDraftMode(true)
		expect(store.editorSchemaItem.required).toEqual(['name', 'email'])
		expect(store.editorSchemaItem.id).toBe(7)
	})
})
