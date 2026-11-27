/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Drafts tab's calls: the versions routes, the schema's preview template,
 * the compare rows and the parsing of typed changes.
 *
 * @spec openspec/changes/records-draft-versions
 */
import axios from '@nextcloud/axios'
import {
	compareRows,
	createDraft,
	discardDraft,
	displayValue,
	listDrafts,
	parseChanges,
	previewDraft,
	promoteDraft,
	readDraft,
	schemaPreviewUrl,
	updateDraft,
	versionsUrl,
} from './draftsApi.js'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), post: jest.fn(), put: jest.fn(), delete: jest.fn() },
}))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) => '/index.php' + path.replace(/{(\w+)}/g, (m, key) => params[key]),
}))

const TARGET = { register: 'zaken', schema: 'vergunning', id: 'v-1' }
const BASE = '/index.php/apps/openregister/api/objects/zaken/vergunning/v-1/versions'

beforeEach(() => {
	jest.resetAllMocks()
})

describe('versionsUrl', () => {
	it('addresses the object, a draft and an action on a draft', () => {
		expect(versionsUrl(TARGET)).toBe(BASE)
		expect(versionsUrl(TARGET, 'status-update')).toBe(BASE + '/status-update')
		expect(versionsUrl(TARGET, 'status-update', 'promote')).toBe(BASE + '/status-update/promote')
		expect(versionsUrl(TARGET, '', 'promote')).toBe(BASE)
	})
})

describe('the versions calls', () => {
	it('lists the results, and nothing when the answer has none', async () => {
		axios.get.mockResolvedValueOnce({ data: { results: [{ key: 'a' }] } })
		expect(await listDrafts(TARGET)).toEqual([{ key: 'a' }])
		expect(axios.get).toHaveBeenCalledWith(BASE)

		axios.get.mockResolvedValueOnce({ data: {} })
		expect(await listDrafts(TARGET)).toEqual([])
	})

	it('reads one draft merged onto the object', async () => {
		axios.get.mockResolvedValueOnce({ data: { status: 'in_behandeling' } })
		expect(await readDraft(TARGET, 'status-update')).toEqual({ status: 'in_behandeling' })
		expect(axios.get).toHaveBeenCalledWith(BASE + '/status-update')
	})

	it('creates a draft with its changes under `object`, and updates one', async () => {
		axios.post.mockResolvedValueOnce({ data: { key: 'status-update' } })
		expect(await createDraft(TARGET, { key: 'status-update', name: 'Status', changes: { status: 'x' } })).toEqual({ key: 'status-update' })
		expect(axios.post).toHaveBeenCalledWith(BASE, { key: 'status-update', name: 'Status', object: { status: 'x' } })

		axios.put.mockResolvedValueOnce({ data: { key: 'status-update' } })
		await updateDraft(TARGET, 'status-update', { status: 'y' })
		expect(axios.put).toHaveBeenCalledWith(BASE + '/status-update', { object: { status: 'y' } })
	})

	it('discards, promotes with and without force, and previews', async () => {
		axios.delete.mockResolvedValueOnce({})
		await discardDraft(TARGET, 'a')
		expect(axios.delete).toHaveBeenCalledWith(BASE + '/a')

		axios.post.mockResolvedValue({ data: { id: 'v-1' } })
		await promoteDraft(TARGET, 'a')
		expect(axios.post).toHaveBeenLastCalledWith(BASE + '/a/promote', {})
		await promoteDraft(TARGET, 'a', true)
		expect(axios.post).toHaveBeenLastCalledWith(BASE + '/a/promote', { force: true })

		axios.post.mockResolvedValueOnce({ data: { previewUrl: 'https://site/p?token=t' } })
		expect(await previewDraft(TARGET, 'a')).toEqual({ previewUrl: 'https://site/p?token=t' })
		expect(axios.post).toHaveBeenLastCalledWith(BASE + '/a/preview', {})
	})
})

describe('schemaPreviewUrl', () => {
	it('is the declared template, else null, and null when the schema cannot be read', async () => {
		axios.get.mockResolvedValueOnce({ data: { configuration: { previewUrl: 'https://site/{uuid}' } } })
		expect(await schemaPreviewUrl('vergunning')).toBe('https://site/{uuid}')
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/schemas/vergunning')

		axios.get.mockResolvedValueOnce({ data: { configuration: { previewUrl: '' } } })
		expect(await schemaPreviewUrl('vergunning')).toBeNull()

		axios.get.mockResolvedValueOnce({ data: {} })
		expect(await schemaPreviewUrl('vergunning')).toBeNull()

		axios.get.mockRejectedValueOnce(new Error('403'))
		expect(await schemaPreviewUrl('vergunning')).toBeNull()
	})
})

describe('compareRows and displayValue', () => {
	it('pairs each changed field with its published and draft value', () => {
		expect(compareRows(['status', 'tags', 'missing'], { status: 'nieuw', tags: ['a'] }, { status: 'in_behandeling', tags: ['a', 'b'] })).toEqual([
			{ field: 'status', published: 'nieuw', draft: 'in_behandeling' },
			{ field: 'tags', published: '["a"]', draft: '["a","b"]' },
			{ field: 'missing', published: '—', draft: '—' },
		])
		expect(compareRows(undefined, {}, {})).toEqual([])
	})

	it('reads absent values as a dash and keeps zero and false', () => {
		expect(displayValue(null)).toBe('—')
		expect(displayValue(0)).toBe('0')
		expect(displayValue(false)).toBe('false')
	})
})

describe('parseChanges', () => {
	it('accepts only a JSON object', () => {
		expect(parseChanges('{"status": "x"}')).toEqual({ status: 'x' })
		expect(parseChanges('{}')).toEqual({})
		expect(parseChanges('[1]')).toBeNull()
		expect(parseChanges('null')).toBeNull()
		expect(parseChanges('"x"')).toBeNull()
		expect(parseChanges('{status')).toBeNull()
	})
})
