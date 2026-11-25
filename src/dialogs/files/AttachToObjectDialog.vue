<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
-->

<!--
  The "Add to object" picker: search the objects the caller may save a file
  into, by title, and attach the file to the one they pick.

  The offer is the server's (`/api/files/attach-targets`): only schemas the
  caller may change and that hold files are searched. `targets` narrows it to a
  consuming app's `fileActions.attachTargets`; it never widens it.

  @spec openspec/changes/files-leaf-save-to-object/specs/file-actions/spec.md
-->
<template>
	<NcDialog
		:name="t('openregister', 'Add to object')"
		size="normal"
		class="or-attach-dialog"
		@closing="$emit('close', attached)">
		<NcTextField
			v-model="search"
			:label="t('openregister', 'Search objects')"
			:disabled="saving"
			@update:modelValue="onSearch" />

		<p v-if="error" class="or-attach-dialog__error" role="alert">
			{{ error }}
		</p>

		<NcLoadingIcon v-if="loading" />

		<ul v-else-if="results.length > 0" class="or-attach-dialog__results">
			<li v-for="result in results" :key="result.objectUuid">
				<NcButton
					variant="tertiary"
					alignment="start"
					wide
					:disabled="saving"
					@click="attach(result)">
					<span class="or-attach-dialog__title">{{ result.title }}</span>
					<span class="or-attach-dialog__where">{{ labelOf(result) }}</span>
				</NcButton>
			</li>
		</ul>

		<p v-else-if="searched" class="or-attach-dialog__empty">
			{{ t('openregister', 'No objects found') }}
		</p>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog, NcLoadingIcon, NcTextField } from '@nextcloud/vue'

/** How long typing must pause before the picker searches, in ms. */
export const SEARCH_DELAY = 300

export default {
	name: 'AttachToObjectDialog',
	components: { NcButton, NcDialog, NcLoadingIcon, NcTextField },
	props: {
		/** The Files node id to attach (a file, or a folder of files). */
		nodeId: {
			type: Number,
			required: true,
		},

		/** A consuming app's `fileActions.attachTargets` (schema slugs), or null. */
		targets: {
			type: Array,
			default: null,
		},
	},

	emits: ['close'],
	data() {
		return {
			search: '',
			results: [],
			offered: [],
			loading: false,
			searched: false,
			saving: false,
			error: '',
			attached: null,
			timer: null,
		}
	},

	beforeUnmount() {
		clearTimeout(this.timer)
	},

	methods: {
		t,
		/**
		 * Search after typing pauses.
		 *
		 * @return {void}
		 */
		onSearch() {
			clearTimeout(this.timer)
			this.timer = setTimeout(() => this.find(), SEARCH_DELAY)
		},

		/**
		 * Ask the server which objects match the typed title.
		 *
		 * @return {Promise<void>}
		 */
		async find() {
			const search = this.search.trim()
			if (search === '') {
				this.results = []
				this.searched = false
				return
			}
			this.loading = true
			this.error = ''
			try {
				const params = { search }
				if (Array.isArray(this.targets) && this.targets.length > 0) {
					params.targets = this.targets.join(',')
				}
				const { data } = await axios.get(generateUrl('/apps/openregister/api/files/attach-targets'), { params })
				this.offered = data?.targets ?? []
				this.results = data?.results ?? []
			} catch {
				this.results = []
				this.error = t('openregister', 'The file could not be added to the object.')
			} finally {
				this.loading = false
				this.searched = true
			}
		},

		/**
		 * The schema label a result is shown with.
		 *
		 * @param {{schema: string}} result The result
		 * @return {string}
		 */
		labelOf(result) {
			return this.offered.find((target) => target.schema === result.schema)?.label ?? result.schema
		},

		/**
		 * Attach the node to the picked object, then close.
		 *
		 * @param {{objectUuid: string, schema: string, register: string, title: string}} result The picked object
		 * @return {Promise<void>}
		 */
		async attach(result) {
			this.saving = true
			this.error = ''
			try {
				const url = generateUrl('/apps/openregister/api/objects/{register}/{schema}/{id}/files/attach', {
					register: result.register,
					schema: result.schema,
					id: result.objectUuid,
				})
				const { data } = await axios.post(url, { nodeId: this.nodeId })
				this.attached = { object: result, ...data }
				this.$emit('close', this.attached)
			} catch (e) {
				this.error = e?.response?.data?.error ?? t('openregister', 'The file could not be added to the object.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.or-attach-dialog__results {
	list-style: none;
	margin: 8px 0 0;
	padding: 0;
	max-height: 50vh;
	overflow-y: auto;
}

.or-attach-dialog__title {
	font-weight: bold;
}

.or-attach-dialog__where {
	margin-inline-start: 8px;
	color: var(--color-text-maxcontrast);
}

.or-attach-dialog__error {
	color: var(--color-error-text);
}

.or-attach-dialog__empty {
	color: var(--color-text-maxcontrast);
}
</style>
