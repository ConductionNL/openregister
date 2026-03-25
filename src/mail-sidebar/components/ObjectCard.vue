<template>
	<div
		class="or-mail-object-card"
		role="article"
		:aria-label="cardAriaLabel">
		<div class="or-mail-object-card__header">
			<h4 class="or-mail-object-card__title">
				<a
					:href="deepLink"
					target="_blank"
					rel="noopener noreferrer"
					:title="t('openregister', 'Open in OpenRegister')">
					{{ objectTitle }}
				</a>
			</h4>
			<button
				v-if="showUnlink"
				class="or-mail-object-card__unlink"
				:aria-label="t('openregister', 'Remove link to {title}', { title: objectTitle })"
				:title="t('openregister', 'Remove link')"
				@click="$emit('unlink', object)">
				&times;
			</button>
		</div>
		<div class="or-mail-object-card__meta">
			<span v-if="object.schemaTitle" class="or-mail-object-card__schema">
				{{ object.schemaTitle }}
			</span>
			<span v-if="object.registerTitle" class="or-mail-object-card__register">
				{{ object.registerTitle }}
			</span>
			<span v-if="object.linkedEmailCount" class="or-mail-object-card__badge">
				{{ n('openregister', '{count} email', '{count} emails', object.linkedEmailCount, { count: object.linkedEmailCount }) }}
			</span>
		</div>
		<div v-if="object.linkedBy" class="or-mail-object-card__footer">
			<span class="or-mail-object-card__linked-by">
				{{ t('openregister', 'Linked by {user}', { user: object.linkedBy }) }}
			</span>
		</div>
	</div>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'

export default {
	name: 'ObjectCard',
	props: {
		object: {
			type: Object,
			required: true,
		},
		showUnlink: {
			type: Boolean,
			default: false,
		},
	},
	computed: {
		objectTitle() {
			return this.object.objectTitle || this.object.objectUuid || ''
		},
		deepLink() {
			const registerId = this.object.registerId || ''
			const schemaId = this.object.schemaId || ''
			const objectUuid = this.object.objectUuid || ''
			return `/apps/openregister/registers/${registerId}/${schemaId}/${objectUuid}`
		},
		cardAriaLabel() {
			const parts = [this.objectTitle]
			if (this.object.schemaTitle) {
				parts.push(this.object.schemaTitle)
			}
			if (this.object.registerTitle) {
				parts.push(t('openregister', 'in {register}', { register: this.object.registerTitle }))
			}
			return parts.join(', ')
		},
	},
	methods: {
		t,
		n,
	},
}
</script>
