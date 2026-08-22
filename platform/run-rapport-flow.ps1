$ErrorActionPreference = "Stop"

Write-Host "Wiping database to ensure a clean slate..."
docker exec cybersec-backend php artisan migrate:fresh --force

Write-Host "Seeding ONLY structural data (roles, profiles)..."
docker exec cybersec-backend php artisan db:seed --class=RoleSeeder --force
docker exec cybersec-backend php artisan db:seed --class=ScanProfileSeeder --force
# NO USER SEEDER, NO MOCK SCANS

Write-Host "Starting background queue worker for scans..."
docker exec -d cybersec-backend php artisan queue:work --queue=remediation,default --tries=3 --timeout=180

Write-Host "Running cohesive user journey for rapport..."
$env:Path = "C:\Program Files\nodejs;" + $env:Path
$env:BASE_URL = "http://localhost:3000"
$env:CAPTURE_SCREENSHOTS = "1"
$env:SCREENSHOT_DIR = "c:\wamp64\www\cybersec-workspace-full\cybersec-workspace\platform\rapport\img"

# Run ONLY our new cohesive flow script
npx playwright test tests/e2e/99-rapport-flow.spec.ts

Write-Host "All done! Screenshots saved to rapport/img"
