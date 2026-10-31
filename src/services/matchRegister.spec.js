/**
 * Unit tests for the register identifier matcher.
 *
 * The rule under test is the one `RegisterMapper::find()` has always applied
 * and the frontend did not: a register answers to its numeric id, its uuid or
 * its slug. `/registers/:id` compared the route param against the id alone,
 * so a link naming the slug matched nothing, and the page rendered an empty
 * schema list rather than an error. That failure mode is why the negative
 * cases below matter as much as the positive ones: "no match" and "matched a
 * register with no schemas" look identical on screen.
 */

import { findRegisterByIdentifier, registerAnswersTo } from './matchRegister.js'

/** The register a cross-app link would name by slug. */
const DOSSIQ = {
	id: 7,
	uuid: '9E5B2C1A-4D3F-4A8B-9C0E-1F2A3B4C5D6E',
	slug: 'dossiq',
	title: 'Dossiq Case Management Register',
}

/** A second register, so a match is a match and not the only row present. */
const PUBLICATIONS = { id: 12, uuid: 'aaaa-bbbb', slug: 'publications' }

const REGISTERS = [PUBLICATIONS, DOSSIQ]

describe('registerAnswersTo', () => {
	it('matches the numeric id, as a number or as a route-param string', () => {
		expect(registerAnswersTo(DOSSIQ, 7)).toBe(true)
		expect(registerAnswersTo(DOSSIQ, '7')).toBe(true)
	})

	it('matches the slug, which is the only spelling another app can write', () => {
		expect(registerAnswersTo(DOSSIQ, 'dossiq')).toBe(true)
	})

	it('matches the uuid', () => {
		expect(registerAnswersTo(DOSSIQ, DOSSIQ.uuid)).toBe(true)
	})

	it('ignores case on uuid and slug, as LOWER(slug) does in the mapper', () => {
		expect(registerAnswersTo(DOSSIQ, 'DOSSIQ')).toBe(true)
		expect(registerAnswersTo(DOSSIQ, DOSSIQ.uuid.toLowerCase())).toBe(true)
	})

	it('refuses another register', () => {
		expect(registerAnswersTo(PUBLICATIONS, 'dossiq')).toBe(false)
		expect(registerAnswersTo(DOSSIQ, 12)).toBe(false)
	})

	it('refuses an empty or missing identifier rather than matching everything', () => {
		// A register with no slug carries `slug: null`, and `String(null)` is
		// `"null"`, not `""`. An empty identifier compared loosely would match
		// every register that happens to be missing the same field.
		expect(registerAnswersTo(DOSSIQ, '')).toBe(false)
		expect(registerAnswersTo(DOSSIQ, null)).toBe(false)
		expect(registerAnswersTo(DOSSIQ, undefined)).toBe(false)
		expect(registerAnswersTo(null, 'dossiq')).toBe(false)
	})

	it('does not match a register whose slug is absent against a missing one', () => {
		const unslugged = { id: 3, uuid: null, slug: null }
		expect(registerAnswersTo(unslugged, 'null')).toBe(false)
		expect(registerAnswersTo(unslugged, 3)).toBe(true)
	})
})

describe('findRegisterByIdentifier', () => {
	it('finds the register a cross-app link names by slug', () => {
		expect(findRegisterByIdentifier(REGISTERS, 'dossiq')).toBe(DOSSIQ)
	})

	it('finds it by id and by uuid too', () => {
		expect(findRegisterByIdentifier(REGISTERS, '7')).toBe(DOSSIQ)
		expect(findRegisterByIdentifier(REGISTERS, DOSSIQ.uuid)).toBe(DOSSIQ)
	})

	it('returns undefined for a name nothing answers to', () => {
		expect(findRegisterByIdentifier(REGISTERS, 'nosuchregister')).toBeUndefined()
	})

	it('survives a store that has not loaded yet', () => {
		expect(findRegisterByIdentifier(undefined, 'dossiq')).toBeUndefined()
		expect(findRegisterByIdentifier([], 'dossiq')).toBeUndefined()
	})
})
