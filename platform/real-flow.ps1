$ErrorActionPreference = "Stop"

Write-Host "Wiping database..."
docker exec cybersec-backend php artisan migrate:fresh --force

Write-Host "Seeding base requirements (roles, profiles, users) ONLY..."
docker exec cybersec-backend php artisan db:seed --class=RoleSeeder --force
docker exec cybersec-backend php artisan db:seed --class=ScanProfileSeeder --force
docker exec cybersec-backend php artisan db:seed --class=UserSeeder --force

Write-Host "Starting Queue Worker..."
docker exec -d cybersec-backend php artisan queue:work --queue=remediation,default --tries=3 --timeout=180

Write-Host "Running full E2E flow via Playwright..."
$env:Path = "C:\Program Files\nodejs;" + $env:Path
$env:BASE_URL = "http://localhost:3000"
npx playwright test --ignore-snapshots

Write-Host "Generating screenshots..."
$env:CAPTURE_SCREENSHOTS = "1"
$env:SCREENSHOT_DIR = "c:\wamp64\www\cybersec-workspace-full\cybersec-workspace\platform\rapport\img"
npx playwright test tests/e2e/99-screenshots.spec.ts

Write-Host "Done!"
