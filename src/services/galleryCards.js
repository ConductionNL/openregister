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
 * A thumbnail from Nextcloud's preview service for a file id, not the full file.
 *
 * @param {number|string} fileId The file id.
 * @param {string} size The card size.
 * @return {string}
 */
export function previewUrl(fileId, size) {
	const edge = THUMBNAIL_SIZE[size] ?? THUMBNAIL_SIZE.medium
	return generateUrl('/core/preview?fileId={fileId}&x={edge}&y={edge}&a=1', { fileId, edge })
}

/**
 * The cover URL of a cover field value: a file (or the first of a list of
 * files) becomes a preview thumbnail; a file known only by its URL, or an
 * image URL string, is used as it is. Anything else has no cover.
 *
 * @param {*} value The cover field's value on the record.
 * @param {string} size The card size.
 * @return {string|null}
 */
export function coverUrl(value, size) {
	if (Array.isArray(value)) {
		for (const item of value) {
			const url = coverUrl(item, size)
			if (url) return url
		}
		return null
	}
	if (typeof value === 'number' || (typeof value === 'string' && /^\d+$/.test(value))) {
		return previewUrl(value, size)
	}
	if (typeof value === 'string') {
		return value.trim() === '' ? null : value
	}
	if (value && typeof value === 'object') {
		if (value.id !== undefined && value.id !== null && /^\d+$/.test(String(value.id))) {
			return previewUrl(value.id, size)
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
		const url = coverUrl(record[field], size)
		if (url) return url
	}
	if (!field || field === imageField) {
		return coverUrl(record?.['@self']?.image, size)
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
