/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The lean catalogue keeps everything `t()` and `n()` need from the library's
 * English catalogue and drops the strings that translate to themselves.
 *
 * @spec exclude Unit test of a build tooling script.
 */
const path = require('path')
const loader = require('./lean-library-catalogue-loader.js')
const { withoutIdentityTranslations } = loader

describe('withoutIdentityTranslations', () => {
	const catalogue = {
		translations: { Save: 'Save', 'Task deadline': 'Deadline', Cancel: 'Cancel' },
		plurals: { '{n} item': ['{n} item', '{n} items'] },
	}

	it('drops translations whose value equals their key', () => {
		expect(withoutIdentityTranslations(catalogue).translations).toEqual({ 'Task deadline': 'Deadline' })
	})

	it('keeps every plural, the source of the English plural msgids other languages key on', () => {
		expect(withoutIdentityTranslations(catalogue).plurals).toEqual(catalogue.plurals)
	})

	it('copes with a catalogue that has no translations', () => {
		expect(withoutIdentityTranslations({ plurals: {} })).toEqual({ plurals: {}, translations: {} })
	})
})

describe('leanLibraryCatalogueLoader on the installed English catalogue', () => {
	const file = require.resolve('@conduction/nextcloud-vue/l10n/en.json')
	const original = JSON.parse(require('fs').readFileSync(file, 'utf8'))
	const lean = JSON.parse(loader(JSON.stringify(original)))

	it('renders every English string the same: each dropped entry was identity', () => {
		for (const [source, value] of Object.entries(original.translations)) {
			expect(lean.translations[source] ?? source).toBe(value)
		}
		expect(lean.plurals).toEqual(original.plurals)
	})

	it('is a small fraction of the original', () => {
		expect(JSON.stringify(lean).length).toBeLessThan(JSON.stringify(original).length / 10)
		expect(path.basename(file)).toBe('en.json')
	})
})
