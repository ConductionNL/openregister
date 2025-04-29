<template>
	<NcAppContent>
		<h2 class="pageHeader">
			Dashboard
		</h2>

		<div class="dashboard-content">
			<div class="register-cards" v-if="registers && registers.length > 0">
				<div v-for="register in registers" 
					:key="register.id" 
					class="register-card">
					<div class="register-header">
						<DatabaseOutline :size="24" />
						<h3>{{ register.title }}</h3>
					</div>
					<p class="register-description">{{ register.description }}</p>
					<div class="schema-container">
						<div v-for="schema in register.schemas" 
							:key="schema.id"
							class="schema-node">
							<div class="schema-icon">
								<FileCodeOutline :size="20" />
							</div>
							<span class="schema-title">{{ schema.title }}</span>
						</div>
					</div>
				</div>
			</div>
			<NcEmptyContent v-else
				name="No Registers"
				description="No registers have been created yet">
				<template #icon>
					<DatabaseOutline />
				</template>
			</NcEmptyContent>
		</div>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcEmptyContent } from '@nextcloud/vue'
import { registerStore } from '../../store/store.js'
import DatabaseOutline from 'vue-material-design-icons/DatabaseOutline.vue'
import FileCodeOutline from 'vue-material-design-icons/FileCodeOutline.vue'

export default {
	name: 'DashboardIndex',
	components: {
		NcAppContent,
		NcEmptyContent,
		DatabaseOutline,
		FileCodeOutline,
	},
	data() {
		return {
			registers: [],
		}
	},
	mounted() {
		this.loadRegisters()
	},
	methods: {
		async loadRegisters() {
			await registerStore.refreshRegisterList()
			this.registers = registerStore.registerList
		},
	},
}
</script>

<style lang="scss" scoped>
.dashboard-content {
	padding: 20px;
	max-width: 1600px;
	margin: 0 auto;
}

.register-cards {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
	gap: 20px;
	padding: 20px;
}

.register-card {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 8px;
	padding: 16px;
	box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
	transition: all 0.3s ease;

	&:hover {
		transform: translateY(-2px);
		box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
	}
}

.register-header {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 12px;

	h3 {
		margin: 0;
		font-size: 1.2em;
		color: var(--color-main-text);
	}
}

.register-description {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin-bottom: 16px;
}

.schema-container {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.schema-node {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px;
	background: var(--color-background-hover);
	border-radius: 4px;
	font-size: 0.9em;

	.schema-icon {
		display: flex;
		align-items: center;
		color: var(--color-primary);
	}

	.schema-title {
		color: var(--color-text-maxcontrast);
	}
}
</style>
