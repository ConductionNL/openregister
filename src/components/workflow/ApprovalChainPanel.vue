<template>
	<div class="approval-chain-panel">
		<h3>Approval Chains</h3>
		<div v-if="chains.length === 0">
			<p>No approval chains configured for this schema.</p>
		</div>
		<div v-for="chain in chains" :key="chain.id" class="chain-card">
			<h4>{{ chain.name }}</h4>
			<p>Status Field: {{ chain.statusField }}</p>
			<p>Steps: {{ chain.steps.length }}</p>
			<ul>
				<li v-for="(step, i) in chain.steps" :key="i">
					Step {{ step.order }}: {{ step.role }} (approve: {{ step.statusOnApprove }}, reject: {{ step.statusOnReject }})
				</li>
			</ul>
		</div>
		<NcButton type="primary" @click="showCreateForm = !showCreateForm">
			{{ showCreateForm ? 'Cancel' : 'Create Chain' }}
		</NcButton>
		<div v-if="showCreateForm" class="create-form">
			<div class="form-group">
				<label>Name</label>
				<input v-model="newChain.name" type="text" class="input-field">
			</div>
			<div class="form-group">
				<label>Status Field</label>
				<input v-model="newChain.statusField" type="text" class="input-field">
			</div>
			<NcButton type="primary" @click="createChain">
				Save Chain
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'ApprovalChainPanel',
	components: { NcButton },
	props: {
		schemaId: { type: Number, default: null },
	},
	data() {
		return {
			chains: [],
			showCreateForm: false,
			newChain: { name: '', statusField: 'status', steps: [] },
		}
	},
	mounted() {
		this.fetchChains()
	},
	methods: {
		async fetchChains() {
			try {
				const url = generateUrl('/apps/openregister/api/approval-chains')
				const response = await axios.get(url)
				this.chains = (response.data || []).filter(c => !this.schemaId || c.schemaId === this.schemaId)
			} catch (error) {
				console.error('Failed to fetch chains:', error)
			}
		},
		async createChain() {
			try {
				const url = generateUrl('/apps/openregister/api/approval-chains')
				await axios.post(url, { ...this.newChain, schemaId: this.schemaId })
				this.showCreateForm = false
				this.fetchChains()
			} catch (error) {
				console.error('Failed to create chain:', error)
			}
		},
	},
}
</script>

<style scoped>
.chain-card { border: 1px solid var(--color-border); border-radius: 8px; padding: 12px; margin-bottom: 12px; }
.form-group { margin-bottom: 8px; }
.form-group label { display: block; font-weight: bold; }
.input-field { width: 100%; padding: 8px; }
.create-form { margin-top: 12px; padding: 12px; border: 1px solid var(--color-border); border-radius: 8px; }
</style>
