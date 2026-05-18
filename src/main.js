import Vue from 'vue'
import { PiniaVuePlugin } from 'pinia'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import Tooltip from '@nextcloud/vue/dist/Directives/Tooltip.js'
import pinia from './pinia.js'
import App from './App.vue'
import router from './router/index.js'
import {
	registerIcons,
	installIntegrationRegistry,
	registerBuiltinIntegrations,
	registerLeafIntegrations,
} from '@conduction/nextcloud-vue'
import '@conduction/nextcloud-vue/css/index.css'

// Install the in-page integration registry on window.OCA.OpenRegister and
// pre-register the 5 always-on built-ins (files/notes/tags/tasks/audit) plus
// the 18 leaf descriptors. OR is the producer of this registry — every
// consumer app's bootstrap reaches into the same singleton — so registering
// the defaults here means object-detail surfaces inside OR itself exercise
// the full set without depending on a consumer app's wiring.
installIntegrationRegistry(window)
registerBuiltinIntegrations()
registerLeafIntegrations()
// xwiki is intentionally NOT in nc-vue's leafIntegrations[] — it ships with
// a richer dedicated tab (CnXwikiTab) that consumer apps register separately.
// For the per-leaf verification harness we want all 24 advertised providers
// in the JS registry, so register a generic descriptor here. The tab + widget
// components come from the registry's resolveWidget AD-19 fallback to the
// default CnIntegrationTab / CnIntegrationCard set via the leaf descriptor.
try {
	const xwikiAlreadyRegistered = window?.OCA?.OpenRegister?.integrations?.has?.('xwiki')
	if (window?.OCA?.OpenRegister?.integrations?.register && !xwikiAlreadyRegistered) {
		import('@conduction/nextcloud-vue').then(({ CnIntegrationTab, CnIntegrationCard }) => {
			window.OCA.OpenRegister.integrations.register({
				id: 'xwiki',
				label: t('openregister', 'Articles'),
				icon: 'FileDocumentMultiple',
				requiredApp: 'openconnector',
				order: 31,
				group: 'external',
				referenceType: 'xwiki',
				tab: CnIntegrationTab,
				widget: CnIntegrationCard,
				defaultSize: { w: 4, h: 3 },
			})
		}).catch(e => console.error('[main] failed to register xwiki descriptor', e))
	}
} catch (e) {
	console.error('[main] xwiki registry guard failed', e)
}
import { Fragment } from 'vue-frag'

import AccountGroupOutline from 'vue-material-design-icons/AccountGroupOutline.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'

registerIcons({
	AccountGroupOutline,
	FileDocumentOutline,
	Cog,
	CogOutline,
})

Vue.mixin({ methods: { t, n } })

Vue.use(PiniaVuePlugin)
Vue.directive('tooltip', Tooltip)

Vue.component('Fragment', Fragment)

new Vue(
	{
		pinia,
		router,
		render: h => h(App),
	},
).$mount('#content')
