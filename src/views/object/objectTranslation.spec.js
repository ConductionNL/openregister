/**
 * The Translate action's rules (ai-translation-with-a-glossary task 3.2):
 * when it is offered, and how a bulk-translate answer lands on the object.
 *
 * @spec openspec/changes/ai-translation-with-a-glossary/tasks.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { canTranslate, draftedAndSkipped, registerLanguages, translatableProperties, withTranslations } from './objectTranslation.js'

describe('objectTranslation', () => {
	it('reads the translatable properties in schema order', () => {
		expect(translatableProperties({ properties: { title: { translatable: true }, code: {}, body: { translatable: true } } })).toEqual(['title', 'body'])
		expect(translatableProperties({ properties: { flag: { translatable: 'yes' } } })).toEqual([])
		expect(translatableProperties({ properties: [] })).toEqual([])
		expect(translatableProperties(null)).toEqual([])
	})

	it('reads the register languages without blanks or repeats', () => {
		expect(registerLanguages({ languages: ['nl', 'en', 'nl', '', null] })).toEqual(['nl', 'en'])
		expect(registerLanguages({ languages: null })).toEqual([])
		expect(registerLanguages(undefined)).toEqual([])
	})

	it('offers the action only to an administrator with two languages and a translatable property', () => {
		expect(canTranslate({ isAdmin: true, languages: ['nl', 'en'], translatable: ['title'] })).toBe(true)
		expect(canTranslate({ isAdmin: false, languages: ['nl', 'en'], translatable: ['title'] })).toBe(false)
		expect(canTranslate({ isAdmin: true, languages: ['nl'], translatable: ['title'] })).toBe(false)
		expect(canTranslate({ isAdmin: true, languages: ['nl', 'en'], translatable: [] })).toBe(false)
	})

	it('puts each translation in its target slot and keeps the other slots and fields', () => {
		const object = { id: 'u', title: { nl: 'Vergunning', de: 'Genehmigung' }, body: 'Tekst', code: 'A1' }
		const updated = withTranslations(object, { to: 'en', translated: { title: 'Permit', body: 'Text' } })

		expect(updated).toEqual({ id: 'u', title: { nl: 'Vergunning', de: 'Genehmigung', en: 'Permit' }, body: { nl: 'Tekst', en: 'Text' }, code: 'A1' })
		expect(object.title).toEqual({ nl: 'Vergunning', de: 'Genehmigung' })
	})

	it('gives an empty field only its new slot', () => {
		expect(withTranslations({ title: null }, { to: 'en', translated: { title: 'Permit' } })).toEqual({ title: { en: 'Permit' } })
	})

	it('has nothing to save when nothing was translated', () => {
		expect(withTranslations({ title: 'x' }, { to: 'en', translated: {} })).toBeNull()
		expect(withTranslations({ title: 'x' }, { translated: { title: 'y' } })).toBeNull()
		expect(withTranslations(null, { to: 'en', translated: { title: 'y' } })).toBeNull()
	})

	it('splits the drafts from the fields that were not translated', () => {
		expect(draftedAndSkipped({ skipped: { title: 'glossary-terms-missing: omgevingsvergunning', body: 'encrypted-at-rest' } })).toEqual({
			drafted: [{ property: 'title', terms: 'omgevingsvergunning' }],
			skipped: [{ property: 'body', reason: 'encrypted-at-rest' }],
		})
		expect(draftedAndSkipped(null)).toEqual({ drafted: [], skipped: [] })
	})
})
