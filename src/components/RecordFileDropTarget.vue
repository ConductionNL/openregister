<!--
  A drop target for record files on a register + schema.

  It hands a dropped CSV, TSV, XLSX or JSON-lines file to the record import and
  holds no parsing, mapping or write logic of its own. Any other file is left
  alone for the surface underneath (file upload) to handle.

  @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md#requirement-the-widget-must-delegate-not-implement

  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<div
		class="recordFileDropTarget"
		:class="{ 'recordFileDropTarget--active': dragging }"
		@dragenter="onDragEnter"
		@dragover="onDragOver"
		@dragleave="onDragLeave"
		@drop="onDrop">
		<slot />
		<div v-if="dragging" class="recordFileDropTarget__notice" role="status">
			{{ t('openregister', 'Drop a CSV, TSV, XLSX or JSON-lines file to import its rows as objects') }}
		</div>
	</div>
</template>

<script>
import { openRecordImport } from '../dialogs/recordImport/openRecordImport.js'
import { t } from '../modals/object/l10n.js'
import { isRecordFile } from '../services/recordImport.js'

/**
 * Whether a drag carries files at all (the names are not readable before the drop).
 *
 * @param {DragEvent} event The drag event.
 * @return {boolean} True when files are dragged.
 */
function carriesFiles(event) {
	return Array.from(event?.dataTransfer?.types ?? []).includes('Files')
}

export default {
	name: 'RecordFileDropTarget',

	props: {
		/** The register the rows go into. */
		register: { type: [String, Number], default: null },
		/** The schema the rows go into. */
		schema: { type: [String, Number], default: null },
	},

	data() {
		return { dragging: false }
	},

	computed: {
		/**
		 * Whether a drop here has a target to import into.
		 *
		 * @return {boolean} True with both a register and a schema.
		 */
		enabled() {
			return Boolean(this.register) && Boolean(this.schema)
		},
	},

	methods: {
		t,

		/**
		 * Show the notice while files are dragged over a target.
		 *
		 * @param {DragEvent} event The drag event.
		 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md#requirement-the-widget-must-delegate-not-implement
		 */
		onDragEnter(event) {
			if (this.enabled && carriesFiles(event)) {
				this.dragging = true
			}
		},

		/**
		 * Accept the drag, so the browser delivers the drop here instead of opening the file.
		 *
		 * @param {DragEvent} event The drag event.
		 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md#requirement-the-widget-must-delegate-not-implement
		 */
		onDragOver(event) {
			if (this.enabled && carriesFiles(event)) {
				event.preventDefault()
				this.dragging = true
			}
		},

		/**
		 * Hide the notice when the drag leaves the target itself, not one of its children.
		 *
		 * @param {DragEvent} event The drag event.
		 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md#requirement-the-widget-must-delegate-not-implement
		 */
		onDragLeave(event) {
			if (!this.$el?.contains?.(event?.relatedTarget)) {
				this.dragging = false
			}
		},

		/**
		 * Hand the first record file of the drop to the record import.
		 *
		 * @param {DragEvent} event The drop event.
		 * @return {boolean} True when a record file was handed over.
		 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md#requirement-the-widget-must-delegate-not-implement
		 */
		onDrop(event) {
			this.dragging = false
			if (!this.enabled) {
				return false
			}

			const file = Array.from(event?.dataTransfer?.files ?? []).find((candidate) => isRecordFile(candidate))
			if (!file) {
				return false
			}

			event.preventDefault()
			event.stopPropagation()
			return openRecordImport({ file, register: this.register, schema: this.schema })
		},
	},
}
</script>

<style scoped>
.recordFileDropTarget {
	position: relative;
}

.recordFileDropTarget--active {
	box-shadow: inset 0 0 0 2px var(--color-primary-element);
	border-radius: var(--border-radius-large);
}

.recordFileDropTarget__notice {
	position: absolute;
	inset: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	padding: calc(var(--default-grid-baseline) * 4);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font-weight: bold;
	text-align: center;
	opacity: 0.95;
	pointer-events: none;
}
</style>
