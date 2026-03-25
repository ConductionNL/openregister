declare module '*.vue' {
	import type { DefineComponent } from 'vue'
	const component: DefineComponent<Record<string, unknown>, Record<string, unknown>, unknown>
	export default component
}

declare module '@nextcloud/vue-richtext' {
	export function registerWidget(
		id: string,
		callback: () => Promise<unknown>,
		onDestroy?: () => void,
	): void
}
