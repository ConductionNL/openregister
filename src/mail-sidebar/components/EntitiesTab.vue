<template>
	<div class="or-tab-entities">
		<div v-if="loading" class="or-tab-loading">
			{{ t('openregister', 'Loading entities...') }}
		</div>
		<div v-else-if="entities.length === 0" class="or-tab-empty">
			{{ t('openregister', 'No entities detected for this email.') }}
		</div>
		<div v-else>
			<div
				v-for="(group, type) in groupedEntities"
				:key="type"
				class="or-entity-group">
				<h4 class="or-entity-group-title">
					{{ formatType(type) }}
				</h4>
				<ul class="or-entity-list">
					<li
						v-for="entity in group"
						:key="entity.id"
						class="or-entity-item">
						<span class="or-entity-value">{{ entity.value }}</span>
						<span v-if="entity.confidence" class="or-entity-confidence">
							{{ Math.round(entity.confidence * 100) }}%
						</span>
					</li>
				</ul>
			</div>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'EntitiesTab',
	props: {
		accountId: { type: Number, default: null },
		messageId: { type: Number, default: null },
	},
	data() {
		return {
			entities: [],
			loading: false,
		}
	},
	computed: {
		groupedEntities() {
			const groups = {}
			for (const entity of this.entities) {
				const type = entity.type || 'unknown'
				if (!groups[type]) {
					groups[type] = []
				}
				groups[type].push(entity)
			}
			return groups
		},
	},
	watch: {
		messageId() {
			this.loadEntities()
		},
	},
	created() {
		this.loadEntities()
	},
	methods: {
		t,
		formatType(type) {
			const labels = {
				PERSON: t('openregister', 'Persons'),
				ORGANIZATION: t('openregister', 'Organizations'),
				EMAIL: t('openregister', 'Email Addresses'),
				PHONE: t('openregister', 'Phone Numbers'),
				LOCATION: t('openregister', 'Locations'),
				ADDRESS: t('openregister', 'Addresses'),
				DATE: t('openregister', 'Dates'),
				IBAN: t('openregister', 'IBANs'),
				unknown: t('openregister', 'Other'),
			}
			return labels[type] || type
		},
		async loadEntities() {
			if (!this.messageId) {
				this.entities = []
				return
			}

			this.loading = true
			try {
				// Query entities that have relations to this email
				// The entity relations have an emailId field
				const url = generateUrl('/apps/openregister/api/entities')
				const response = await axios.get(url, {
					params: { emailId: this.messageId, limit: 50 },
					timeout: 10000,
				})
				const data = response.data
				this.entities = data?.data || data?.results || []
			} catch (err) {
				console.error('[EntitiesTab] Load failed:', err)
				this.entities = []
			} finally {
				this.loading = false
			}
		},
	},
}
</script>
