<template>
	<CnAppNav :manifest="manifest" :translate="translate" :isAdmin="isAdmin">
		<!-- Primary action: the active-organisation switcher button. Rendered
		     in CnAppNav's #primary-action slot because its label is live store
		     state (organisationStore.activeOrganisation) — the manifest's
		     static nav.primaryAction can't express that. Clicking returns to
		     the dashboard. -->
		<template #primary-action>
			<NcAppNavigationNew
				:text="activeOrganisationName"
				@click="handleNavigate('/')">
				<template #icon>
					<Finance :size="20" />
				</template>
			</NcAppNavigationNew>
		</template>
	</CnAppNav>
</template>

<script>
import { CnAppNav } from '@conduction/nextcloud-vue'
import { getCurrentUser } from '@nextcloud/auth'
import { translate as t } from '@nextcloud/l10n'
import { NcAppNavigationNew } from '@nextcloud/vue'
// Icons
import Finance from 'vue-material-design-icons/Finance.vue'
// Manifest (menu declared declaratively; rendered by CnAppNav)
import manifest from '../manifest.json'
// Store
import { organisationStore } from '../store/store.js'

export default {
	name: 'MainMenu',
	components: {
		CnAppNav,
		NcAppNavigationNew,
		Finance,
	},

	data() {
		return {
			manifest,
		}
	},

	computed: {
		/**
		 * Whether the current user administers this instance.
		 *
		 * CnAppRoot computes this and passes it to the CnAppNav it renders
		 * itself. This app fills the #menu slot, and that slot carries no
		 * scope, so the prop never arrived and CnAppNav defaulted it to
		 * false. The Admin settings entry is gated on it, so it silently did
		 * not render for anybody, on an app whose whole admin surface lives
		 * there.
		 *
		 * Visibility only. Nextcloud's settings framework refuses
		 * /settings/admin/openregister server-side for a non-admin.
		 *
		 * @return {boolean} True when the user administers the instance.
		 *
		 * @spec exclude App-navigation plumbing: forwards a visibility flag CnAppRoot already computes; the access decision is Nextcloud's.
		 */
		isAdmin() {
			try {
				return getCurrentUser()?.isAdmin === true
			} catch {
				return false
			}
		},

		/**
		 * Get the active organisation name to display in navigation.
		 * Shows the current organisational context for the user.
		 *
		 * @return {string} The active organisation name or a fallback
		 */
		/**
		 * @spec exclude App-navigation plumbing: reads the active organisation name with a fallback label; no standalone behavioural contract.
		 */
		activeOrganisationName() {
			const activeOrg = organisationStore.activeOrganisation
			if (activeOrg && activeOrg.name) {
				return activeOrg.name
			}
			// Fallback if no organisation is active
			return t('openregister', 'No Organisation')
		},
	},

	methods: {
		t,
		/**
		 * Translate function passed to CnAppNav so manifest menu labels
		 * resolve through openregister's l10n catalogue.
		 *
		 * @param {string} key The label key from the manifest.
		 * @return {string} The translated string.
		 * @spec exclude App-navigation plumbing: t() wrapper; no standalone behavioural contract.
		 */
		translate(key) {
			return t('openregister', key)
		},

		/**
		 * Navigate to a router path (used by the primary-action button).
		 *
		 * @param {string} path The vue-router path to push.
		 * @spec exclude App-navigation plumbing: $router.push wrapper; no standalone behavioural contract.
		 */
		handleNavigate(path) {
			this.$router.push(path)
		},
	},
}
</script>

<style>
/* The NcAppNavigationNew (active-org button) sits in .app-navigation__body,
   which Nextcloud Vue sets to overflow-y:scroll — causing a permanent
   scrollbar on Windows even though the body holds just one button.
   Override to auto so the scrollbar only appears when content overflows. */
.app-navigation .app-navigation__body {
	overflow: hidden !important;
}
</style>
