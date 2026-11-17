/**
 * The grant editor's form: a stored grant read into the form and back, and
 * the reasons a form cannot be saved.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { formFromGrant, GRANTABLE_VERBS, grantFromForm } from './tokenGrant.js'

describe('tokenGrant', () => {
	it('never offers manage', () => {
		expect(GRANTABLE_VERBS).not.toContain('manage')
	})

	it('reads a stored grant into the form and back unchanged', () => {
		const stored = {
			verbs: ['read', 'update'],
			expiresAt: '2027-03-01T23:59:59+00:00',
			registers: ['zaken'],
			schemas: ['case', 'note'],
			match: { supplier: '@self.tokenSubject' },
			rateLimit: 60,
		}

		expect(grantFromForm(formFromGrant(stored))).toEqual({ grant: stored, errors: [] })
	})

	it('starts a new grant at read with no end date', () => {
		expect(formFromGrant(null)).toEqual({ verbs: ['read'], registers: '', schemas: '', match: '', expiresAt: '', rateLimit: '' })
	})

	it('drops a stored manage verb from the form', () => {
		expect(formFromGrant({ verbs: ['manage', 'read'] }).verbs).toEqual(['read'])
	})

	it('leaves out empty axes, so they do not narrow', () => {
		const { grant } = grantFromForm({ verbs: ['read'], registers: ' , ', schemas: '', match: '  ', expiresAt: '2027-01-01', rateLimit: '' })

		expect(grant).toEqual({ verbs: ['read'], expiresAt: '2027-01-01T23:59:59+00:00' })
	})

	it('trims and deduplicates the slugs', () => {
		const { grant } = grantFromForm({ verbs: ['read'], schemas: ' case, note ,case', expiresAt: '2027-01-01' })

		expect(grant.schemas).toEqual(['case', 'note'])
	})

	it('names every reason a form cannot be saved', () => {
		const result = grantFromForm({ verbs: ['manage'], expiresAt: '', match: '[1]', rateLimit: '0' })

		expect(result).toEqual({ grant: null, errors: ['verbs', 'expiresAt', 'match', 'rateLimit'] })
	})

	it('refuses a row condition that is not JSON', () => {
		expect(grantFromForm({ verbs: ['read'], expiresAt: '2027-01-01', match: '{supplier' }).errors).toEqual(['match'])
	})

	it('refuses a fractional rate limit', () => {
		expect(grantFromForm({ verbs: ['read'], expiresAt: '2027-01-01', rateLimit: '1.5' }).errors).toEqual(['rateLimit'])
	})
})
