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
