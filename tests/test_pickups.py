"""Local integration suite. Uses XAMPP PHP/MariaDB and fake local providers only.

Run: python tests/test_pickups.py
No live SMS requests are made. API copies replace only the OTP provider URL.
WhatsApp is a customer-initiated link; no WhatsApp API is called.
Uses the existing disposable .local-tests/mysql data directory on port 3307.
"""
import http.cookiejar
import json
import os
from pathlib import Path
import shutil
import socket
import sys
import subprocess
import time
import unittest
import urllib.error
import urllib.request
import uuid

ROOT = Path(__file__).resolve().parents[1]
RUN = ROOT / '.local-tests' / 'pickup-checks'
PHP = Path(os.environ.get('HAL_TEST_PHP', 'C:/xampp/php/php.exe'))
MYSQL = Path(os.environ.get('HAL_TEST_MYSQL', 'C:/xampp/mysql/bin/mysql.exe'))
MYSQLD = MYSQL.with_name('mysqld.exe')
DB_NAME = 'hal_pickup_check_' + uuid.uuid4().hex[:8]


def sql(statement):
    return subprocess.check_output([str(MYSQL), '--no-defaults', '-h127.0.0.1', '-P3307', '-uroot', '-N', '-B', '-e', statement], text=True).strip()


def wait_port(port):
    for _ in range(100):
        try:
            with socket.create_connection(('127.0.0.1', port), timeout=.2):
                return
        except OSError:
            time.sleep(.1)
    raise RuntimeError(f'Local service {port} did not start')


class Client:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.http = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(self.jar))
        self.csrf = ''

    def call(self, action, data=None, token=None, account=False):
        endpoint = 'account' if account else 'pickup'
        request = urllib.request.Request(f'http://127.0.0.1:8087/api/{endpoint}.php?action={action}',
            data=None if data is None else json.dumps(data).encode(),
            headers={} if data is None else {'Content-Type': 'application/json', 'X-CSRF-Token': self.csrf if token is None else token})
        try:
            response = self.http.open(request, timeout=35)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            result = json.load(response)
            self.csrf = result.get('csrfToken', self.csrf)
            return response.status, result


class Pickups(unittest.TestCase):
    def verify(self, phone):
        client = Client()
        self.assertEqual(client.call('session')[0], 200)
        old_csrf = client.csrf
        self.assertEqual(client.call('send-otp', {'phone': phone}, token='bad')[0], 403)
        self.assertEqual(client.call('send-otp', {'phone': phone})[0], 200)
        codes = [json.loads(line) for line in (RUN / 'sms.jsonl').read_text().splitlines()]
        code = next(item['otp'] for item in reversed(codes) if item['mobile'] == '91' + phone)
        self.assertEqual(client.call('verify-otp', {'code': '000000'})[0], 422)
        status, result = client.call('verify-otp', {'code': code})
        self.assertEqual(status, 200, result)
        self.assertNotEqual(client.csrf, old_csrf)
        self.assertEqual(result['verifiedPhone'], '+91' + phone)
        self.assertEqual(client.call('verify-otp', {'code': code})[0], 422)
        return client, result['user']

    def test_complete_flow(self):
        a, user = self.verify('9000000101')
        b, _ = self.verify('9000000102')
        payload = {'name': 'Test Customer', 'items': [{'category': 'paper', 'quantity': 2.5}],
            'address': 'Synthetic test address', 'city': 'Noida', 'pincode': '201308', 'date': '',
            'slot': 'flexible', 'notes': '<script>test</script>', 'request_key': uuid.uuid4().hex}
        self.assertEqual(Client().call('orders')[0], 401)
        self.assertEqual(a.call('create', {**payload, 'items': []})[0], 422)
        self.assertEqual(a.call('create', {**payload, 'items': [{'category': 'ewaste', 'quantity': 1.5}]})[0], 422)
        self.assertEqual(a.call('create', {**payload, 'latitude': 91, 'longitude': 10})[0], 422)
        self.assertEqual(a.call('create', {**payload, 'address': '', 'city': '', 'pincode': ''})[0], 422)
        self.assertEqual(a.call('create', {**payload, 'date': '2020-01-01'})[0], 422)
        status, result = a.call('create', payload)
        self.assertEqual(status, 201, result)
        reference = result['order']['reference']
        self.assertNotIn('notifications', result['order'])
        self.assertEqual(a.call('create', payload)[1]['order']['reference'], reference)
        self.assertEqual((RUN / 'whatsapp.jsonl').read_text(), '')
        self.assertEqual(len(a.call('orders')[1]['orders']), 1)
        self.assertEqual(b.call('orders')[1]['orders'], [])
        self.assertEqual(a.call('profile', {'name': 'Changed', 'phone': '9000000999'}, account=True)[0], 422)
        self.assertEqual(a.call('logout', {}, account=True)[0], 200)
        self.assertEqual(a.call('orders')[0], 401)
        # Re-sign-in to the same verified phone uses the same account.
        sql(f"DELETE FROM {DB_NAME}.hal_auth_limits WHERE 1=1")
        sql(f"UPDATE {DB_NAME}.hal_users SET name = 'Saved Name' WHERE id = {user['id']}")
        c, same = self.verify('9000000101')
        self.assertEqual(same['id'], user['id'])
        self.assertEqual(same['name'], 'Saved Name')
        self.assertEqual(len(c.call('orders')[1]['orders']), 1)
        # A receipt is private, printable, and escapes customer-provided content.
        url = f'http://127.0.0.1:8087/api/receipt.php?reference={reference}'
        with c.http.open(url) as response:
            receipt = response.read().decode()
            self.assertIn('attachment;', response.headers['Content-Disposition'])
            self.assertIn('Pickup order receipt', receipt)
            self.assertIn('&lt;script&gt;test&lt;/script&gt;', receipt)
            self.assertNotIn('<script>test</script>', receipt)
            self.assertIn(json.loads((ROOT / 'business-config.json').read_text())['phone'], receipt)
        with self.assertRaises(urllib.error.HTTPError) as denied:
            b.http.open(url)
        self.assertEqual(denied.exception.code, 404)
        with self.assertRaises(urllib.error.HTTPError) as denied:
            Client().http.open(url)
        self.assertEqual(denied.exception.code, 401)
        # A valid GPS pin can replace the typed address.
        pin_payload = {**payload, 'request_key': uuid.uuid4().hex, 'address': '', 'city': '', 'pincode': '', 'latitude': 28.5, 'longitude': 77.4, 'accuracy': 10}
        status, result = c.call('create', pin_payload)
        self.assertEqual(status, 201, result)
        self.assertEqual(float(result['order']['latitude']), 28.5)
        self.assertEqual(sql(f"SELECT COUNT(*) FROM {DB_NAME}.hal_pickups"), '2')
        self.assertEqual((RUN / 'whatsapp.jsonl').read_text(), '')

    def test_otp_attempt_limit_and_cooldown(self):
        client = Client(); client.call('session')
        self.assertEqual(client.call('send-otp', {'phone': '123'})[0], 422)
        self.assertEqual(client.call('send-otp', {'phone': '9000000103'})[0], 200)
        self.assertEqual(client.call('send-otp', {'phone': '9000000103'})[0], 429)
        for _ in range(5):
            self.assertEqual(client.call('verify-otp', {'code': '000000'})[0], 422)
        self.assertEqual(client.call('verify-otp', {'code': '000000'})[0], 422)
        self.assertIsNone(client.call('session')[1]['verifiedPhone'])


