import { execFileSync } from 'child_process';
import * as path from 'path';
import { expect, Page, Response } from '@playwright/test';

type MarkedWindow = Window & { __e2eBeforeSubmit?: boolean };

const FIXTURES_DIR = path.join(__dirname, 'fixtures');

export function php(script: string, args: string[] = []): string {
  return execFileSync('php', [path.join(FIXTURES_DIR, script), ...args], { encoding: 'utf8' });
}

export async function login(page: Page, loginName: string, password: string): Promise<void> {
  await page.goto('index.php');
  await page.fill('#username', loginName);
  await page.fill('#password', password);
  await page.click('input[type=submit]');
  await expect(page.locator('#username')).toHaveCount(0);
}

/**
 * `response` only for actions that never navigate: rules/testing.md, "Playwright sur un module Dolibarr".
 * `dispatch` skips Playwright's visible and enabled checks: each use is declared in the report.
 */
export async function submitAndWait(
  page: Page,
  selector: string,
  opts: { response?: string | RegExp | ((r: Response) => boolean); dispatch?: boolean } = {},
): Promise<void> {
  const target = page.locator(selector);
  const click = () => (opts.dispatch ? target.dispatchEvent('click') : target.click());
  if (opts.response !== undefined) {
    const responded = page.waitForResponse(opts.response);
    responded.catch(() => undefined); // a failed click must report itself, not this wait
    await click();
    await responded;
    return;
  }
  await page.evaluate(() => { (window as MarkedWindow).__e2eBeforeSubmit = true; });
  await click();
  await page.waitForFunction(
    () => (window as MarkedWindow).__e2eBeforeSubmit !== true && document.readyState === 'complete',
  );
}
