#!/usr/bin/env python3
"""
Demo video generator for the CyberSec Platform - Efficient version.

Strategy:
1. For each scene, capture N "key" screenshots at different scroll positions
2. Each key screenshot is held for ~0.4s in the final video
3. ffmpeg duplicates each keyframe to make smooth video at 12fps

This is much faster than capturing every frame.
"""
from playwright.sync_api import sync_playwright
from pathlib import Path
import subprocess
import sys
import shutil
import os

SCRIPT_DIR = Path(__file__).parent.resolve()
HTML_DIR = SCRIPT_DIR / "screenshots"
KEYFRAMES_DIR = Path("/home/z/my-project/scripts/demo_keyframes")
VIDEO_OUT = Path("/home/z/my-project/download/demo_cybersec_platform.mp4")

# Each scene: (html_file, scroll_fractions)
# scroll_fractions = list of fractional scroll positions to capture (0.0 = top, 1.0 = bottom)
SCENES = [
    ("login.html",         [0.0]),                                   # 1 keyframe
    ("register.html",      [0.0, 0.5]),                              # 2 keyframes
    ("new_project.html",   [0.0, 0.4, 0.8]),                         # 3 keyframes
    ("launch_scan.html",   [0.0, 0.3, 0.6, 0.9]),                    # 4 keyframes
    ("sandbox.html",       [0.0, 0.2, 0.4, 0.6, 0.8, 1.0]),          # 6 keyframes (most important — exploit demo)
    ("chat.html",          [0.0, 0.25, 0.5, 0.75, 1.0]),             # 5 keyframes (AI conversation)
    ("monitoring.html",    [0.0, 0.25, 0.5, 0.75, 1.0]),             # 5 keyframes (events stream)
    ("system_health.html", [0.0, 0.3, 0.6, 1.0]),                    # 4 keyframes (services)
]

HOLD_SECONDS = 1.6   # how long each keyframe is held
TRANSITION_SECONDS = 0.4  # fade between keyframes
FPS = 12
VIEWPORT_W = 1440
VIEWPORT_H = 900


def main() -> int:
    if KEYFRAMES_DIR.exists():
        shutil.rmtree(KEYFRAMES_DIR)
    KEYFRAMES_DIR.mkdir(parents=True)

    VIDEO_OUT.parent.mkdir(parents=True, exist_ok=True)

    keyframes = []  # list of (path, label)
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        context = browser.new_context(
            viewport={"width": VIEWPORT_W, "height": VIEWPORT_H},
            device_scale_factor=1,
        )
        page = context.new_page()

        for html_name, scroll_fracs in SCENES:
            html_path = HTML_DIR / html_name
            if not html_path.exists():
                print(f"  [SKIP] {html_name} not found", file=sys.stderr)
                continue

            print(f"  [SCENE] {html_name}  ({len(scroll_fracs)} keyframes)", flush=True)
            page.set_viewport_size({"width": VIEWPORT_W, "height": VIEWPORT_H})
            page.goto(f"file://{html_path}", wait_until="networkidle")
            page.wait_for_timeout(800)

            try:
                total_h = page.evaluate("() => Math.max(document.body.scrollHeight, document.documentElement.scrollHeight)")
            except Exception:
                total_h = VIEWPORT_H
            max_scroll = max(0, total_h - VIEWPORT_H)

            for i, frac in enumerate(scroll_fracs):
                target = int(max_scroll * frac)
                page.evaluate(f"window.scrollTo(0, {target})")
                page.wait_for_timeout(400)
                label = f"{html_name.replace('.html','')}_kf{i}"
                path = KEYFRAMES_DIR / f"{label}.png"
                page.screenshot(path=str(path), full_page=False)
                keyframes.append((path, label))
                print(f"     - {label} (scroll={target}px)", flush=True)

        browser.close()

    print(f"  [INFO] {len(keyframes)} keyframes captured", flush=True)

    # Build a frame list: each keyframe repeated HOLD_SECONDS * FPS times
    # with a fade transition between consecutive keyframes
    frames_list_path = KEYFRAMES_DIR / "frames.txt"
    with open(frames_list_path, "w") as f:
        for idx, (path, label) in enumerate(keyframes):
            hold_n = int(HOLD_SECONDS * FPS)
            for _ in range(hold_n):
                f.write(f"file '{path.absolute()}'\n")
                f.write(f"duration {1.0/FPS:.4f}\n")
            # Last frame needs one extra duration entry to flush
            f.write(f"file '{path.absolute()}'\n")
            f.write(f"duration {0.01:.4f}\n")

    # Use ffmpeg concat demuxer with image2
    # Simpler approach: use -loop 1 -t per image and concat
    # But easiest is to use a Python script to build the video frame-by-frame

    # Simplest: use ffmpeg with image2 + tile
    # Actually let me just duplicate keyframes into actual frame files
    frames_dir = KEYFRAMES_DIR / "expanded"
    frames_dir.mkdir(exist_ok=True)
    frame_idx = 0
    for path, label in keyframes:
        for _ in range(int(HOLD_SECONDS * FPS)):
            dst = frames_dir / f"frame_{frame_idx:06d}.png"
            shutil.copy(path, dst)
            frame_idx += 1
    print(f"  [INFO] {frame_idx} expanded frames", flush=True)

    # Encode
    print("  [ENCODE] ffmpeg H.264 720p...", flush=True)
    cmd = [
        "ffmpeg", "-y",
        "-framerate", str(FPS),
        "-i", str(frames_dir / "frame_%06d.png"),
        "-vf", f"scale=1280:-2,fps={FPS},format=yuv420p",
        "-c:v", "libx264",
        "-preset", "fast",
        "-crf", "24",
        "-movflags", "+faststart",
        "-loglevel", "error",
        str(VIDEO_OUT),
    ]
    result = subprocess.run(cmd, capture_output=True, text=True)
    if result.returncode != 0:
        print("ffmpeg failed:", result.stderr, file=sys.stderr)
        return 1

    size_mb = VIDEO_OUT.stat().st_size // (1024 * 1024)
    duration_s = frame_idx / FPS
    print(f"  [OK] {VIDEO_OUT}", flush=True)
    print(f"       {size_mb} MB, {duration_s:.1f}s, {FPS}fps", flush=True)

    # Cleanup intermediate frames (keep keyframes for debugging)
    shutil.rmtree(frames_dir, ignore_errors=True)
    return 0


if __name__ == "__main__":
    sys.exit(main())
