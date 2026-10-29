import { APIRequestContext, expect, Page } from '@playwright/test';
import { submitAndWait } from './dolibarr';

export type Player = { id: number; label: string };
export type Fixtures = {
  login: string;
  password: string;
  api_key: string;
  runner_id: number;
  players: Record<'p1' | 'p2' | 'p3' | 'p4', Player>;
};

export const fixtures: Fixtures = JSON.parse(process.env.E2E_FIXTURES || '{}');

// Same entity as the fixtures: with Multicompany the login form picks it, not the account.
export async function loginToFixtureEntity(page: Page): Promise<void> {
  await page.goto('index.php');
  const entity = page.locator('select#entity');
  if (await entity.count()) {
    await entity.selectOption(process.env.E2E_ENTITY || '1');
  }
  await page.fill('#username', fixtures.login);
  await page.fill('#password', fixtures.password);
  await submitAndWait(page, 'input[type=submit]');
  await expect(page.locator('#username')).toHaveCount(0);
}

export async function openBabyfootMenu(page: Page, script: string): Promise<void> {
  await page.goto('index.php');
  await submitAndWait(page, '#mainmenua_tools');
  await submitAndWait(page, `.vmenu a[href*="babyfoot/${script}"] >> nth=0`);
}

export async function apiCall(request: APIRequestContext, method: string, path: string, data?: unknown) {
  const headers = { DOLAPIKEY: fixtures.api_key, DOLAPIENTITY: process.env.E2E_ENTITY || '1', Accept: 'application/json' };
  const response = await request.fetch(`api/index.php/babyfoot/${path}`, { method, headers, data });
  const body = await response.text();
  return { status: response.status(), json: body ? JSON.parse(body) : null, body };
}
