/**
 * The name an object modal shows for an object: its metadata name, its own
 * name, its metadata title or its id, the first that is set.
 *
 * @spec exclude Presentational label shared by the object modals.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 *
 * @param {object|null} item The object
 * @return {*} The name, or the last candidate when none is set
 */
export function objectName(item) {
	return (
		item?.['@self']?.name
		|| item?.name
		|| item?.['@self']?.title
		|| item?.id
	)
}
