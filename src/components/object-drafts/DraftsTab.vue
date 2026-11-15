<!--
SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div class="drafts-tab" data-testid="record-drafts-tab">
		<div class="drafts-tab__toolbar">
			<NcButton data-testid="draft-new" @click="openEditor(null)">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('openregister', 'New draft') }}
			</NcButton>
		</div>

		<div v-if="loading" class="drafts-tab__loading">
			<NcLoadingIcon :size="44" />
		</div>

		<TabErrorState
			v-else-if="error"
			:name="t('openregister', 'The drafts could not be read')"
			:description="errorMessage" />

		<NcEmptyContent
			v-else-if="drafts.length === 0"
			:name="t('openregister', 'No drafts')"
			:description="t('openregister', 'A draft holds changes to this object that are not published yet.')">
			<template #icon>
				<FileDocumentEditOutline :size="44" />
			</template>
		</NcEmptyContent>

		<ul v-else class="drafts-tab__list">
			<li
				v-for="draft in drafts"
				:key="draft.key"
				class="drafts-tab__draft"
				:data-testid="'draft-' + draft.key">
				<div class="drafts-tab__head">
					<strong>{{ draft.name || draft.key }}</strong>
					<span class="drafts-tab__meta">
						{{ t('openregister', 'Key') }}: {{ draft.key }}
						· {{ t('openregister', 'Created by') }}: {{ draft.createdBy || '—' }}
					</span>
					<span class="drafts-tab__meta">
						{{ t('openregister', 'Changed fields') }}:
						{{ (draft.changedFields || []).join(', ') || '—' }}
					</span>
				</div>

				<div class="drafts-tab__actions">
					<NcButton :disabled="busy === draft.key" @click="openEditor(draft)">
						{{ t('openregister', 'Edit') }}
					</NcButton>
					<NcButton :disabled="busy === draft.key" @click="toggleCompare(draft)">
						{{ t('openregister', 'Compare') }}
					</NcButton>
					<NcButton
						v-if="previewTemplate"
						:disabled="busy === draft.key"
						data-testid="draft-preview"
						@click="preview(draft)">
						<template #icon>
							<OpenInNew :size="20" />
						</template>
						{{ t('openregister', 'Preview') }}
					</NcButton>
					<NcButton
						variant="primary"
						:disabled="busy === draft.key"
						data-testid="draft-promote"
						@click="promote(draft, false)">
						{{ t('openregister', 'Promote') }}
					</NcButton>
					<NcButton
						variant="error"
						:disabled="busy === draft.key"
						@click="confirmDiscard = draft.key">
						{{ t('openregister', 'Discard') }}
					</NcButton>
				</div>

				<NcNoteCard v-if="confirmDiscard === draft.key" type="warning">
					<p>{{ t('openregister', 'Discard this draft? The published object stays as it is.') }}</p>
					<div class="drafts-tab__actions">
						<NcButton @click="confirmDiscard = ''">
							{{ t('openregister', 'Cancel') }}
						</NcButton>
						<NcButton variant="error" data-testid="draft-discard-confirm" @click="discard(draft)">
							{{ t('openregister', 'Discard') }}
						</NcButton>
					</div>
				</NcNoteCard>

				<NcNoteCard v-if="conflicts[draft.key]" type="error">
					<p>{{ t('openregister', 'Promoting would overwrite changes published since this draft was made.') }}</p>
					<ul>
						<li v-for="conflict in conflicts[draft.key]" :key="conflict.field">
							{{ conflict.field }}
						</li>
					</ul>
					<NcButton v-if="isAdmin" variant="error" @click="promote(draft, true)">
						{{ t('openregister', 'Promote anyway') }}
					</NcButton>
				</NcNoteCard>

				<NcNoteCard v-if="failures[draft.key]" type="error">
					<p>{{ failures[draft.key] }}</p>
				</NcNoteCard>

				<table v-if="comparisons[draft.key]" class="drafts-tab__compare">
					<thead>
						<tr>
							<th>{{ t('openregister', 'Field') }}</th>
							<th>{{ t('openregister', 'Published') }}</th>
							<th>{{ t('openregister', 'Draft') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in comparisons[draft.key]" :key="row.field">
							<td>{{ row.field }}</td>
							<td>{{ row.published }}</td>
							<td>{{ row.draft }}</td>
						</tr>
					</tbody>
				</table>
			</li>
		</ul>

		<DraftEditModal
			v-if="editor"
			:target="target"
			:draft="editor.draft"
			:changes="editor.changes"
			@close="editor = null"
			@saved="onSaved" />
	</div>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import FileDocumentEditOutline from 'vue-material-design-icons/FileDocumentEditOutline.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import DraftEditModal from '../../modals/object/DraftEditModal.vue'
import TabErrorState from '../shared/TabErrorState.vue'
import { relationTab } from '../object-relations/relationTab.js'
import {
	compareRows,
	discardDraft,
	listDrafts,
	previewDraft,
	promoteDraft,
	readDraft,
	schemaPreviewUrl,
} from './draftsApi.js'

/**
 * DraftsTab — the named drafts of an object beside its published version.
 *
 * Lists the drafts the caller may see with their key, name, creator and
 * changed fields, and lets the caller edit, compare, preview, promote and
 * discard one. Promoting replaces the published version; a conflict with a
 * change published since the draft was made lists the fields, and only an
 * administrator may promote anyway. Preview appears when the schema declares
 * `previewUrl` and opens the filled URL in a new tab.
 *
 * Spec: openspec/changes/records-draft-versions (tasks 5.1, 5.2)
 */
export default {
	name: 'DraftsTab',

	components: {
		DraftEditModal,
		FileDocumentEditOutline,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		OpenInNew,
		Plus,
		TabErrorState,
	},

	mixins: [relationTab],

	props: {
		/** The published object, for the compare. */
		published: {
			type: Object,
			default: () => ({}),
		},
	},

	emits: ['promoted'],

	data() {
		return {
			loading: false,
			drafts: [],
			previewTemplate: null,
			busy: '',
			confirmDiscard: '',
			editor: null,
			comparisons: {},
			conflicts: {},
			failures: {},
		}
	},

	computed: {
		/**
		 * The object the versions routes address.
		 *
		 * @spec exclude route parameters, UI plumbing
		 * @return {{register: (string|number), schema: (string|number), id: string}}
		 */
		target() {
			return { register: this.register, schema: this.schema, id: this.objectId }
		},

		/**
		 * Whether the signed-in user is an administrator (offered Promote anyway).
		 *
		 * @spec exclude display gate only; the server refuses a non-admin force
		 * @return {boolean}
		 */
		isAdmin() {
			return getCurrentUser()?.isAdmin === true
		},
	},

	methods: {
		/**
		 * Read the drafts and the schema's preview template.
		 *
		 * @spec openspec/changes/records-draft-versions/specs/content-versioning/spec.md#requirement-drafts-are-managed-on-the-object-page
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.clearFailure()
			try {
				const [drafts, template] = await Promise.all([
					listDrafts(this.target),
					schemaPreviewUrl(this.schema),
				])
				this.drafts = drafts
				this.previewTemplate = template
			} catch (err) {
				this.showFailure(err)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Open the draft form: empty for a new draft, with the draft's changes otherwise.
		 *
		 * @param {object|null} draft The draft, or null.
		 * @spec openspec/changes/records-draft-versions
		 * @return {Promise<void>}
		 */
		async openEditor(draft) {
			if (!draft) {
				this.editor = { draft: null, changes: {} }
				return
			}

			const merged = await this.run(draft, () => readDraft(this.target, draft.key))
			if (merged === undefined) {
				return
			}

			const changes = {}
			for (const field of draft.changedFields || []) {
				changes[field] = merged[field]
			}

			this.editor = { draft, changes }
		},

		/**
		 * After a save: close the form and read the drafts again.
		 *
		 * @spec exclude UI plumbing after a save
		 * @return {Promise<void>}
		 */
		async onSaved() {
			this.editor = null
			this.comparisons = {}
			await this.load()
		},

		/**
		 * Show or hide the compare of a draft against the published version.
		 *
		 * @param {object} draft The draft.
		 * @spec openspec/changes/records-draft-versions/specs/content-versioning/spec.md#requirement-drafts-are-managed-on-the-object-page
		 * @return {Promise<void>}
		 */
		async toggleCompare(draft) {
			if (this.comparisons[draft.key]) {
				const { [draft.key]: _closed, ...rest } = this.comparisons
				this.comparisons = rest
				return
			}

			const merged = await this.run(draft, () => readDraft(this.target, draft.key))
			if (merged !== undefined) {
				this.comparisons = { ...this.comparisons, [draft.key]: compareRows(draft.changedFields, this.published, merged) }
			}
		},

		/**
		 * Promote a draft. A conflict lists its fields; on success the draft leaves the list.
		 *
		 * @param {object} draft The draft.
		 * @param {boolean} force Overwrite the conflicting fields (administrators).
		 * @spec openspec/changes/records-draft-versions/specs/content-versioning/spec.md#requirement-drafts-are-managed-on-the-object-page
		 * @return {Promise<void>}
		 */
		async promote(draft, force) {
			this.conflicts = { ...this.conflicts, [draft.key]: null }
			const saved = await this.run(draft, () => promoteDraft(this.target, draft.key, force), (err) => {
				if (err.response?.status === 409) {
					this.conflicts = { ...this.conflicts, [draft.key]: err.response.data?.conflicts || [] }
					return true
				}

				return false
			})
			if (saved !== undefined) {
				this.drafts = this.drafts.filter((d) => d.key !== draft.key)
				this.$emit('promoted', saved)
			}
		},

		/**
		 * Discard a draft after the confirmation.
		 *
		 * @param {object} draft The draft.
		 * @spec openspec/changes/records-draft-versions/specs/content-versioning/spec.md#requirement-drafts-are-managed-on-the-object-page
		 * @return {Promise<void>}
		 */
		async discard(draft) {
			this.confirmDiscard = ''
			const done = await this.run(draft, async () => {
				await discardDraft(this.target, draft.key)
				return true
			})
			if (done !== undefined) {
				this.drafts = this.drafts.filter((d) => d.key !== draft.key)
			}
		},

		/**
		 * Make a preview link and open the filled preview URL in a new tab.
		 *
		 * @param {object} draft The draft.
		 * @spec openspec/changes/records-draft-versions/specs/content-versioning/spec.md#requirement-a-draft-can-be-previewed-on-an-outside-site-through-an-expiring-link
		 * @return {Promise<void>}
		 */
		async preview(draft) {
			const link = await this.run(draft, () => previewDraft(this.target, draft.key))
			if (link?.previewUrl) {
				window.open(link.previewUrl, '_blank', 'noopener')
			}
		},

		/**
		 * Run one call for a draft: mark it busy, keep its failure message.
		 *
		 * @param {object} draft The draft.
		 * @param {() => Promise<(object|boolean)>} call The call.
		 * @param {(err: Error) => boolean} [handled] Returns true for an error it dealt with itself.
		 * @spec exclude per-draft busy and error state, UI plumbing
		 * @return {Promise<(object|boolean|undefined)>} The call's answer, or undefined on a failure.
		 */
		async run(draft, call, handled = () => false) {
			this.busy = draft.key
			this.failures = { ...this.failures, [draft.key]: '' }
			try {
				return await call()
			} catch (err) {
				if (!handled(err)) {
					this.failures = { ...this.failures, [draft.key]: err.response?.data?.error || err.message || '' }
				}

				return undefined
			} finally {
				this.busy = ''
			}
		},
	},
}
</script>

<style scoped>
.drafts-tab {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
	padding: calc(var(--default-grid-baseline) * 2) 0;
}

.drafts-tab__toolbar,
.drafts-tab__actions {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 2);
}

.drafts-tab__loading {
	display: flex;
	justify-content: center;
}

.drafts-tab__list {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.drafts-tab__draft {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 3);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.drafts-tab__head {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.drafts-tab__meta {
	color: var(--color-text-maxcontrast);
}

.drafts-tab__compare {
	width: 100%;
	border-collapse: collapse;
}

.drafts-tab__compare th,
.drafts-tab__compare td {
	padding: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 2);
	border-bottom: 1px solid var(--color-border);
	text-align: start;
	vertical-align: top;
}
</style>
