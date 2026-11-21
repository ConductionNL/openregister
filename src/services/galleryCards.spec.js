/**
 * @spec openspec/changes/records-gallery-view/specs/saved-search-views/spec.md#requirement-the-search-page-renders-a-gallery-view-as-cards
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import {
	cardSize,
	COVER_KEY,
	coverUrl,
	galleryCardFields,
	galleryCards,
	gallerySchema,
	previewUrl,
	recordCover,
} from './galleryCards.js'

jest.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) =>
		'/index.php' + path.replace(/{(\w+)}/g, (_, key) => params[key]),
}))

const schema = {
	properties: { adres: { type: 'string' }, foto: { type: 'file' }, plaatje: { type: 'string', format: 'image' } },
	configuration: { objectImageField: 'plaatje', objectNameField: 'naam' },
}

describe('gallery cards', () => {
	it('takes the thumbnail from the preview service, sized by the card size', () => {
		expect(previewUrl(42, 'small')).toBe('/index.php/core/preview?fileId=42&x=128&y=128&a=1')
		expect(previewUrl('7', 'large')).toBe('/index.php/core/preview?fileId=7&x=512&y=512&a=1')
		expect(previewUrl(7, 'huge')).toBe('/index.php/core/preview?fileId=7&x=256&y=256&a=1')
	})

	it('reads the card size, medium when unset or unknown', () => {
		expect(cardSize({ cardSize: 'large' })).toBe('large')
		expect(cardSize({ cardSize: 'huge' })).toBe('medium')
		expect(cardSize(undefined)).toBe('medium')
	})

	it('makes a cover of a file, the first usable file of a list, a file URL or an image URL', () => {
		expect(coverUrl({ id: 11, downloadUrl: '/full' }, 'medium')).toBe('/index.php/core/preview?fileId=11&x=256&y=256&a=1')
		expect(coverUrl([null, { title: 'x' }, { id: 12 }], 'medium')).toBe('/index.php/core/preview?fileId=12&x=256&y=256&a=1')
		expect(coverUrl({ downloadUrl: '/dl/1' }, 'medium')).toBe('/dl/1')
		expect(coverUrl({ accessUrl: '/share/1' }, 'medium')).toBe('/share/1')
		expect(coverUrl('https://example.org/pand.jpg', 'medium')).toBe('https://example.org/pand.jpg')
		expect(coverUrl(13, 'small')).toBe('/index.php/core/preview?fileId=13&x=128&y=128&a=1')
		expect(coverUrl('', 'medium')).toBeNull()
		expect(coverUrl({}, 'medium')).toBeNull()
		expect(coverUrl([], 'medium')).toBeNull()
		expect(coverUrl(true, 'medium')).toBeNull()
	})

	it('uses the rendered @self.image when the view names no cover or the schema image field', () => {
		const record = { foto: null, '@self': { image: '/img/1.png' } }
		expect(recordCover(record, {}, schema)).toBe('/img/1.png')
		expect(recordCover({ '@self': { image: '/img/2.png' } }, { coverField: 'plaatje' }, schema)).toBe('/img/2.png')
		expect(recordCover(record, { coverField: 'foto' }, schema)).toBeNull()
		expect(recordCover({ foto: { id: 5 }, '@self': { image: '/img/3.png' } }, { coverField: 'foto' }, schema))
			.toBe('/index.php/core/preview?fileId=5&x=256&y=256&a=1')
	})

	it('puts each cover under the cover key and leaves a card without one bare', () => {
		const cards = galleryCards(
			[{ id: 'a', foto: { id: 5 } }, { id: 'b', foto: null, [COVER_KEY]: 'stale' }],
			{ coverField: 'foto', cardSize: 'small' },
			schema,
		)
		expect(cards[0][COVER_KEY]).toBe('/index.php/core/preview?fileId=5&x=128&y=128&a=1')
		expect(cards[1]).toEqual({ id: 'b', foto: null })
		expect(galleryCards(null, {}, schema)).toEqual([])
	})

	it('draws the cards with the view title field and the cover as image, the schema otherwise kept', () => {
		const drawn = gallerySchema(schema, { titleField: 'adres' })
		expect(drawn.configuration).toEqual({ objectImageField: COVER_KEY, objectNameField: 'adres' })
		expect(drawn.properties).toBe(schema.properties)
		expect(schema.configuration.objectImageField).toBe('plaatje')
		expect(gallerySchema(null, {}).configuration).toEqual({ objectImageField: COVER_KEY })
		expect(gallerySchema(schema, {}).configuration.objectNameField).toBe('naam')
	})

	it('lists at most four card fields, strings only', () => {
		expect(galleryCardFields({ cardFields: ['a', '', 3, 'b', 'c', 'd', 'e'] })).toEqual(['a', 'b', 'c', 'd'])
		expect(galleryCardFields({})).toEqual([])
	})
})
