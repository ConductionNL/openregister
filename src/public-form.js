/**
 * Entry for the public page of a form open to visitors without an account.
 *
 * @spec openspec/changes/capability-rows-verified-a/specs/form-and-journey-registry/spec.md
 */
import { loadState } from '@nextcloud/initial-state'
import { createApp, h } from 'vue'
import PublicFormPage from './views/publicForm/PublicFormPage.vue'

const form = loadState('openregister', 'publicForm', null)

createApp({
	render: () => h(PublicFormPage, { form }),
}).mount('#openregister-public-form')
