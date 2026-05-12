<template>
	<NcModal v-if="show"
		:name="t('openregister', 'Switch Active Organisation')"
		@close="$emit('close')">
		<div class="organisationSwitcher">
			<h3>{{ t('openregister', 'Select Active Organisation') }}</h3>
			<div class="organisationList">
				<div v-for="org in organisations"
					:key="org.uuid"
					class="organisationOption"
					:class="{ active: isActive(org) }"
					@click="$emit('switch', org)">
					<div class="organisationOptionContent">
						<span class="organisationOptionName">{{ org.name }}</span>
						<span v-if="org.isDefault" class="defaultBadge">{{ t('openregister', 'Default') }}</span>
						<span v-if="isActive(org)" class="activeBadge">{{ t('openregister', 'Current') }}</span>
					</div>
					<span v-if="org.description" class="organisationOptionDescription">{{ org.description }}</span>
				</div>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcModal } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'SwitchOrganisationModal',
	components: {
		NcModal,
	},
	props: {
		show: {
			type: Boolean,
			required: true,
		},
		organisations: {
			type: Array,
			required: true,
		},
		activeOrganisationUuid: {
			type: String,
			default: null,
		},
	},
	emits: ['close', 'switch'],
	methods: {
		t,
		isActive(org) {
			return this.activeOrganisationUuid != null
				&& this.activeOrganisationUuid === org.uuid
		},
	},
}
</script>

<style scoped>
.organisationSwitcher {
	padding: 20px;
}

.organisationSwitcher h3 {
	margin-bottom: 20px;
	color: var(--color-text-dark);
}

.organisationList {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.organisationOption {
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	cursor: pointer;
	transition: background-color 0.2s ease;
}

.organisationOption:hover {
	background: var(--color-background-hover);
}

.organisationOption.active {
	background: var(--color-success-light);
	border-color: var(--color-success);
}

.organisationOptionContent {
	display: flex;
	align-items: center;
	gap: 8px;
}

.organisationOptionName {
	font-weight: 600;
	color: var(--color-text-dark);
}

.organisationOptionDescription {
	color: var(--color-text-lighter);
	font-size: 12px;
	margin-top: 4px;
	display: block;
}
</style>
