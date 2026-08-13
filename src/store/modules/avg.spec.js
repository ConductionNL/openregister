/* eslint-disable no-console */
import { setActivePinia, createPinia } from 'pinia'

import axios from '@nextcloud/axios'
import {
	useAvgStore,
	CASE_LIFECYCLE_TRANSITIONS,
	resolveStatusLabel,
	resolveTierLabel,
	resolveGroundOptions,
	resolveTemplateRef,
} from './avg.js'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: {
		get: jest.fn(),
		post: jest.fn(),
		put: jest.fn(),
	},
}))

jest.mock('@nextcloud/router', () => ({
	__esModule: true,
	generateUrl: jest.fn((path) => `/index.php${path}`),
}))

const PACK = {
	jurisdiction: 'default',
	escalationTiers: [
		{ tier: 'Reminder', offsetDays: -7 },
		{ tier: 'Escalation', offsetDays: -2 },
		{ tier: 'Breach', offsetDays: 0 },
	],
	denialGrounds: [
		{
			key: 'manifestly-unfounded',
			label: 'Manifestly unfounded',
			citation: 'GDPR Art 12(5)',
		},
		{
			key: 'third-party-rights',
			label: 'Third-party rights',
			citation: 'GDPR Art 15(4)',
		},
	],
	templates: { denial: 'template:denial-default' },
}

