<!--
  OpenRegister Object Reference Widget

  Renders a rich preview card for OpenRegister object references in the
  Nextcloud Smart Picker / @nextcloud/vue-richtext. Displays object title,
  schema/register context, key properties, and a clickable link.

  @category Reference
  @package  OCA.OpenRegister.Reference
  @license  EUPL-1.2

  @see https://docs.nextcloud.com/server/latest/developer_manual/digging_deeper/reference.html
-->
<template>
	<a :href="objectUrl"
		class="openregister-reference-widget"
		target="_blank"
		rel="noopener noreferrer"
		:title="t('openregister', 'View object')">
		<div class="openregister-reference-widget__icon">
			<img :src="iconUrl" :alt="title" class="openregister-reference-widget__icon-img">
		</div>
		<div class="openregister-reference-widget__content">
			<h3 class="openregister-reference-widget__title">
				{{ title }}
			</h3>
			<p class="openregister-reference-widget__subtitle">
				<span class="openregister-reference-widget__tag">
					{{ t('openregister', 'Schema') }}: {{ schemaTitle }}
				</span>
				<span class="openregister-reference-widget__separator">|</span>
				<span class="openregister-reference-widget__tag">
					{{ t('openregister', 'Register') }}: {{ registerTitle }}
				</span>
			</p>
			<ul v-if="properties.length > 0" class="openregister-reference-widget__properties">
				<li v-for="prop in properties"
					:key="prop.label"
					class="openregister-reference-widget__property">
					<span class="openregister-reference-widget__property-label">{{ prop.label }}:</span>
					<span class="openregister-reference-widget__property-value">{{ prop.value }}</span>
				</li>
			</ul>
			<p v-if="updated" class="openregister-reference-widget__updated">
				{{ t('openregister', 'Updated') }}: {{ formattedDate }}
			</p>
		</div>
	</a>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'ObjectReferenceWidget',

	props: {
		richObjectType: {
			type: String,
			default: 'openregister-object',
		},
		richObject: {
			type: Object,
			default: () => ({}),
		},
		accessible: {
			type: Boolean,
			default: true,
		},
	},

	computed: {
		title() {
			return this.richObject.title || t('openregister', 'Unknown Object')
		},
		objectUrl() {
			return this.richObject.url || '#'
		},
		iconUrl() {
			return this.richObject.icon_url || ''
		},
		schemaTitle() {
			return this.richObject.schema?.title || t('openregister', 'Unknown Schema')
		},
		registerTitle() {
			return this.richObject.register?.title || t('openregister', 'Unknown Register')
		},
		properties() {
			return this.richObject.properties || []
		},
		updated() {
			return this.richObject.updated || ''
		},
		formattedDate() {
			if (!this.updated) {
				return ''
			}
			try {
				const date = new Date(this.updated)
				return date.toLocaleDateString(undefined, {
					year: 'numeric',
					month: 'short',
					day: 'numeric',
					hour: '2-digit',
					minute: '2-digit',
				})
			} catch {
				return this.updated
			}
		},
	},

	methods: {
		t,
	},
}
</script>

<style scoped>
.openregister-reference-widget {
	display: flex;
	align-items: flex-start;
	gap: 12px;
	padding: 12px;
	border: 1px solid var(--color-border, #e0e0e0);
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-main-background, #fff);
	color: var(--color-main-text, #222);
	text-decoration: none;
	transition: box-shadow 0.2s ease;
	max-width: 600px;
}

.openregister-reference-widget:hover,
.openregister-reference-widget:focus {
	box-shadow: 0 2px 8px var(--color-box-shadow, rgba(0, 0, 0, 0.1));
	text-decoration: none;
}

.openregister-reference-widget__icon {
	flex-shrink: 0;
	width: 44px;
	height: 44px;
	display: flex;
	align-items: center;
	justify-content: center;
}

.openregister-reference-widget__icon-img {
	width: 32px;
	height: 32px;
	object-fit: contain;
}

.openregister-reference-widget__content {
	flex: 1;
	min-width: 0;
	overflow: hidden;
}

.openregister-reference-widget__title {
	margin: 0 0 4px 0;
	font-size: 1rem;
	font-weight: 600;
	line-height: 1.3;
	color: var(--color-main-text, #222);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.openregister-reference-widget__subtitle {
	margin: 0 0 6px 0;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast, #767676);
}

.openregister-reference-widget__separator {
	margin: 0 6px;
	color: var(--color-text-maxcontrast, #767676);
}

.openregister-reference-widget__properties {
	list-style: none;
	margin: 0 0 6px 0;
	padding: 0;
}

.openregister-reference-widget__property {
	font-size: 0.85rem;
	line-height: 1.5;
	color: var(--color-text-lighter, #555);
}

.openregister-reference-widget__property-label {
	font-weight: 500;
	color: var(--color-main-text, #222);
}

.openregister-reference-widget__property-value {
	margin-left: 4px;
}

.openregister-reference-widget__updated {
	margin: 0;
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast, #767676);
}

/* Responsive: stack properties on narrow widths */
@media (max-width: 400px) {
	.openregister-reference-widget {
		flex-direction: column;
		align-items: stretch;
	}

	.openregister-reference-widget__icon {
		width: 100%;
		height: auto;
		justify-content: flex-start;
	}
}
</style>
