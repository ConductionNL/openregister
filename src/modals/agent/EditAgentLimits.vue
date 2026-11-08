<!--
  Edit which tools one AI agent may use, and which views it may read.

  The tool limit is the agent's own `tools` grant: the chat enforces it, and so
  does the MCP server for a client that names the agent. The view limit is the
  agent's `views`: its chat search only finds objects inside them
  (ContextRetrievalHandler). Over MCP the data scope stays the user's.

  @spec openspec/specs/agent-tool-governance/spec.md#requirement-an-agent-is-held-to-its-tool-grant-on-every-path
  @spec openspec/specs/agent-tool-governance/spec.md#requirement-an-agent-reads-only-the-views-it-is-granted
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<CnFormDialog
		ref="dialog"
		:dialogTitle="t('openregister', 'Limits of {name}', { name: agent.name })"
		:cancelLabel="t('openregister', 'Cancel')"
		:confirmLabel="t('openregister', 'Save')"
		:recoverDraft="false"
		:feedback="false"
		@confirm="save"
		@close="$emit('close')">
		<template #form>
			<NcSelect
				v-model="selectedTools"
				:inputLabel="t('openregister', 'Tools')"
				:options="toolOptions"
				label="label"
				trackBy="id"
				:multiple="true"
				keepOpen
				:disabled="saving" />
			<NcSelect
				v-model="selectedViews"
				:inputLabel="t('openregister', 'Views')"
				:options="viewOptions"
				label="label"
				trackBy="id"
				:multiple="true"
				keepOpen
				:disabled="saving" />
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
		</template>
	</CnFormDialog>
</template>

<script>
import { CnFormDialog } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcNoteCard, NcSelect } from '@nextcloud/vue'

/**
 * Turn stored ids into select options, keeping an id the catalogue no longer lists.
 *
 * @param {Array<string>} ids The stored ids
 * @param {Array<{id: string, label: string}>} options The known options
 * @return {Array<{id: string, label: string}>} The selected options
 */
export function toSelected(ids, options) {
	return (Array.isArray(ids) ? ids : []).map(
		(id) => options.find((o) => o.id === id) || { id, label: id },
	)
}

export default {
	name: 'EditAgentLimits',
	components: { CnFormDialog, NcNoteCard, NcSelect },
	props: {
		agent: { type: Object, required: true },
		toolOptions: { type: Array, default: () => [] },
		viewOptions: { type: Array, default: () => [] },
	},

	emits: ['close', 'saved'],
	data() {
		return {
			selectedTools: toSelected(this.agent.tools, this.toolOptions),
			selectedViews: toSelected(this.agent.views, this.viewOptions),
			saving: false,
			error: '',
		}
	},

	methods: {
		t,
		/**
		 * Store the chosen tools and views on the agent.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/agent-tool-governance/spec.md#requirement-an-agent-is-held-to-its-tool-grant-on-every-path
		 * @spec openspec/specs/agent-tool-governance/spec.md#requirement-an-agent-reads-only-the-views-it-is-granted
		 */
		async save() {
			this.saving = true
			this.error = ''
			try {
				const { data } = await axios.patch(
					generateUrl('/apps/openregister/api/agents/{id}', {
						id: this.agent.id,
					}),
					{
						tools: this.selectedTools.map((o) => o.id),
						views: this.selectedViews.map((o) => o.id),
					},
				)
				this.$emit('saved', data)
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| t('openregister', 'Could not save the limits')
			} finally {
				this.saving = false
				// Back to the form with its own error card, not the result phase.
				this.$refs?.dialog?.setValidationErrors({}, null)
			}
		},
	},
}
</script>
