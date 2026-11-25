/**
 * The cards of a gallery view: each record with its cover thumbnail, and the
 * schema CnObjectCard reads the card title and cover from.
 *
 * @spec openspec/changes/records-gallery-view/specs/saved-search-views/spec.md#requirement-the-search-page-renders-a-gallery-view-as-cards
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { generateUrl } from '@nextcloud/router'

/** The record key a card's cover URL is placed under; CnObjectCard reads it as the image field. */
export const COVER_KEY = '_galleryCover'

/** Thumbnail edge in pixels per card size. */
const THUMBNAIL_SIZE = { small: 128, medium: 256, large: 512 }

/**
 * The card size of a gallery: small, medium or large; medium when unset or unknown.
 *
 * @param {object} gallery The view's `presentation.gallery`.
 * @return {string}
 */
export function cardSize(gallery) {
	const size = gallery?.cardSize
	return Object.hasOwn(THUMBNAIL_SIZE, size) ? size : 'medium'
}

/**
 * A thumbnail of a file, not the full file.
 *
 * A file attached to a record lives in OpenRegister's own storage, where the
 * person looking has no mount, so Nextcloud's preview service answers 404 for
 * it. With the record known, the thumbnail comes from the record's own file
 * preview route, which checks the reader's access to the record; without it,
 * from Nextcloud's preview service.
 *
 * @param {number|string} fileId The file id.
 * @param {string} size The card size.
 * @param {object|null} record The record the file is attached to, when known.
 * @return {string}
 */
export function previewUrl(fileId, size, record = null) {
	const edge = THUMBNAIL_SIZE[size] ?? THUMBNAIL_SIZE.medium
	const self = record?.['@self'] ?? {}
	if (self.register && self.schema && (self.id ?? record?.id)) {
		return generateUrl(
			'/apps/openregister/api/objects/{register}/{schema}/{id}/files/{fileId}/preview?width={edge}&height={edge}',
			{ register: self.register, schema: self.schema, id: self.id ?? record.id, fileId, edge },
		)
	}
	return generateUrl('/core/preview?fileId={fileId}&x={edge}&y={edge}&a=1', { fileId, edge })
}

/**
 * The cover URL of a cover field value: a file (or the first of a list of
 * files) becomes a preview thumbnail; a file known only by its URL, or an
 * image URL string, is used as it is. Anything else has no cover.
 *
 * @param {*} value The cover field's value on the record.
 * @param {string} size The card size.
 * @param {object|null} record The record the value belongs to, when known.
 * @return {string|null}
 */
export function coverUrl(value, size, record = null) {
	if (Array.isArray(value)) {
		for (const item of value) {
			const url = coverUrl(item, size, record)
			if (url) return url
		}
		return null
	}
	if (typeof value === 'number' || (typeof value === 'string' && /^\d+$/.test(value))) {
		return previewUrl(value, size, record)
	}
	if (typeof value === 'string') {
		return value.trim() === '' ? null : value
	}
	if (value && typeof value === 'object') {
		if (value.id !== undefined && value.id !== null && /^\d+$/.test(String(value.id))) {
			return previewUrl(value.id, size, record)
		}
		return value.downloadUrl || value.accessUrl || value.url || null
	}
	return null
}

/**
 * The record's cover: the cover field's file, or the rendered `@self.image`
 * when the view names no cover field or names the schema's own image field.
 *
 * @param {object} record The rendered record.
 * @param {object} gallery The view's `presentation.gallery`.
 * @param {object} schema The view's schema.
 * @return {string|null}
 */
export function recordCover(record, gallery, schema) {
	const field = gallery?.coverField
	const imageField = schema?.configuration?.objectImageField
	const size = cardSize(gallery)
	if (field && record?.[field] !== undefined && record[field] !== null) {
		const url = coverUrl(record[field], size, record)
		if (url) return url
	}
	if (!field || field === imageField) {
		return coverUrl(record?.['@self']?.image, size, record)
	}
	return null
}

/**
 * The records as cards: each with its cover URL under COVER_KEY, absent when it has none.
 *
 * @param {Array<object>} records The rendered records.
 * @param {object} gallery The view's `presentation.gallery`.
 * @param {object} schema The view's schema.
 * @return {Array<object>}
 */
export function galleryCards(records, gallery, schema) {
	if (!Array.isArray(records)) return []
	return records.map((record) => {
		const cover = recordCover(record, gallery, schema)
		const card = { ...record }
		delete card[COVER_KEY]
		if (cover) card[COVER_KEY] = cover
		return card
	})
}

/**
 * The schema the cards are drawn with: the view's title field as the card
 * title and the cover as the card image, the rest of the schema unchanged.
 *
 * @param {object} schema The view's schema.
 * @param {object} gallery The view's `presentation.gallery`.
 * @return {object}
 */
export function gallerySchema(schema, gallery) {
	const base = schema || {}
	const configuration = { ...(base.configuration || {}), objectImageField: COVER_KEY }
	if (gallery?.titleField) configuration.objectNameField = gallery.titleField
	return { ...base, configuration }
}

/**
 * The fields each card lists: the view's card fields, at most four.
 *
 * @param {object} gallery The view's `presentation.gallery`.
 * @return {Array<string>}
 */
export function galleryCardFields(gallery) {
	const fields = Array.isArray(gallery?.cardFields) ? gallery.cardFields : []
	return fields.filter((field) => typeof field === 'string' && field !== '').slice(0, 4)
}

/**
 * The record behind a card: the card without the cover it was given for display.
 *
 * A card is the record plus COVER_KEY. Handing the card itself to the record
 * dialog, a copy or a template would show the cover as a property and write it
 * into the record on a save.
 *
 * @spec openspec/changes/records-gallery-view/specs/saved-search-views/spec.md#requirement-the-search-page-renders-a-gallery-view-as-cards
 * @param {object} card A card from galleryCards().
 * @return {object}
 */
export function recordOfCard(card) {
	if (!card || typeof card !== 'object') return card
	const record = { ...card }
	delete record[COVER_KEY]
	return record
}
