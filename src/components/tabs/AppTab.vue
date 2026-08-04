<script>
import { h, getCurrentInstance } from 'vue'

/**
 * One panel inside {@link AppTabs}. Replaces `bootstrap-vue`'s `<BTab>`.
 *
 * Registers itself with the parent `AppTabs` through provide/inject — Vue 3
 * removed `$children`, so a parent cannot discover its tab children by walking
 * the tree the way `<BTabs>` did.
 *
 * Supported props mirror the subset of `<BTab>` that OpenRegister used:
 *   - `title` — plain string label, or supply a `#title` slot instead.
 *   - `active` — marks this tab as the initially selected one.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */
export default {
	name: 'AppTab',
	inject: {
		appTabs: { default: null },
	},
	props: {
		/** Plain-text tab label; ignored when a `#title` slot is supplied. */
		title: {
			type: String,
			default: '',
		},
		/** Select this tab initially. */
		active: {
			type: Boolean,
			default: false,
		},
	},
	data() {
		return {
			uid: getCurrentInstance()?.uid ?? Math.random().toString(36).slice(2),
		}
	},
	created() {
		this.appTabs?.register({
			uid: this.uid,
			title: this.title,
			titleSlot: this.$slots.title ?? null,
			active: this.active,
		})
	},
	beforeUnmount() {
		this.appTabs?.unregister(this.uid)
	},
	/**
	 * Render the panel, or nothing when this tab is not the active one.
	 *
	 * @return {object|null} The panel vnode.
	 */
	render() {
		if (this.appTabs === null) {
			return h('div', this.$slots.default?.())
		}
		if (this.appTabs.isActive(this.uid) !== true) {
			return null
		}
		return h(
			'div',
			{
				role: 'tabpanel',
				id: `app-tabpanel-${this.uid}`,
				'aria-labelledby': `app-tab-${this.uid}`,
				class: 'app-tab-panel',
			},
			this.$slots.default?.(),
		)
	},
}
</script>