describe('AVG store — DSAR case management', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		jest.clearAllMocks()
	})

	describe('label resolvers (pack-driven, no inlined jurisdiction strings)', () => {
		it('resolves tier labels from the pack escalationTiers by position', () => {
			expect(resolveTierLabel(PACK, 'reminder')).toBe('Reminder')
			expect(resolveTierLabel(PACK, 'escalation')).toBe('Escalation')
			expect(resolveTierLabel(PACK, 'breached')).toBe('Breach')
		})

		it('falls back to a humanised key for on-track / when no pack', () => {
			expect(resolveTierLabel(PACK, 'on-track')).toBe('On track')
			expect(resolveTierLabel(null, 'escalation')).toBe('Escalation')
		})

		it('maps denial grounds to options with label + citation', () => {
			const opts = resolveGroundOptions(PACK)
			expect(opts).toHaveLength(2)
			expect(opts[0]).toEqual({
				value: 'manifestly-unfounded',
				label: 'Manifestly unfounded',
				citation: 'GDPR Art 12(5)',
			})
		})

		it('returns [] grounds when the pack is null', () => {
			expect(resolveGroundOptions(null)).toEqual([])
		})

		it('resolves a template reference (leaf) from the pack, never inline body', () => {
			expect(resolveTemplateRef(PACK, 'denial')).toBe(
				'template:denial-default',
			)
			expect(resolveTemplateRef(PACK, 'acknowledgement')).toBe('')
		})

		it('honours a pack statusLabels override, else humanises the key', () => {
			expect(
				resolveStatusLabel(
					{ statusLabels: { received: 'Ontvangen' } },
					'received',
				),
			).toBe('Ontvangen')
			expect(resolveStatusLabel(null, 'evidence-collection')).toBe(
				'Evidence collection',
			)
		})
	})

	describe('lifecycle transition mirror', () => {
		it('offers finaliseDenial only from denial-drafted', () => {
			const fromDrafted = CASE_LIFECYCLE_TRANSITIONS.filter((tr) =>
				tr.from.includes('denial-drafted'),
			)
			expect(fromDrafted.map((t) => t.action)).toContain('finaliseDenial')
			const fromReceived = CASE_LIFECYCLE_TRANSITIONS.filter((tr) =>
				tr.from.includes('received'),
			)
			expect(fromReceived.map((t) => t.action)).not.toContain('finaliseDenial')
		})
	})

	describe('fetchCases', () => {
		it('lists cases via the objects API and stores the results', async () => {
			axios.get.mockResolvedValueOnce({
				data: { results: [{ uuid: 'c1', status: 'received' }] },
			})
			const store = useAvgStore()
			const cases = await store.fetchCases({ status: 'received' })
			expect(axios.get).toHaveBeenCalledWith(
				expect.stringContaining(
					'/api/objects/data-subject-requests/dataSubjectRequest',
				),
				{ params: { _limit: 200, status: 'received' } },
			)
			expect(cases).toEqual([{ uuid: 'c1', status: 'received' }])
			expect(store.getCases).toHaveLength(1)
		})
	})

	describe('transitionCase', () => {
		it('posts the action to the transition endpoint and stores the returned case', async () => {
			axios.post.mockResolvedValueOnce({
				data: { uuid: 'c1', status: 'verifying' },
			})
			const store = useAvgStore()
			const result = await store.transitionCase('c1', 'assign')
			expect(axios.post).toHaveBeenCalledWith(
				expect.stringContaining('/api/gdpr/cases/c1/transition'),
				{ action: 'assign' },
			)
			expect(result.status).toBe('verifying')
			expect(store.getActiveCase.status).toBe('verifying')
		})

		it('surfaces a server guard refusal as an error and rethrows', async () => {
			axios.post.mockRejectedValueOnce({
				response: { data: { error: 'regulatorReference required' } },
			})
			const store = useAvgStore()
			await expect(
				store.transitionCase('c1', 'finaliseDenial'),
			).rejects.toBeDefined()
			expect(store.getError).toBe('regulatorReference required')
		})
	})

	describe('draftDenial', () => {
		it('records the ground then posts the draftDenial transition', async () => {
			axios.put.mockResolvedValueOnce({
				data: { uuid: 'c1', denialGround: 'third-party-rights' },
			})
			axios.post.mockResolvedValueOnce({
				data: { uuid: 'c1', status: 'denial-drafted' },
			})
			const store = useAvgStore()
			await store.draftDenial('c1', 'third-party-rights')
			expect(axios.put).toHaveBeenCalledWith(
				expect.stringContaining(
					'/api/objects/data-subject-requests/dataSubjectRequest/c1',
				),
				{ denialGround: 'third-party-rights' },
			)
			expect(axios.post).toHaveBeenCalledWith(
				expect.stringContaining('/api/gdpr/cases/c1/transition'),
				{ action: 'draftDenial' },
			)
		})
	})

	describe('generateBundle', () => {
		it('posts to the bundle endpoint and returns the one-time token metadata', async () => {
			axios.post.mockResolvedValueOnce({
				data: { downloadToken: 'YOUR_TOKEN_HERE', signed: true },
			})
			const store = useAvgStore()
			const meta = await store.generateBundle('c1')
			expect(axios.post).toHaveBeenCalledWith(
				expect.stringContaining('/api/gdpr/cases/c1/bundle'),
			)
			expect(meta.downloadToken).toBe('YOUR_TOKEN_HERE')
		})
	})

	describe('verifyIdentity (fail-closed seam)', () => {
		it('returns the seam three-state result faithfully', async () => {
			axios.post.mockResolvedValueOnce({
				data: {
					status: 'needs-more',
					provider: 'or.default.identity-verify.null',
				},
			})
			const store = useAvgStore()
			const result = await store.verifyIdentity('c1')
			expect(axios.post).toHaveBeenCalledWith(
				expect.stringContaining('/api/gdpr/cases/c1/verify-identity'),
			)
			expect(result.status).toBe('needs-more')
		})
	})

	describe('escalateRegulator (fail-closed seam)', () => {
		it('returns the seam performed/refused result faithfully', async () => {
			axios.post.mockResolvedValueOnce({
				data: {
					status: 'refused',
					provider: 'or.default.regulator-escalate.null',
				},
			})
			const store = useAvgStore()
			const result = await store.escalateRegulator('c1')
			expect(result.status).toBe('refused')
		})
	})

	describe('fetchActivePolicyPack', () => {
		it('selects the pack matching the requested jurisdiction', async () => {
			axios.get.mockResolvedValueOnce({
				data: {
					results: [
						{ jurisdiction: 'default', name: 'Default' },
						{ jurisdiction: 'nl-example', name: 'NL' },
					],
				},
			})
			const store = useAvgStore()
			const pack = await store.fetchActivePolicyPack({
				jurisdiction: 'nl-example',
			})
			expect(pack.name).toBe('NL')
			expect(store.getActivePolicyPack.name).toBe('NL')
		})

		it('falls back to the default pack when the jurisdiction has none', async () => {
			axios.get.mockResolvedValueOnce({
				data: {
					results: [{ jurisdiction: 'default', name: 'Default' }],
				},
			})
			const store = useAvgStore()
			const pack = await store.fetchActivePolicyPack({
				jurisdiction: 'unknown',
			})
			expect(pack.name).toBe('Default')
		})
	})
})
