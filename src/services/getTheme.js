/**
 * Determines the current theme of the document.
 *
 * This function checks the `data-theme-light` and `data-theme-default` attributes
 * on the document body to determine the theme. If `data-theme-light` is present,
 * it returns 'light'. If `data-theme-default` is present, it checks the user's
 * preferred color scheme using the `window.matchMedia` API and returns 'light' or 'dark'
 * accordingly. If neither attribute is present, it defaults to 'dark'.
 *
 * @return { 'light' | 'dark' } The current theme, either 'light' or 'dark'.
 */
export const getTheme = () => {
	if (document.body.hasAttribute('data-theme-light')) {
		return 'light'
	}

	if (document.body.hasAttribute('data-theme-dark')) {
		return 'dark'
	}

	if (document.body.hasAttribute('data-theme-default')) {
		return window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark'
	}
	return 'light'
}
