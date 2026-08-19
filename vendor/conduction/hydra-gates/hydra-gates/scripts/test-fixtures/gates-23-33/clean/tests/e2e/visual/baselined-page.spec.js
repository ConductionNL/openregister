// Visual-regression proof for BaselinedPage.
test('BaselinedPage renders', async ({ page }) => {
	await expect(page).toHaveScreenshot('BaselinedPage.png')
})
