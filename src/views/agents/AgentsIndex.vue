<!--
  The agents screen: every AI agent with the tools it may use.

  @spec openspec/specs/agent-tool-governance/spec.md#requirement-an-agent-is-held-to-its-tool-grant-on-every-path
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<NcAppContent>
		<div class="agents">
			<h2>{{ t('openregister', 'Agents') }}</h2>
			<p class="agents__intro">
				{{
					t(
						'openregister',
						'Choose which tools each AI agent may use. Chat and MCP clients that name the agent get the same limits.',
					)
				}}
			</p>
			<NcLoadingIcon v-if="loading" />
			<NcNoteCard v-else-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<NcEmptyContent
				v-else-if="agents.length === 0"
				:name="t('openregister', 'No agents yet')" />
			<table v-else class="agents__table">
				<thead>
					<tr>
						<th>{{ t('openregister', 'Name') }}</th>
						<th>{{ t('openregister', 'Tools') }}</th>
						<th />
					</tr>
				</thead>
				<tbody>
					<tr v-for="agent in agents" :key="agent.id">
						<td>{{ agent.name }}</td>
						<td>{{ labels(agent.tools, toolOptions) }}</td>
						<td>
							<NcButton
								v-if="canEdit(agent)"
								:aria-label="
									t('openregister', 'Limits of {name}', {
										name: agent.name,
									})
								"
								@click="editing = agent">
								{{ t('openregister', 'Edit limits') }}
							</NcButton>
							<span
								v-else-if="!isListGrant(agent)"
								class="agents__readonly">
								{{
									t(
										'openregister',
										'This agent has a grant per app. Change it through the agents API.',
									)
								}}
							</span>
							<span v-else class="agents__readonly">
								{{
									t(
										'openregister',
										"Only the agent's owner can change its limits.",
									)
								}}
							</span>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
		<EditAgentLimits
			v-if="editing"
			:agent="editing"
			:toolOptions="toolOptions"
			@close="editing = null"
			@saved="onSaved" />
	</NcAppContent>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcAppContent,
	NcButton,
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'
import EditAgentLimits from '../../modals/agent/EditAgentLimits.vue'

export default {
	name: 'AgentsIndex',
	components: {
		EditAgentLimits,
		NcAppContent,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
	},

	data() {
		return {
			agents: [],
			toolOptions: [],
			loading: true,
			error: '',
			editing: null,
		}
	},

	async mounted() {
		await this.load()
	},

	methods: {
		t,
		/**
		 * Load the agents and the tool catalogue in one go.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const [agents, tools] = await Promise.all([
					axios.get(generateUrl('/apps/openregister/api/agents')),
					axios.get(generateUrl('/apps/openregister/api/agents/tools')),
				])
				this.agents = agents.data?.results || []
				this.toolOptions = Object.entries(tools.data?.results || {}).map(
					([id, meta]) => ({ id, label: meta?.name || id }),
				)
			} catch {
				this.error = t('openregister', 'Could not load the agents')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Whether the signed-in user may change this agent (the API allows its owner only).
		 *
		 * @param {object} agent The agent
		 * @return {boolean} True for the owner
		 */
		canEdit(agent) {
			return this.isListGrant(agent) && agent.owner === getCurrentUser()?.uid
		},

		/**
		 * Whether the agent's grant is a plain list of tool ids, the form this screen edits.
		 * A structured grant (app, subject, action) is left alone: saving a list over it
		 * would drop its structure.
		 *
		 * @param {object} agent The agent
		 * @return {boolean} True for no grant or a list grant
		 */
		isListGrant(agent) {
			return (
				agent.tools === null
				|| agent.tools === undefined
				|| Array.isArray(agent.tools)
			)
		},

		/**
		 * Readable list of the ids an agent holds.
		 *
		 * @param {Array<string>|object|null} ids The stored ids (a structured grant reads as its app names)
		 * @param {Array<{id: string, label: string}>} options Known options
		 * @return {string} Comma-separated labels, or a dash for none
		 */
		labels(ids, options) {
			if (ids && !Array.isArray(ids) && typeof ids === 'object') {
				return Object.keys(ids).join(', ')
			}
			if (!Array.isArray(ids) || ids.length === 0) {
				return '-'
			}
			return ids
				.map((id) => options.find((o) => o.id === id)?.label || id)
				.join(', ')
		},

		/**
		 * Replace the saved agent in the list and close the modal.
		 *
		 * @param {object} saved The agent as the API returned it
		 */
		onSaved(saved) {
			this.agents = this.agents.map((a) => (a.id === saved.id ? saved : a))
			this.editing = null
		},
	},
}
</script>

<style scoped>
.agents {
	padding: 16px 24px;
}

.agents__intro {
	color: var(--color-text-maxcontrast);
	margin-bottom: 16px;
}

.agents__table {
	width: 100%;
	border-collapse: collapse;
}

.agents__table th,
.agents__table td {
	padding: 8px;
	text-align: start;
	border-bottom: 1px solid var(--color-border);
}

.agents__readonly {
	color: var(--color-text-maxcontrast);
}
</style>
