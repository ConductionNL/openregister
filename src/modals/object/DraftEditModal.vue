<!--
SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<NcDialog
		:name="draft ? draft.name || draft.key : t('openregister', 'New draft')"
		size="normal"
		@closing="$emit('close')">
		<div class="draft-edit">
			<template v-if="!draft">
				<NcTextField
					v-model="key"
					:label="t('openregister', 'Key')"
					:disabled="saving" />
				<NcTextField
					v-model="name"
					:label="t('openregister', 'Name')"
					:disabled="saving" />
			</template>
			<NcTextArea
				v-model="changesText"
				class="draft-edit__changes"
				:label="t('openregister', 'Changes')"
				:helperText="t('openregister', 'The changes must be a JSON object.')"
				:error="invalid"
				:disabled="saving"
				rows="10" />
			<NcNoteCard v-if="error" type="error">
				<p>{{ error }}</p>
			</NcNoteCard>
		</div>

		<template #actions>
			<NcButton :disabled="saving" @click="$emit('close')">
				{{ t('openregister', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="saving || invalid || (!draft && key.trim() === '')"
				@click="save">
				<template #icon>
					<NcLoadingIcon v-if="saving" :size="20" />
				</template>
				{{ t('openregister', 'Save') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import {
	NcButton,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import { createDraft, parseChanges, updateDraft } from '../../components/object-drafts/draftsApi.js'

/**
 * DraftEditModal — create a draft of an object, or add changes to one.
 *
 * The changes are the fields the draft sets, as a JSON object; the server
 * keeps only those that differ from the published object.
 *
 * Spec: openspec/changes/records-draft-versions (task 5.1)
 */
export default {
	name: 'DraftEditModal',

	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcTextArea,
		NcTextField,
	},

	props: {
		/** The object: `{register, schema, id}`. */
		target: {
			type: Object,
			required: true,
		},

		/** The draft being edited, or null for a new one. */
		draft: {
			type: Object,
			default: null,
		},

		/** The draft's current changes, when editing. */
		changes: {
			type: Object,
			default: () => ({}),
		},
	},

	emits: ['close', 'saved'],

	data() {
		return {
			key: '',
			name: '',
			changesText: JSON.stringify(this.changes || {}, null, 2),
			saving: false,
			error: '',
		}
	},

	computed: {
		/**
		 * Whether the typed changes are not a JSON object.
		 *
		 * @spec exclude form validation state, UI plumbing
		 * @return {boolean}
		 */
		invalid() {
			return parseChanges(this.changesText) === null
		},
	},

	methods: {
		t,

		/**
		 * Save: create the draft, or add the changes to it; the server's refusal is shown as it is.
		 *
		 * @spec openspec/changes/records-draft-versions/specs/content-versioning/spec.md#requirement-drafts-are-managed-on-the-object-page
		 * @return {Promise<void>}
		 */
		async save() {
			const changes = parseChanges(this.changesText)
			if (changes === null) {
				return
			}

			this.saving = true
			this.error = ''
			try {
				const saved = this.draft
					? await updateDraft(this.target, this.draft.key, changes)
					: await createDraft(this.target, { key: this.key.trim(), name: this.name.trim(), changes })
				this.$emit('saved', saved)
			} catch (err) {
				this.error = err.response?.data?.error || err.message || ''
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.draft-edit {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
}
</style>
