#!/usr/bin/env python3
import os
import sys

hook_type = sys.argv[1] if len(sys.argv) > 1 else ""
event = sys.argv[2] if len(sys.argv) > 2 else ""
project = ""
skip_next = False
for arg in sys.argv[3:]:
    if skip_next:
        skip_next = False
        continue
    if arg in {"--language", "--framework"}:
        skip_next = True
        continue
    if arg.startswith(("--language=", "--framework=")) or arg in {"--no-restart", "--force"}:
        continue
    if arg.startswith("--"):
        continue
    project = arg
    break
project = project or os.path.basename(os.getcwd())

if event in {"project:up:before", "project:up:after"}:
    print(f"Хук {hook_type} видит, что проект {project} запущен.")
else:
    print(f"Команда {event} не поддерживается этим хуком.")
