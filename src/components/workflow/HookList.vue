<template>
	<div class="hook-list">
		<h3>Configured Hooks</h3>
		<NcButton v-if="hooks.length === 0" @click="$emit('add')">
			Add Hook
		</NcButton>
		<table v-else class="hook-table">
			<thead>
				<tr>
					<th scope="col">Event</th>
					<th scope="col">Engine</th>
					<th scope="col">Workflow</th>
					<th scope="col">Mode</th>
					<th scope="col">Order</th>
					<th scope="col">Enabled</th>
					<th scope="col">Actions</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="(hook, index) in hooks" :key="hook.id || index">
					<td>{{ hook.event }}</td>
					<td>{{ hook.engine }}</td>
					<td>{{ hook.workflowId }}</td>
					<td>{{ hook.mode || 'sync' }}</td>
					<td>{{ hook.order || 0 }}</td>
					<td>{{ hook.enabled !== false ? 'Yes' : 'No' }}</td>
					<td>
						<NcButton variant="tertiary" @click="$emit('edit', index)">
							Edit
						</NcButton>
						<NcButton variant="tertiary" @click="$emit('test', hook)">
							Test
						</NcButton>
						<NcButton variant="error" @click="$emit('delete', index)">
							Delete
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>
		<NcButton v-if="hooks.length > 0" @click="$emit('add')"> Add Hook </NcButton>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'

export default {
	name: 'HookList',
	components: { NcButton },
	props: {
		hooks: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['add', 'edit', 'delete', 'test'],
}
</script>

<style scoped>
.hook-table {
	width: 100%;
	border-collapse: collapse;
}

.hook-table th,
.hook-table td {
	padding: 8px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}
</style>