if __name__ == '__main__':
    RUN.mkdir(parents=True, exist_ok=True)
    app = RUN / 'app'; (app / 'api').mkdir(parents=True, exist_ok=True)
    for path in (ROOT / 'api').glob('*.php'):
        if path.name == 'config.local.php':
            continue
        text = path.read_text(encoding='utf-8')
        text = text.replace('https://control.msg91.com/api/v5/otp?', 'http://127.0.0.1:8088/otp?')
        (app / 'api' / path.name).write_text(text, encoding='utf-8')
    (app / 'images').mkdir(exist_ok=True)
    shutil.copy2(ROOT / 'images/logo.png', app / 'images/logo.png')
    for pattern in ('*.html', '*.css', '*.js', 'business-config.json'):
        for path in ROOT.glob(pattern):
            shutil.copy2(path, app / path.name)
    (RUN / 'hal-account-config.php').write_text("<?php return ['host'=>'127.0.0.1','port'=>3307,'database'=>'" + DB_NAME + "','username'=>'root','password'=>'','otp_authkey'=>'fake','otp_template_id'=>'fake','otp_daily_limit'=>100];", encoding='utf-8')
    for name in ('sms.jsonl', 'whatsapp.jsonl'):
        (RUN / name).write_text('')
    (RUN / 'provider.php').write_text('''<?php
header('Content-Type: application/json');
if (str_starts_with($_SERVER['REQUEST_URI'], '/otp?')) {
 file_put_contents(__DIR__.'/sms.jsonl', json_encode($_GET)."\\n", FILE_APPEND);
 echo json_encode(['type'=>'success','request_id'=>'test']);
} else {
 parse_str(file_get_contents('php://input'), $body);
 file_put_contents(__DIR__.'/whatsapp.jsonl', json_encode($body)."\\n", FILE_APPEND);
 http_response_code(500); echo '{}';
}
''', encoding='utf-8')
    processes = []
    log = (RUN / 'services.log').open('w')
    try:
        processes.append(subprocess.Popen([str(MYSQLD), '--no-defaults', '--basedir=C:/xampp/mysql', '--datadir=' + str(ROOT / '.local-tests/mysql'), '--port=3307', '--bind-address=127.0.0.1', '--console'], stdout=log, stderr=log))
        wait_port(3307)
        if processes[0].poll() is not None:
            raise RuntimeError('Test database port already in use; refusing to use an unowned database server.')
        sql(f'CREATE DATABASE {DB_NAME}')
        processes.append(subprocess.Popen([str(PHP), '-S', '127.0.0.1:8088', str(RUN / 'provider.php')], stdout=log, stderr=log))
        processes.append(subprocess.Popen([str(PHP), '-d', 'session.save_path=' + str(ROOT / '.local-tests/sessions'), '-S', '127.0.0.1:8087', '-t', str(app)], stdout=log, stderr=log))
        wait_port(8088); wait_port(8087)
        result = unittest.TextTestRunner(verbosity=2).run(unittest.defaultTestLoader.loadTestsFromTestCase(Pickups))
        if result.wasSuccessful():
            subprocess.check_call(['python', str(ROOT / 'tests/test_accounts.py')])
            if '--preview' in sys.argv:
                print('Synthetic-provider preview: http://127.0.0.1:8087/booking.html', flush=True)
                input('Press Enter to stop preview and clean up.\n')
        raise SystemExit(0 if result.wasSuccessful() else 1)
    finally:
        if processes and processes[0].poll() is None:
            try:
                sql(f'DROP DATABASE IF EXISTS {DB_NAME}')
            except subprocess.CalledProcessError:
                pass
        for process in reversed(processes):
            if process.poll() is None:
                process.terminate()
                try: process.wait(timeout=10)
                except subprocess.TimeoutExpired: process.kill()
        log.close()
