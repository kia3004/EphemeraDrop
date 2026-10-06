import test from 'node:test';
import assert from 'node:assert/strict';
import { decryptFileChunk, decryptTextPayload, encryptFileChunk, encryptTextPayload, generateRandomKey, importKey, unwrapContentKey, wrapContentKey } from '../../resources/js/crypto.js';

test('encrypts and decrypts text payloads', async () => {
  const key = await generateRandomKey();
  const encrypted = await encryptTextPayload('hello', key);
  const roundTripKey = await importKey(await crypto.subtle.exportKey('raw', key));
  const value = await decryptTextPayload(encrypted, roundTripKey);
  assert.equal(value.body, 'hello');
});

test('detects tampered file chunks', async () => {
  const key = await generateRandomKey();
  const encrypted = await encryptFileChunk(new TextEncoder().encode('chunk-data'), key, 0, 2);
  const tampered = encrypted.slice();
  tampered[16] ^= 1;
  await assert.rejects(async () => {
    await decryptFileChunk(tampered, key, 0, 2);
  });
});

test('detects chunk reorder attempts', async () => {
  const key = await generateRandomKey();
  const one = await encryptFileChunk(new TextEncoder().encode('one'), key, 0, 2);
  const two = await encryptFileChunk(new TextEncoder().encode('two'), key, 1, 2);
  await assert.rejects(async () => decryptFileChunk(two, key, 0, 2));
  const data = await decryptFileChunk(one, key, 0, 2);
  assert.equal(new TextDecoder().decode(data), 'one');
});

test('wraps and unwraps content keys with a password', async () => {
  const contentKey = await generateRandomKey();
  const password = 'correct horse battery staple';
  const salt = 'AAAAAAAAAAAAAAAAAAAAAA';
  const wrapped = await wrapContentKey(contentKey, password, salt);
  const unwrapped = await unwrapContentKey(wrapped, password, salt);
  const encrypted = await encryptTextPayload('password protected', contentKey);
  const value = await decryptTextPayload(encrypted, unwrapped);
  assert.equal(value.body, 'password protected');
  await assert.rejects(async () => unwrapContentKey(wrapped, 'wrong password', salt));
});
