import { defineConfig } from '@playwright/test';

// Must end with '/', or a relative goto() drops the base path.
const baseURL = (process.env.BASE_URL || 'http://localhost:8080').replace(/\/*$/, '/');

export default defineConfig({
  testDir: './tests/e2e',
  globalSetup: './tests/e2e/global-setup.ts',
  globalTeardown: './tests/e2e/global-teardown.ts',
  workers: 1,
  use: { baseURL },
});
