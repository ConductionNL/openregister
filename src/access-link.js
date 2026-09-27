/**
 * Entry for the public page a person with an access link lands on.
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
 */
import { loadState } from '@nextcloud/initial-state'
import { createApp, h } from 'vue'
import AccessLinkPage from './views/accessLink/AccessLinkPage.vue'

const anchor = loadState('openregister', 'accessLinkAnchor', '')

createApp({
	render: () => h(AccessLinkPage, { anchor }),
}).mount('#openregister-access-link')
