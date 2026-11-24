/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The page budget (hydra ADR-124): every main page in
 * tests/performance/budget.json is usable within PERF_PAGE_BUDGET_MS, warm.
 */

import { test } from '@playwright/test'
import { definePerformanceSpec } from '@conduction/nextcloud-vue/testing/performance'

definePerformanceSpec(test)
