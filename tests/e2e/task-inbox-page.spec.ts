/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Task inbox UI e2e — the inbox page, the deep link, and the widget
 * (flow-task-inbox-projections 5.1).
 *
 * 1. The inbox page at /flow-tasks renders rows served by the flow-tasks
 *    API: a task seeded through the API appears in the list.
 * 2. The per-uuid deep link resolves on a COLD full-page load. The reload
 *    IS the test: TaskController::open() used to redirect to a hash path
 *    the history-mode router never resolved, so every notification button
 *    and VTODO URL rendered the dashboard. The assertion is on the task's
 *    own title, plus the absence of the dashboard heading.
 * 3. The dashboard carries the tasks widget (CnTasksWidget, the `tasks`
 *    dashboard widget type from nextcloud-vue 2.30.0).
 *
 * ⚠️ Needs a build carrying nextcloud-vue >= 2.30.0 (the `tasks` entity
 * source and CnTasksWidget shipped with nextcloud-vue#910). Against an
 * older release build the inbox renders an empty index and the widget
 * cell reads unavailable — a red run there is the dependency, not a
 * regression.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-the-inbox-surfaces-read-the-inbox-and-count-from-its-total
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-assigned-task-appears-in-the-assignees-own-calendar
 */
import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'
import { FlowTaskDetail, FlowTaskInbox } from './_page-routes.ts'

const APP_BASE = '/index.php/apps/openregister'
const API_BASE = `${APP_BASE}/api/flow-tasks`
const STORAGE_STATE = path.resolve(__dirname, '.auth/admin.json')
const RUN_ID = `e2e-inbox-ui-${Date.now().toString(36)}`

// Same reasoning as task-inbox.spec.ts: seed and clean over the REST API
// with Basic auth so no CSRF token is demanded. The BROWSER tests use the
// logged-in storage state instead — the page under test is a real SPA page.
const ADMIN_HEADERS = {
	'OCS-APIRequest': 'true',
	Accept: 'application/json',
	Authorization: `Basic ${Buffer.from(
		`${process.env.OR_USER || 'admin'}:${process.env.OR_PASS || 'admin'}`,
	).toString('base64')}`,
}

/**
 * Seed one task for the admin through the API and hand back its row.
 *
 * @param request The Playwright API context.
 * @param title The task title to seed.
 */
async function seedTask(request: APIRequestContext, title: string) {
	const response = await request.post(API_BASE, {
		headers: ADMIN_HEADERS,
		data: {
			title,
			state: 'active',
			performerType: 'user',
			assignee: process.env.OR_USER || 'admin',
			requester: process.env.OR_USER || 'admin',
			priority: 'normal',
		},
	})
	expect(response.status(), await response.text()).toBe(201)
	return response.json()
}

/**
 * Cancel a seeded task; never fails the suite — cleanup is not a verdict.
 *
 * @param request The Playwright API context.
 * @param uuid The task to cancel.
 */
async function cancelQuietly(request: APIRequestContext, uuid: string) {
	try {
		await request.post(`${API_BASE}/${uuid}/cancel`, {
			headers: ADMIN_HEADERS,
			data: { reason: `${RUN_ID} cleanup` },
		})
	} catch (error) {
		console.warn('[task-inbox-page] cleanup failed:', error)
	}
}

/**
 * Skip when the logged-in storage state is absent (the full suite's
 * global-setup writes it); a UI spec without a session can only test the
 * login page.
 *
 * @param page The page fixture (unused; present for signature clarity).
 */
function requireSession(page: Page) {
	void page
	if (!fs.existsSync(STORAGE_STATE)) {
		test.skip(true, 'storageState not present — run the full suite first')
	}
}

test.describe('flow-task inbox UI — page, deep link, widget', () => {
	test.use({ storageState: STORAGE_STATE })

	test('the inbox page renders rows from the flow-tasks API', async ({
		page,
		request,
	}) => {
		requireSession(page)
		const title = `${RUN_ID} inbox row`
		const task = await seedTask(request, title)

		try {
			await page.goto(`${APP_BASE}${FlowTaskInbox}`, {
				waitUntil: 'domcontentloaded',
			})
			// The seeded task's title is a rendered row, not just a payload.
			await expect(page.getByText(title).first()).toBeVisible({
				timeout: 25_000,
			})
		} finally {
			await cancelQuietly(request, String(task.uuid))
		}
	})

	test('the per-uuid deep link survives a cold reload and lands on the task', async ({
		page,
		request,
	}) => {
		requireSession(page)
		const title = `${RUN_ID} deep link`
		const task = await seedTask(request, title)

		try {
			// The FULL-PAGE load is the scenario: this is the URL a VTODO and
			// a notification button carry, opened with no SPA state at all.
			await page.goto(`${APP_BASE}${FlowTaskDetail(String(task.uuid))}`, {
				waitUntil: 'domcontentloaded',
			})

			// The task's own surface, not the dashboard the old hash
			// redirect fell back to.
			await expect(page.getByTestId('task-title')).toHaveText(
				new RegExp(title),
				{ timeout: 25_000 },
			)
			expect(page.url()).toContain(`/flow-tasks/${task.uuid}`)
		} finally {
			await cancelQuietly(request, String(task.uuid))
		}
	})

	test('the dashboard renders the tasks widget', async ({ page, request }) => {
		requireSession(page)
		const title = `${RUN_ID} widget row`
		const task = await seedTask(request, title)

		try {
			await page.goto(`${APP_BASE}/`, { waitUntil: 'domcontentloaded' })
			// CnTasksWidget's own root class: scoped, so the menu's Tasks
			// entry can never satisfy this assertion by accident.
			await expect(page.locator('.cn-tasks-widget')).toBeVisible({
				timeout: 25_000,
			})
			// The seeded assigned task is one of the widget's rows.
			await expect(
				page.locator('.cn-tasks-widget').getByText(title).first(),
			).toBeVisible({ timeout: 15_000 })
		} finally {
			await cancelQuietly(request, String(task.uuid))
		}
	})
})
