import {defineConfig} from '@playwright/test';

/**
 * Validates a generated per-app demo environment against the steps its own
 * documentation tells the reader to run.
 *
 * It is pointed at an ALREADY BOOTED demo via DEMO_BASE_URL rather than
 * starting one itself. A spec that boots its own fixture proves the fixture
 * works; this one has to prove the documented instructions work.
 */
export default defineConfig({
  testDir: '.',
  timeout: 60_000,
  expect: {timeout: 15_000},
  reporter: [['list']],
  use: {
    baseURL: process.env.DEMO_BASE_URL || 'http://localhost:8613',
    ignoreHTTPSErrors: true,
  },
});
