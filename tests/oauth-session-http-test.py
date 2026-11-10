"""Exercise actual PHP cookie sessions locally; no WordPress or provider calls."""
import http.cookiejar
import json
import os
from pathlib import Path
import socket
import subprocess
import tempfile
import time
import urllib.request

root = Path(__file__).resolve().parent.parent
with tempfile.TemporaryDirectory(prefix="authorizenter-session-") as tmp:
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        port = sock.getsockname()[1]
    env = dict(os.environ, AUTHORIZENTER_TEST_FLOW_STORE=str(Path(tmp) / "flows.json"))
    with open(Path(tmp) / "server.log", "w") as log:
        server = subprocess.Popen(
            ["php", "-d", f"session.save_path={tmp}", "-S", f"127.0.0.1:{port}",
             str(root / "tests/fixtures/oauth-session-router.php")],
            cwd=root, env=env, stdout=log, stderr=log,
        )
        try:
            for _ in range(100):
                try:
                    with socket.create_connection(("127.0.0.1", port), timeout=0.1):
                        break
                except OSError:
                    time.sleep(0.02)
            base = f"http://127.0.0.1:{port}/"
            owner = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
            foreign = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

            def request(browser, state=None):
                with browser.open(base + ("?state=" + state if state else ""), timeout=5) as response:
                    return json.load(response), response.headers

            first, headers = request(owner)
            cookie = headers.get("Set-Cookie", "").lower()
            assert "httponly" in cookie and "samesite=lax" in cookie, cookie
            state1 = first["state"]
            state2 = request(owner)[0]["state"]
            assert state1 != state2
            assert request(foreign, state1)[0]["result"] == "authorizenter_state_invalid"
            assert request(owner, state2)[0]["result"] == "exchange_reached"
            assert request(owner, state1)[0]["result"] == "exchange_reached"
            assert request(owner, state1)[0]["result"] == "authorizenter_state_invalid"
            print("PASS: real PHP HttpOnly/SameSite cookie, foreign browser rejection, owner return, parallel tabs, single-use replay")
        finally:
            server.terminate()
            server.wait(timeout=5)
