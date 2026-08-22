$ErrorActionPreference = "SilentlyContinue"

Write-Host "Waiting for Docker Desktop engine to start..."
while ($true) {
    $res = docker ps -q 2>&1
    if ($LASTEXITCODE -eq 0) {
        break
    }
    Start-Sleep -Seconds 3
}

Write-Host "Docker is up. Starting platform..."
docker compose --env-file .env.docker up -d
Start-Sleep -Seconds 15

Write-Host "Starting background queue worker..."
docker compose --env-file .env.docker exec -d backend php artisan queue:work --queue=remediation,default --tries=3 --timeout=180

Write-Host "Running full Playwright test suite..."
$env:Path = "C:\Program Files\nodejs;" + $env:Path
$env:BASE_URL = "http://localhost:3000"
npx playwright test

Write-Host "Done!"
