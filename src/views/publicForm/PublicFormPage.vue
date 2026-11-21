<template>
	<main class="public-form-page">
		<NcEmptyContent
			v-if="form === null"
			:name="t('openregister', 'Not available')" />

		<NcEmptyContent
			v-else-if="sent"
			:name="t('openregister', 'Thank you, it was added.')" />

		<CnFormPage
			v-else
			:title="form.title"
			:description="form.description"
			:fields="form.fields"
			:steps="form.steps"
			:submitEndpoint="submitEndpoint"
			@submit="onSent" />
	</main>
</template>

<script>
import { CnFormPage } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcEmptyContent } from '@nextcloud/vue'

/**
 * The form a visitor without an account fills in. The server hands over the
 * form only when it is open to them (`access: anonymous`); otherwise `form`
 * is null and the page says the form is not available. Sending goes through
 * the public form submit route, which creates the record and answers with
 * nothing but its reference, so the visitor sees no other record.
 *
 * @spec openspec/changes/capability-rows-verified-a/specs/form-and-journey-registry/spec.md
 */
export default {
	name: 'PublicFormPage',
	components: { CnFormPage, NcEmptyContent },

	props: {
		/** The form as the server reads it for a visitor, or null when it is not open to them. */
		form: {
			type: Object,
			default: null,
		},
	},

	data() {
		return { sent: false }
	},

	computed: {
		/**
		 * The public submit route of this form.
		 *
		 * @return {string} The URL
		 */
		submitEndpoint() {
			return generateUrl('/apps/openregister/api/forms/{formId}/submit', { formId: this.form?.id ?? '' })
		},
	},

	methods: {
		t,

		/**
		 * Show the confirmation once the form is sent.
		 */
		onSent() {
			this.sent = true
		},
	},
}
</script>

<style scoped>
.public-form-page {
	max-width: 720px;
	margin: 0 auto;
	padding: 24px 16px;
}
</style>
