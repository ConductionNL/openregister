<template>
	<form class="link-journey-form" @submit.prevent="save">
		<h3>{{ t('openregister', 'Edit') }}</h3>
		<NcLoadingIcon v-if="run === null && !error" :size="32" />
		<template v-else-if="run">
			<NcTextField
				v-for="field in run.declared"
				:key="field"
				v-model="answers[field]"
				:label="field" />
			<NcButton type="submit" variant="primary" :disabled="busy">
				{{ t('openregister', 'Save changes') }}
			</NcButton>
		</template>
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<NcNoteCard v-if="notice" type="success">
			{{ notice }}
		</NcNoteCard>
	</form>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcLoadingIcon, NcNoteCard, NcTextField } from '@nextcloud/vue'
import { passwordOptions, publicLinkUrl } from '../../services/accessLinks.js'
import { refusalMessage } from './refusalMessage.js'

/**
 * The journey an `edit` link opens: one field per field the journey
 * declares, prefilled with the record's current values, saved back through
 * the link. Fields the journey does not declare are never shown or sent.
 *
 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-form-link-may-open-an-existing-record-for-change-req-fjr-008
 */
export default {
	name: 'LinkJourneyForm',
	components: { NcButton, NcLoadingIcon, NcNoteCard, NcTextField },

	props: {
		/** The link's anchor. */
		anchor: {
			type: String,
			required: true,
		},

		/** The password the holder entered, if the link has one. */
		password: {
			type: String,
			default: '',
		},
	},

	emits: ['saved'],

	data() {
		return {
			run: null,
			answers: {},
			busy: false,
			error: '',
			notice: '',
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * @spec openspec/changes/or-form-and-journey-registry/tasks.md#task-6-3
		 * @return {Promise<void>}
		 */
		async load() {
			this.error = ''
			try {
				const response = await axios.get(
					publicLinkUrl(this.anchor, '/journey'),
					passwordOptions(this.password),
				)
				this.run = response.data.run
				this.answers = Object.fromEntries(
					this.run.declared.map((field) => [field, this.run.answers[field] ?? '']),
				)
			} catch (e) {
				this.error = refusalMessage(e)
			}
		},

		/**
		 * @spec openspec/changes/or-form-and-journey-registry/tasks.md#task-6-3
		 * @return {Promise<void>}
		 */
		async save() {
			this.busy = true
			this.error = ''
			this.notice = ''
			try {
				const response = await axios.post(
					publicLinkUrl(this.anchor, '/journey'),
					{ answers: this.answers },
					passwordOptions(this.password),
				)
				this.answers = { ...this.answers, ...response.data.answers }
				this.notice = t('openregister', 'Updated')
				this.$emit('saved', response.data)
			} catch (e) {
				this.error = refusalMessage(e)
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.link-journey-form {
	display: flex;
	flex-direction: column;
	gap: 8px;
	max-width: 480px;
}
</style>
