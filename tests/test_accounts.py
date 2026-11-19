"""Integration checks against a LOCAL PHP server and a disposable MySQL database.

Run: python tests/test_accounts.py
The default local endpoint is http://127.0.0.1:8087. Do not point this at production.
"""
import http.cookiejar
import json
import unittest
import urllib.error
import urllib.request
import uuid

BASE = 'http://127.0.0.1:8087/api/account.php?action='


class Client:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.http = urllib.request.build_opener(
            urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(self.jar))
        self.token = ''

    def call(self, action, data=None, csrf=None):
        headers = {}
        if data is not None:
            headers = {'Content-Type': 'application/json',
                       'X-CSRF-Token': self.token if csrf is None else csrf}
        request = urllib.request.Request(BASE + action, headers=headers,
            data=None if data is None else json.dumps(data).encode())
        try:
            response = self.http.open(request, timeout=10)
        except urllib.error.HTTPError as error:
            response = error
        try:
            body = json.load(response)
        finally:
            response.close()
        if 'csrfToken' in body:
            self.token = body['csrfToken']
        return response.status, body

    def session_id(self):
        return next((cookie.value for cookie in self.jar if cookie.name == 'HAL_SESSION'), '')


class AccountIntegration(unittest.TestCase):
    def test_cookie_and_cache_headers(self):
        http = urllib.request.build_opener(urllib.request.ProxyHandler({}))
        request = urllib.request.Request(BASE + 'session', headers={'Host': 'account.example.test'})
        with http.open(request, timeout=10) as response:
            cookie = response.headers.get('Set-Cookie', '').lower()
            self.assertIn('secure', cookie)
            self.assertIn('httponly', cookie)
            self.assertIn('samesite=lax', cookie)
            self.assertIn('no-store', response.headers.get('Cache-Control', ''))

    def test_account_lifecycle_and_isolation(self):
        a, b = Client(), Client()
        code, result = a.call('session')
        self.assertEqual(code, 200, result)
        self.assertIsNone(result['user'])
        original_id, old_token = a.session_id(), a.token
        email = 'account-' + uuid.uuid4().hex[:10] + '@example.test'
        password = 'A-test-password-9041!'
        code, result = a.call('register', {'name': 'Account One', 'email': email, 'password': password})
        self.assertEqual(code, 201, result)
        self.assertNotEqual(a.session_id(), original_id)
        self.assertNotEqual(a.token, old_token)
        user_a = result['user']['id']
        self.assertNotIn('password_hash', result['user'])
        self.assertEqual(a.call('profile', {'name': 'No update'}, csrf=old_token)[0], 403)
        payload = {'name': 'Updated Account', 'phone': '9876543210',
                   'address': 'Test address', 'city': 'Noida', 'pincode': '201308'}
        code, result = a.call('profile', payload)
        self.assertEqual(code, 200, result)
        self.assertEqual(result['user']['phone'], '+919876543210')
        self.assertEqual(a.call('session')[1]['user']['address'], 'Test address')
        self.assertEqual(a.call('profile', {**payload, 'pincode': '12'})[0], 422)
        self.assertEqual(a.call('profile', {**payload, 'phone': '1234567890'})[0], 422)
        self.assertIsNone(b.call('session')[1]['user'])
        self.assertEqual(b.call('profile', payload)[0], 401)
        self.assertEqual(b.call('login', {'email': email, 'password': 'Wrong-password-000!'})[0], 401)
        code, result = b.call('register', {'name': 'Account Two',
            'email': 'second-' + uuid.uuid4().hex[:10] + '@example.test', 'password': password})
        self.assertEqual(code, 201, result)
        self.assertEqual(b.call('profile', {**payload, 'name': 'Second Profile', 'id': user_a})[0], 200)
        self.assertEqual(a.call('session')[1]['user']['name'], 'Updated Account')
        self.assertEqual(a.call('logout', {})[0], 200)
        self.assertIsNone(a.call('session')[1]['user'])
        self.assertEqual(a.call('profile', payload)[0], 401)
        self.assertEqual(a.call('login', {'email': email, 'password': password})[0], 200)
        self.assertEqual(a.call('session')[1]['user']['name'], 'Updated Account')
        self.assertEqual(a.call('register', {'name': 'Duplicate', 'email': email, 'password': password})[0], 409)
        self.assertEqual(a.call('profile')[0], 405)
        for _ in range(12):
            code, _ = a.call('login', {'email': email, 'password': 'Wrong-password-000!'})
            if code == 429:
                break
        self.assertEqual(code, 429)
        self.assertEqual(a.call('session')[1]['user']['name'], 'Updated Account')


if __name__ == '__main__':
    unittest.main(verbosity=2)
