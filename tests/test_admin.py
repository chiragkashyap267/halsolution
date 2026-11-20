"""Integration checks for the disposable local PHP preview; never run on production."""
import copy
import http.cookiejar
import json
import os
from pathlib import Path
import sys
import urllib.error
import urllib.parse
import urllib.request

base = sys.argv[1].rstrip('/')
assert urllib.parse.urlparse(base).hostname in ('127.0.0.1', 'localhost'), 'Local preview only'
root = Path(__file__).resolve().parents[1]
storage = root / '.local-tests' / 'admin-data'
assert storage.is_dir(), 'Start preview with the documented disposable content directory'
password = os.environ['HAL_TEST_ADMIN_PASSWORD']
content_file = storage / 'content.json'
backup = content_file.read_bytes() if content_file.exists() else None
identity_file = storage / 'admin.json'
identity_backup = identity_file.read_bytes() if identity_file.exists() else None
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
token = ''
checks = 0
uploaded = None

def call(action, data=None, status=200, csrf=True, extra=None):
    global checks, token
    headers = {} if data is None else {'Content-Type': 'application/json'}
    if csrf and token: headers['X-CSRF-Token'] = token
    headers.update(extra or {})
    request = urllib.request.Request(base + '/api/admin.php?action=' + action,
        data=None if data is None else json.dumps(data).encode(), headers=headers)
    try:
        response = client.open(request)
    except urllib.error.HTTPError as error:
        response = error
    assert response.code == status, (action, response.code, response.read().decode())
    result = json.load(response)
    token = result.get('csrfToken', token)
    checks += 1
    return result

try:
    call('content', status=401)
    call('session')
    call('login', {'password': password}, status=403, csrf=False)
    call('login', {'password': 'incorrect-local-test'}, status=401)
    call('login', {'password': password})
    content = call('content')
    original = copy.deepcopy(content['products'][0])
    product = {**original, 'price': 17.25, 'badge': 'Fresh rate'}
    saved = call('product', {'revision': content['revision'], 'product': product})
    assert saved['products'][0]['price'] == 17.25
    public = json.load(urllib.request.urlopen(base + '/api/content.php'))
    assert public['products'][0]['price'] == 17.25
    assert 'hash' not in json.dumps(public) and 'csrf' not in json.dumps(public)
    call('product', {'revision': content['revision'], 'product': original}, status=409)
    call('product', {'revision': saved['revision'], 'product': {**product, 'price': -1}}, status=422)
    call('product', {'revision': saved['revision'], 'product': {**product, 'img': 'javascript:alert(1)'}}, status=422)
    call('product', {'revision': saved['revision'], 'product': product}, status=403, extra={'Origin': 'https://attacker.example'})
    added = call('product', {'revision': saved['revision'], 'product': {**product, 'id': '', 'name': 'Local test item "quoted" <tag>', 'active': False}})
    assert len(added['products']) == len(content['products']) + 1
    assert len(json.load(urllib.request.urlopen(base + '/api/content.php'))['products']) == len(content['products'])
    settings = {**content['settings'], 'phone': '+919876543210', 'heroTitle': 'Local preview banner'}
    changed = call('settings', {'revision': added['revision'], 'settings': settings})
    assert changed['settings']['heroTitle'] == settings['heroTitle']
    call('settings', {'revision': changed['revision'], 'settings': {**settings, 'phone': 'bad-number'}}, status=422)
    # A real multipart image succeeds; SVG and executable payloads are rejected.
    def upload(filename, mime, body, expected):
        global checks, uploaded
        boundary = 'HalLocalTestBoundary'
        payload = (f'--{boundary}\r\nContent-Disposition: form-data; name="image"; filename="{filename}"\r\nContent-Type: {mime}\r\n\r\n').encode() + body + f'\r\n--{boundary}--\r\n'.encode()
        request = urllib.request.Request(base + '/api/admin.php?action=upload', data=payload,
            headers={'X-CSRF-Token': token, 'Content-Type': 'multipart/form-data; boundary=' + boundary})
        try: response = client.open(request)
        except urllib.error.HTTPError as error: response = error
        assert response.code == expected
        result = json.load(response)
        if response.code == 200:
            uploaded = root / result['url']
            assert uploaded.parent == root / 'images' / 'uploads' and uploaded.exists()
        checks += 1
    upload('bad.php', 'image/png', b'<?php echo 1;', 422)
    upload('bad.svg', 'image/svg+xml', b'<svg xmlns="http://www.w3.org/2000/svg"></svg>', 422)
    upload('logo.png', 'image/png', (root / 'images' / 'logo.png').read_bytes(), 200)
    call('password', {'currentPassword': 'incorrect-local-test', 'newPassword': 'local-test-new-password'}, status=422)
    call('password', {'currentPassword': password, 'newPassword': 'local-test-new-password'})
    call('logout', {})
    call('content', status=401)
    call('session')
    call('login', {'password': password}, status=401)
    call('login', {'password': 'local-test-new-password'})
    call('logout', {})
    call('session')
    for _ in range(5): call('login', {'password': 'incorrect-local-test'}, status=401)
    call('login', {'password': password}, status=429)
    print(f'Admin integration checks passed: {checks}; price persistence, visibility, concurrency, CSRF, origin, uploads, rate limits, logout.')
finally:
    if backup is None: content_file.unlink(missing_ok=True)
    else: content_file.write_bytes(backup)
    if identity_backup is None: identity_file.unlink(missing_ok=True)
    else: identity_file.write_bytes(identity_backup)
    if uploaded: uploaded.unlink(missing_ok=True)
    # Only the disposable loopback-IP limiter is reset for subsequent manual preview.
    import hashlib
    (storage / ('login-' + hashlib.sha256(b'127.0.0.1').hexdigest() + '.json')).unlink(missing_ok=True)
