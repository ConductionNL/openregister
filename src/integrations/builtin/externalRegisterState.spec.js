/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the external-register card shows for each answer of the leaf endpoint.
 *
 * @spec exclude Unit test of the client-side external register card states.
 */
import { externalRegisterFields, externalRegisterMessage, externalRegisterUrl } from './externalRegisterState.js'

const t = (app, text) => `${app}:${text}`

describe('externalRegisterUrl', () => {
	it('reads the leaf endpoint by the key, encoded, with refresh on demand', () => {
		expect(externalRegisterUrl('external-registers', 'bag-adres', '0363 2000', false))
			.toBe('/apps/openregister/api/external-register/external-registers/bag-adres/0363%202000')
		expect(externalRegisterUrl('external-registers', 'woz-waarde', 42, true))
			.toBe('/apps/openregister/api/external-register/external-registers/woz-waarde/42?refresh=1')
	})

	it('reads nothing without a key, a register or a schema', () => {
		expect(externalRegisterUrl('r', 's', '  ', false)).toBeNull()
		expect(externalRegisterUrl('r', 's', null, false)).toBeNull()
		expect(externalRegisterUrl('', 's', 'k', false)).toBeNull()
		expect(externalRegisterUrl('r', '', 'k', false)).toBeNull()
	})
})

describe('externalRegisterMessage', () => {
	it('says nothing when the record shows', () => {
		expect(externalRegisterMessage('ok', t)).toBe('')
		expect(externalRegisterMessage('', t)).toBe('')
	})

	it('gives every failure its own sentence and only not-found says the register holds nothing', () => {
		const sentences = {
			'no-key': 'openregister:There is nothing to look up yet.',
			'not-found': 'openregister:The register holds no record for this key.',
			'source-absent': 'openregister:This register is not available here yet. An administrator can set it up.',
			'not-configured': 'openregister:This register is not available here yet. An administrator can set it up.',
			refused: 'openregister:The register refused this request.',
			unreachable: 'openregister:The register did not answer. Try again later.',
			'something-new': 'openregister:The register did not answer. Try again later.',
		}
		for (const [state, sentence] of Object.entries(sentences)) {
			expect(externalRegisterMessage(state, t)).toBe(sentence)
		}
		const holdsNothing = Object.keys(sentences).filter((state) => externalRegisterMessage(state, t).includes('holds no record'))
		expect(holdsNothing).toEqual(['not-found'])
	})
})

describe('externalRegisterFields', () => {
	const record = { straat: 'Dam', huisnummer: 1, adres: { postcode: '1012JS' }, actief: true, '@self': { id: 'x' }, _links: {}, tags: ['a'] }

	it('shows the named fields in their order, dot paths included, skipping absent ones', () => {
		expect(externalRegisterFields(record, ['huisnummer', 'adres.postcode', 'missing', 'adres'])).toEqual([
			{ name: 'huisnummer', value: '1' },
			{ name: 'adres.postcode', value: '1012JS' },
			{ name: 'adres', value: '{"postcode":"1012JS"}' },
		])
	})

	it('shows every scalar field when none are named', () => {
		expect(externalRegisterFields(record, [])).toEqual([
			{ name: 'straat', value: 'Dam' },
			{ name: 'huisnummer', value: '1' },
			{ name: 'actief', value: 'true' },
		])
	})

	it('shows nothing without a record', () => {
		expect(externalRegisterFields(null, [])).toEqual([])
		expect(externalRegisterFields([1], [])).toEqual([])
	})
})
