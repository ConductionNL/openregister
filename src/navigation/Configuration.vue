<template>
	<div>
		<NcAppNavigationItem :name="t('openregister', 'Configuration')" @click="settingsOpen = true">
			<template #icon>
				<CogOutline :size="20" />
			</template>
		</NcAppNavigationItem>
		<NcAppSettingsDialog :open.sync="settingsOpen" :show-navigation="true" :name="t('openregister', 'Application settings')">
			<NcAppSettingsSection id="federation" :name="t('openregister', 'Federation')" doc-url="https://conduction.gitbook.io/opencatalogi-nextcloud/beheerders/directory">
				<template #icon>
					<LanConnect :size="20" />
				</template>
				<NcCheckboxRadioSwitch :checked.sync="configuration.federationActive" type="switch">
					{{ t('openregister', 'Automatically connect to the federation network.') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch :checked.sync="configuration.federationActive" type="switch">
					{{ t('openregister', 'Automatically update catalogues.') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch :checked.sync="configuration.federationListed" type="switch">
					{{ t('openregister', 'Make this installation discoverable within the federation network.') }}
				</NcCheckboxRadioSwitch>
				<NcTextField id="federationLocation"
					:label="t('openregister', 'Internet location (URL) of this installation')"
					placeholder="https://" />
			</NcAppSettingsSection>
			<NcAppSettingsSection id="storadge" :name="t('openregister', 'Storage')" doc-url="zaakafhandel.app">
				<template #icon>
					<Database :size="20" />
				</template>

				<p>
					{{ t('openregister', 'Here you can configure the details for various connections.') }}
				</p>
				<NcCheckboxRadioSwitch :checked.sync="configuration.mongoStorage" type="switch">
					{{ t('openregister', 'Use external storage (e.g. MongoDB) instead of the internal Nextcloud storage.') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch :checked.sync="configuration.cloudStorage" type="switch">
					{{ t('openregister', 'Use VNG APIs instead of MongoDB.') }}
				</NcCheckboxRadioSwitch>
				<p>
					<table>
						<tbody>
							<tr>
								<td class="row-name">
									DRC
								</td>
								<td>{{ t('openregister', 'Location') }}</td>
								<td>
									<NcTextField id="drcLocation"
										:value.sync="configuration.drcLocation"
										:label-outside="true"
										placeholder="https://" />
								</td>
								<td>{{ t('openregister', 'Key') }}</td>
								<td>
									<NcTextField id="drcKey"
										:value.sync="configuration.drcKey"
										:label-outside="true"
										placeholder="***" />
								</td>
							</tr>
							<tr>
								<td class="row-name">
									ORC
								</td>
								<td>{{ t('openregister', 'Location') }}</td>
								<td>
									<NcTextField id="orcLocation"
										:value.sync="configuration.orcLocation"
										:label-outside="true"
										placeholder="https://" />
								</td>
								<td>{{ t('openregister', 'Key') }}</td>
								<td>
									<NcTextField id="orcKey"
										:value.sync="configuration.orcKey"
										:label-outside="true"
										placeholder="***" />
								</td>
							</tr>
							<tr>
								<td class="row-name">
									Elastic
								</td>
								<td>{{ t('openregister', 'Location') }}</td>
								<td>
									<NcTextField id="elasticLocation"
										:value.sync="configuration.elasticLocation"
										:label-outside="true"
										placeholder="https://" />
								</td>
								<td>{{ t('openregister', 'Key') }}</td>
								<td>
									<NcTextField id="elasticKey"
										:value.sync="configuration.elasticKey"
										:label-outside="true"
										placeholder="***" />
								</td>
								<td>{{ t('openregister', 'Index') }}</td>
								<td>
									<NcTextField id="elasticIndex"
										:value.sync="configuration.elasticIndex"
										:label-outside="true"
										placeholder="objects" />
								</td>
							</tr>
							<tr>
								<td class="row-name">
									Mongo DB
								</td>
								<td>{{ t('openregister', 'Location') }}</td>
								<td>
									<NcTextField id="mongodbLocation"
										:value.sync="configuration.mongodbLocation"
										:label-outside="true"
										placeholder="https://" />
								</td>
								<td>{{ t('openregister', 'Key') }}</td>
								<td>
									<NcTextField id="mongodbKey"
										:value.sync="configuration.mongodbKey"
										:label-outside="true"
										placeholder="***" />
								</td>
								<td>{{ t('openregister', 'Cluster name') }}</td>
								<td>
									<NcTextField id="mongodbCluster"
										:value.sync="configuration.mongodbCluster"
										:label-outside="true"
										placeholder="***" />
								</td>
							</tr>
						</tbody>
					</table>
				</p>
				<NcButton :aria-label="t('openregister', 'Save')"
					type="primary"
					wide
					@click="saveConfig(); feedbackPosition = 'top'">
					<template #icon>
						<ContentSave :size="20" />
					</template>
					{{ t('openregister', 'Save') }}
				</NcButton>
				<div v-if="feedbackPosition === 'top' && configurationSuccess !== -1">
					<NcNoteCard :type="configurationSuccess ? 'success' : 'error'">
						<p>
							{{ configurationSuccess ?
								t('openregister', 'Configuration saved successfully.') :
								t('openregister', 'Failed to save configuration.')
							}}
						</p>
					</NcNoteCard>
				</div>
			</NcAppSettingsSection>
			<NcAppSettingsSection id="organisation" :name="t('openregister', 'Roles and Permissions')" doc-url="zaakafhandel.app">
				<template #icon>
					<AccountLockOpenOutline :size="20" />
				</template>

				<p>
					{{ t('openregister', 'Here you can configure the details for your organisation.') }}
				</p>

				<NcTextField id="organisationName" :value.sync="configuration.organisationName" />
				<NcTextField id="organisationOin" :value.sync="configuration.organisationOin" />
				<NcTextArea id="organisationPki" :value.sync="configuration.organisationPki" />

				<NcButton :aria-label="t('openregister', 'Save')"
					type="primary"
					wide
					@click="saveConfig(); feedbackPosition = 'bottom'">
					<template #icon>
						<ContentSave :size="20" />
					</template>
					{{ t('openregister', 'Save') }}
				</NcButton>
				<div v-if="feedbackPosition === 'bottom' && configurationSuccess !== -1">
					<NcNoteCard :type="configurationSuccess ? 'success' : 'error'">
						<p>
							{{ configurationSuccess ?
								t('openregister', 'Configuration saved successfully.') :
								t('openregister', 'Failed to save configuration.')
							}}
						</p>
					</NcNoteCard>
				</div>
			</NcAppSettingsSection>
		</NcAppSettingsDialog>
	</div>
</template>
<script>

import {
	NcAppSettingsDialog,
	NcAppSettingsSection,
	NcAppNavigationItem,
	NcCheckboxRadioSwitch,
	NcTextField,
	NcTextArea,
	NcButton,
	NcNoteCard,
} from '@nextcloud/vue'

import { translate as t } from '@nextcloud/l10n'

import Database from 'vue-material-design-icons/Database.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import AccountLockOpenOutline from 'vue-material-design-icons/AccountLockOpenOutline.vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import LanConnect from 'vue-material-design-icons/LanConnect.vue'

export default {
	name: 'Configuration',
	components: {
		NcAppSettingsDialog,
		NcAppSettingsSection,
		NcAppNavigationItem,
		NcCheckboxRadioSwitch,
		NcTextField,
		NcTextArea,
		NcButton,
		NcNoteCard,
		// icons
		CogOutline,
		Database,
		ContentSave,
		AccountLockOpenOutline,
		LanConnect,
	},
	data() {
		return {

			// all of this is settings and should be moved
			settingsOpen: false,
			orc_location: '',
			orc_key: '',
			drc_location: '',
			drc_key: '',
			elastic_location: '',
			elastic_key: '',
			loading: true,
			organisation_name: '',
			organisation_oin: '',
			organisation_pki: '',
			configuration: {
				federationActive: true,
				federationListed: false,
				federationLocation: '',
				external: false,
				drcLocation: '',
				drcKey: '',
				orcLocation: '',
				orcKey: '',
				elasticLocation: '',
				elasticKey: '',
				elasticIndex: '',
				mongodbLocation: '',
				mongodbKey: '',
				mongodbCluster: '',
				organisationName: '',
				organisationOin: '',
				organisationPki: '',
			},
			configurationSuccess: -1,
			feedbackPosition: '',
			debounceTimeout: false,
		}
	},
	mounted() {
		this.fetchData()
	},
	methods: {
		t,
		// We use the catalogi in the menu so lets fetch those
		fetchData(_newPage) {
			this.loading = true

			fetch(
				'/index.php/apps/opencatalogi/configuration',
				{
					method: 'GET',
				},
			)
				.then((response) => {
					response.json().then((data) => {
						this.configuration = data
					})
				})
				.catch((err) => {
					console.error(err)
				})
		},
		saveConfig() {
			 // Simple POST request with a JSON body using fetch
			const requestOptions = {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify(this.configuration),
			}

			const debounceNotification = (status) => {
				this.configurationSuccess = status

				if (this.debounceTimeout) {
					clearTimeout(this.debounceTimeout)
				}

				this.debounceTimeout = setTimeout(() => {
					this.feedbackPosition = undefined
					this.configurationSuccess = -1
				}, 1500)
			}

			fetch('/index.php/apps/opencatalogi/configuration', requestOptions)
				.then((response) => {
					debounceNotification(response.ok)

					response.json().then((data) => {
						this.configuration = data
					})
				})
				.catch((err) => {
					debounceNotification(false)
					console.error(err)
				})
		},
	},
}
</script>
<style>
table {
	table-layout: fixed;
}

td.row-name {
	padding-inline-start: 16px;
}

td.row-size {
	text-align: right;
	padding-inline-end: 16px;
}

.table-header {
	font-weight: normal;
	color: var(--color-text-maxcontrast);
}

.sort-icon {
	color: var(--color-text-maxcontrast);
	position: relative;
	inset-inline: -10px;
}

.row-size .sort-icon {
	inset-inline: 10px;
}
</style>
