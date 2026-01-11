<template>
	<div class="settings-card" :class="{ 'collapsible-section': collapsible }">
		<h4
			v-if="title"
			:class="{ 'collapsible-header': collapsible }"
			@click="collapsible ? toggleCollapsed() : null">
			<span>{{ icon }} {{ title }}</span>
			<ChevronDown
				v-if="collapsible && !isCollapsed"
				:size="20"
				class="chevron-icon"
				:class="{ 'rotate': !isCollapsed }" />
			<ChevronUp
				v-if="collapsible && isCollapsed"
				:size="20"
				class="chevron-icon"
				:class="{ 'rotate': isCollapsed }" />
		</h4>

		<transition v-if="collapsible" name="slide-fade">
			<div v-show="!isCollapsed" class="collapsible-content">
				<slot />
			</div>
		</transition>

		<div v-else class="card-content">
			<slot />
		</div>
	</div>
</template>

<script>
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue'

export default {
	name: 'SettingsCard',

	components: {
		ChevronDown,
		ChevronUp,
	},

	props: {
		title: {
			type: String,
			default: '',
		},
		icon: {
			type: String,
			default: '',
		},
		collapsible: {
			type: Boolean,
			default: false,
		},
		defaultCollapsed: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			isCollapsed: this.defaultCollapsed,
		}
	},

	methods: {
		toggleCollapsed() {
			if (this.collapsible) {
				this.isCollapsed = !this.isCollapsed
				this.$emit('toggle', this.isCollapsed)
			}
		},
	},
}
</script>

<style scoped>
.settings-card {
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large);
	padding: 20px;
	margin-bottom: 20px;
}

.settings-card h4 {
	margin: 0 0 16px 0;
	color: var(--color-main-text);
	font-size: 16px;
	font-weight: 600;
	display: flex;
	align-items: center;
	gap: 8px;
}

/* Collapsible sections */
.collapsible-section h4.collapsible-header {
	cursor: pointer;
	user-select: none;
	display: flex;
	justify-content: space-between;
	align-items: center;
	transition: color 0.2s ease;
	margin-bottom: 0;
}

.collapsible-section h4.collapsible-header:hover {
	color: var(--color-primary-element);
}

.collapsible-section h4.collapsible-header .chevron-icon {
	transition: transform 0.3s ease;
	color: var(--color-text-maxcontrast);
}

.collapsible-section h4.collapsible-header:hover .chevron-icon {
	color: var(--color-primary-element);
}

.collapsible-section h4.collapsible-header .chevron-icon.rotate {
	transform: rotate(180deg);
}

.collapsible-content {
	padding-top: 16px;
}

.card-content {
	/* No extra padding needed, content controls its own spacing */
}

/* Vue Transition Animations */
.slide-fade-enter-active {
	transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
}

.slide-fade-leave-active {
	transition: all 0.3s cubic-bezier(0.4, 0, 1, 1);
}

.slide-fade-enter-from {
	transform: translateY(-10px);
	opacity: 0;
}

.slide-fade-leave-to {
	transform: translateY(-5px);
	opacity: 0;
}
</style>
