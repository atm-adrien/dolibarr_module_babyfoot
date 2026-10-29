import { php } from './dolibarr';

export default function globalTeardown(): void {
  php('teardown.php');
  if (process.env.E2E_BABYFOOT_ENABLED_BY_SETUP === '1') {
    php('module.php', ['off']);
  }
}
