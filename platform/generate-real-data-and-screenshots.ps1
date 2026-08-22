$ErrorActionPreference = "SilentlyContinue"

Write-Host "Wiping database to ensure a clean slate..."
docker exec cybersec-backend php artisan migrate:fresh --force

Write-Host "Starting background queue worker for scans..."
docker exec -d cybersec-backend php artisan queue:work --queue=remediation,default --tries=3 --timeout=180

Write-Host "Running full E2E interactions and capturing screenshots..."
$env:Path = "C:\Program Files\nodejs;" + $env:Path
$env:BASE_URL = "http://localhost:3000"
$env:CAPTURE_SCREENSHOTS = "1"
$env:SCREENSHOT_DIR = "c:\wamp64\www\cybersec-workspace-full\cybersec-workspace\platform\rapport\img"

npx playwright test

Write-Host "All tests completed and screenshots saved!"
