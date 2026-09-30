<!--
  Edit which tools one AI agent may use.

  The limit is the agent's own `tools` grant: the chat enforces it, and so does
  the MCP server for a client that names the agent. Views are not offered here:
  the chat does not apply an agent's views yet (ContextRetrievalHandler leaves
  them as a TODO), and a limit the screen shows but nothing applies would lie.
  Change ai-agent-view-limits builds that half.

  @spec openspec/specs/agent-tool-governance/spec.md#requirement-an-agent-is-held-to-its-tool-grant-on-every-path
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<NcModal size="normal" @close="$emit('close')">
		<div class="agent-limits">
			<h2>
				{{ t('openregister', 'Limits of {name}', { name: agent.name }) }}
			</h2>
			<NcSelect
				v-model="selectedTools"
				:inputLabel="t('openregister', 'Tools')"
				:options="toolOptions"
				label="label"
				trackBy="id"
				:multiple="true"
				keepOpen
				:disabled="saving" />
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<div class="agent-limits__actions">
				<NcButton :disabled="saving" @click="$emit('close')">
					{{ t('openregister', 'Cancel') }}
				</NcButton>
				<NcButton variant="primary" :disabled="saving" @click="save">
					{{ t('openregister', 'Save') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcModal, NcNoteCard, NcSelect } from '@nextcloud/vue'

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
	components: { NcButton, NcModal, NcNoteCard, NcSelect },
	props: {
		agent: { type: Object, required: true },
		toolOptions: { type: Array, default: () => [] },
	},

	emits: ['close', 'saved'],
	data() {
		return {
			selectedTools: toSelected(this.agent.tools, this.toolOptions),
			saving: false,
			error: '',
		}
	},

	methods: {
		t,
		/**
		 * Store the chosen tools on the agent.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/agent-tool-governance/spec.md#requirement-an-agent-is-held-to-its-tool-grant-on-every-path
		 */
		async save() {
			this.saving = true
			this.error = ''
			try {
				const { data } = await axios.patch(
					generateUrl('/apps/openregister/api/agents/{id}', {
						id: this.agent.id,
					}),
					{ tools: this.selectedTools.map((o) => o.id) },
				)
				this.$emit('saved', data)
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| t('openregister', 'Could not save the limits')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.agent-limits {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 24px;
}

.agent-limits__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}
</style>
