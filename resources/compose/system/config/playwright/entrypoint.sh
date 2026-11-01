#!/bin/sh
set -eu

if [ "${PLAYWRIGHT_SHOW:-0}" = "1" ]; then
    export DISPLAY=:99
    Xvfb "$DISPLAY" -screen 0 1440x900x24 -ac -nolisten tcp >/tmp/xvfb.log 2>&1 &
    sleep 1
    x11vnc -display "$DISPLAY" -forever -shared -nopw -rfbport 5900 >/tmp/x11vnc.log 2>&1 &
    websockify --web=/usr/share/novnc 7900 localhost:5900 >/tmp/novnc.log 2>&1 &
fi

exec "$@"
