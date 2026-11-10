/**
 * The Nextcloud components every app-content page shell uses: the content
 * area, its buttons, the loading and empty states and the error note.
 *
 * Spread into a view's `components` so the five registrations live in one
 * place instead of being repeated in every index and detail page.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import {
	NcAppContent,
	NcButton,
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'

export const pageShellComponents = {
	NcAppContent,
	NcButton,
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
}
