import { execSync } from 'node:child_process'

/**
 * Reset the fixture accounts before the browser suite runs.
 *
 * These tests drive a real server against a real database, so fixtures have to
 * be restored deliberately -- a previous run that bought credits must not change
 * what this run sees. Only the seeded accounts are touched; generations are left
 * alone, so a demo environment keeps its history.
 */
export default function globalSetup() {
    execSync('php artisan db:seed --class=DemoSeeder --force', { stdio: 'inherit' })
}
