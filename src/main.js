import Vue from 'vue'
import { PiniaVuePlugin } from 'pinia'
import Tooltip from '@nextcloud/vue/dist/Directives/Tooltip.js'
import pinia from './pinia.js'
import App from './App.vue'
import router from './router/index.js'
import { registerIcons } from '@conduction/nextcloud-vue'
import '@conduction/nextcloud-vue/css/index.css'
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
