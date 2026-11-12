<script setup>
import { translate as t } from '@nextcloud/l10n'
import { navigationStore, objectStore } from '../../store/store.js'
</script>

<template>
	<NcDialog
		v-if="navigationStore.modal === 'downloadObject'"
		:name="
			'Download '
			+ (objectName(objectStore.objectItem) || 'Object')
		"
		size="normal"
		noClose>
		<NcNoteCard v-if="success" type="success">
			<p>Object successfully downloaded</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<template #actions>
			<CloseCancelButton :label="success ? 'Close' : 'Cancel'" @click="closeDialog" />
		</template>

		<div v-if="!success" class="formContainer">
			<div class="json-editor">
				<label>Object (JSON)</label>
				<div :class="`codeMirrorContainer ${getTheme()}`">
					<CodeMirror
						v-model="objectItem.object"
						:basic="true"
						placeholder='{ "key": "value" }'
						:dark="getTheme() === 'dark'"
						:linter="jsonParseLinter()"
						:lang="json()"
						:tabSize="2" />
				</div>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import { json, jsonParseLinter } from '@codemirror/lang-json'
import { NcDialog, NcNoteCard } from '@nextcloud/vue'
import CodeMirror from 'vue-codemirror6'
import CloseCancelButton from '../../components/shared/CloseCancelButton.vue'
import { getTheme } from '../../services/getTheme.js'
import { settleConfirmRequest } from '../shared/confirmDialogState.js'
import { objectName as nameOf } from './objectName.js'

export default {
	name: 'DownloadObject',
	components: {
		// components
		NcDialog,
		NcNoteCard,
		CodeMirror,
		// icons
		CloseCancelButton,
	},

	data() {
		return {
			// store
			objectStore,
			navigationStore,
			// state
			success: null,
			loading: false,
			error: false,
			closeModalTimeout: null,
		}
	},

	/**
	 * @spec exclude Vue mounted() hook auto-starting the download when an object is set; modal init plumbing.
	 */
	mounted() {
		if (objectStore.objectItem?.id) {
			this.downloadObject()
		}
	},

	methods: {
		json,
		jsonParseLinter,
		getTheme,
		objectName: nameOf,
		/**
		 * @spec openspec/specs/entity-management-modals/spec.md
		 */
		closeDialog() {
			navigationStore.setModal(false)
			clearTimeout(this.closeModalTimeout)
			this.success = null
			this.loading = false
			this.error = false
		},

		/**
		 * @spec exclude Download handler delegating to objectStore.downloadObject; entity export lives in the store, this is modal orchestration plumbing.
		 */
		downloadObject() {
			this.loading = true
			return settleConfirmRequest(
				this,
				// Started inside the chain, so a store call that throws is shown
				// like any other failure.
				Promise.resolve()
					.then(() => objectStore.downloadObject(objectStore.objectItem))
					.then((response) => ({ response })),
				'An error occurred while downloading the object',
			)
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../styles/modals.scss' as *;

.json-editor {
	position: relative;
	margin-bottom: 2.5rem;
}

.json-editor label {
	display: block;
	margin-bottom: 0.5rem;
	font-weight: bold;
}

/* CodeMirror */
.codeMirrorContainer {
	margin-block-start: 6px;
}

@include code-mirror-container-deep-cm-content;

@include code-mirror-editor;

@include code-mirror-theme;
</style>
