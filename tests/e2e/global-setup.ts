import { php } from './dolibarr';

// The dataset is built once per run and handed to the workers through the environment, so no
// password or id is ever written to the repository.
export default function globalSetup(): void {
  const state = php('module.php', ['status']).trim();
  if (state === 'stale') {
    // Separate processes: an off/on cycle in one process keeps the cached $conf.
    php('module.php', ['off']);
  }
  if (state !== 'ok') {
    php('module.php', ['on']);
  }
  if (state === 'off') {
    process.env.E2E_BABYFOOT_ENABLED_BY_SETUP = '1';
  }

  // A run killed mid-way leaves its dataset behind, and refs are usually unique: start clean.
  php('teardown.php');
  process.env.E2E_FIXTURES = php('setup.php');
}
