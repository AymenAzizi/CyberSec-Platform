#!/usr/bin/env python3
"""
Capture LIVE screenshots of the REAL working CyberSec Platform.
Logs in as admin, navigates to each page, and captures full-page screenshots.
These screenshots show REAL data (real scans, real findings, real AI chat, real sandbox).
"""
import asyncio
import os
import time
from pathlib import Path
from playwright.async_api import async_playwright

BASE_URL = "http://127.0.0.1:8000"
OUTPUT_DIR = Path("/home/z/my-project/download")
OUTPUT_DIR.mkdir(parents=True, exist_ok=True)

CREDENTIALS = {"email": "admin@cybersec.local", "password": "password"}


async def screenshot_page(page, name: str, url: str = None, wait: int = 2):
    """Navigate to URL (if given) and capture a full-page screenshot."""
    if url:
        await page.goto(f"{BASE_URL}{url}", wait_until="networkidle", timeout=30000)
    await page.wait_for_timeout(wait * 1000)
    out_path = OUTPUT_DIR / f"LIVE2-{name}.png"
    await page.screenshot(path=str(out_path), full_page=True)
    size = out_path.stat().st_size // 1024
    print(f"  [OK] LIVE2-{name}.png ({size} KB)")
    return out_path


async def main():
    async with async_playwright() as p:
        browser = await p.chromium.launch(headless=True)
        context = await browser.new_context(
            viewport={"width": 1440, "height": 900},
            device_scale_factor=2,
        )
        page = await context.new_page()

        print("=== Logging in as admin ===")
        await page.goto(f"{BASE_URL}/login", wait_until="networkidle")
        await page.fill('input[name="email"]', CREDENTIALS["email"])
        await page.fill('input[name="password"]', CREDENTIALS["password"])
        await page.click('button[type="submit"]')
        # Wait for redirect (login redirects to /dashboard or /)
        await page.wait_for_timeout(5000)
        # Make sure we're on the dashboard
        if "/login" in page.url:
            await page.goto(f"{BASE_URL}/dashboard", wait_until="networkidle")
            await page.wait_for_timeout(2000)
        print(f"  Current URL: {page.url}")
        print("  Logged in successfully")

        print("\n=== Capturing screenshots ===")
        await screenshot_page(page, "dashboard", "/dashboard", wait=3)
        await screenshot_page(page, "scans", "/scans", wait=2)
        await screenshot_page(page, "scan-detail", "/scans/22", wait=3)
        await screenshot_page(page, "reports", "/reports", wait=2)
        await screenshot_page(page, "report-view", "/reports/22", wait=3)
        await screenshot_page(page, "knowledge-graph", "/projects/1/graph", wait=4)
        await screenshot_page(page, "osint", "/osint", wait=2)
        await screenshot_page(page, "alerts", "/security/alerts", wait=2)
        await screenshot_page(page, "sandbox", "/security/sandbox", wait=2)
        await screenshot_page(page, "monitoring", "/security/monitoring", wait=2)
        await screenshot_page(page, "projects", "/projects", wait=2)
        await screenshot_page(page, "new-project", "/projects/create", wait=2)
        await screenshot_page(page, "new-scan", "/scans/create", wait=2)
        await screenshot_page(page, "audit-logs", "/admin/audit-logs", wait=2)
        await screenshot_page(page, "system-health", "/admin/system-health", wait=2)
        await screenshot_page(page, "users", "/admin/users", wait=2)
        await screenshot_page(page, "chat", "/chat", wait=2)

        # Capture the chatbot in action (floating chat panel)
        print("\n=== Capturing chatbot with real AI response ===")
        await page.goto(f"{BASE_URL}/dashboard", wait_until="networkidle")
        await page.wait_for_timeout(2000)
        # Click the floating chatbot button
        try:
            await page.click('#chatbot-fab', timeout=5000)
            await page.wait_for_timeout(1000)
            # Type a question
            await page.fill('#chatbot-input', 'What is SQL injection and how do I prevent it?')
            await page.wait_for_timeout(500)
            # Submit
            await page.click('#chatbot-form button[type="submit"]')
            # Wait for AI response (z-ai CLI takes ~10-20s)
            print("  Waiting for AI response (up to 60s)...")
            await page.wait_for_timeout(45000)
            await screenshot_page(page, "chatbot-ai-response", None, wait=2)
        except Exception as e:
            print(f"  Chatbot capture failed: {e}")

        # Capture a remediation page
        print("\n=== Capturing remediation page ===")
        await screenshot_page(page, "remediation", "/findings/209/remediation", wait=3)

        await browser.close()
        print(f"\n=== All screenshots saved to {OUTPUT_DIR} ===")


if __name__ == "__main__":
    asyncio.run(main())
