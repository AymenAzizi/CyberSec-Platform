#!/usr/bin/env python3
"""Expand keyframes into video frames and encode with ffmpeg."""
from pathlib import Path
import subprocess
import shutil

KEYFRAMES_DIR = Path("/home/z/my-project/scripts/demo_keyframes")
EXPANDED_DIR = KEYFRAMES_DIR / "expanded"
VIDEO_OUT = Path("/home/z/my-project/download/demo_cybersec_platform.mp4")

HOLD_SECONDS = 1.6
FPS = 12

# ordered keyframes (must match the SCENES order in make_demo_video.py)
ORDERED = [
    "login_kf0",
    "register_kf0", "register_kf1",
    "new_project_kf0", "new_project_kf1", "new_project_kf2",
    "launch_scan_kf0", "launch_scan_kf1", "launch_scan_kf2", "launch_scan_kf3",
    "sandbox_kf0", "sandbox_kf1", "sandbox_kf2", "sandbox_kf3", "sandbox_kf4", "sandbox_kf5",
    "chat_kf0", "chat_kf1", "chat_kf2", "chat_kf3", "chat_kf4",
    "monitoring_kf0", "monitoring_kf1", "monitoring_kf2", "monitoring_kf3", "monitoring_kf4",
    "system_health_kf0", "system_health_kf1", "system_health_kf2", "system_health_kf3",
]

if EXPANDED_DIR.exists():
    shutil.rmtree(EXPANDED_DIR)
EXPANDED_DIR.mkdir(parents=True)

frame_idx = 0
hold_n = int(HOLD_SECONDS * FPS)
for name in ORDERED:
    src = KEYFRAMES_DIR / f"{name}.png"
    if not src.exists():
        print(f"MISSING: {src}")
        continue
    for _ in range(hold_n):
        dst = EXPANDED_DIR / f"frame_{frame_idx:06d}.png"
        shutil.copy(src, dst)
        frame_idx += 1
    print(f"  expanded {name} → {hold_n} frames (total: {frame_idx})")

print(f"\nTotal frames: {frame_idx}  ({frame_idx/FPS:.1f}s @ {FPS}fps)")

# Encode
print("\nEncoding with ffmpeg...")
cmd = [
    "ffmpeg", "-y",
    "-framerate", str(FPS),
    "-i", str(EXANDED_DIR := EXPANDED_DIR / "frame_%06d.png"),
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
    print("ffmpeg failed:", result.stderr)
    raise SystemExit(1)

size_mb = VIDEO_OUT.stat().st_size // (1024 * 1024)
print(f"\nOK: {VIDEO_OUT}")
print(f"     {size_mb} MB, {frame_idx/FPS:.1f}s, {FPS}fps, 1280x800 H.264")
