'use strict';
// Codec tests for public/assets/js/passkey.js (exported via module.exports for
// node --test; in the browser the same file wires the WebAuthn flows).
const test = require('node:test');
const assert = require('node:assert/strict');
const crypto = require('crypto');
const { b64uToBuf, bufToB64 } = require('../../public/assets/js/passkey.js');

test('b64uToBuf decodes base64url with and without padding', () => {
    assert.deepEqual(Array.from(b64uToBuf('AQIDBA')), [1, 2, 3, 4]);
    assert.deepEqual(Array.from(b64uToBuf('AQIDBA==')), [1, 2, 3, 4]);
    // URL-safe alphabet: '-' → '+', '_' → '/'
    assert.deepEqual(Array.from(b64uToBuf('-_8')), Array.from(Buffer.from('+/8=', 'base64')));
});

test('bufToB64 emits standard base64 (what base64_decode(strict) expects)', () => {
    const bytes = Uint8Array.from([0xfb, 0xef, 0xbe, 0x00, 0x01]);
    assert.equal(bufToB64(bytes), Buffer.from(bytes).toString('base64'));
    assert.equal(bufToB64(bytes.buffer), Buffer.from(bytes).toString('base64')); // ArrayBuffer input
});

test('round-trip: server base64url → bytes → standard base64 → bytes', () => {
    for (let i = 0; i < 50; i++) {
        const raw = crypto.randomBytes(1 + Math.floor(Math.random() * 64));
        const fromServer = raw.toString('base64url'); // how ByteBuffer serializes options
        const decoded = b64uToBuf(fromServer);
        assert.deepEqual(Array.from(decoded), Array.from(raw));
        const toServer = bufToB64(decoded); // what the endpoint base64_decodes
        assert.deepEqual(Array.from(Buffer.from(toServer, 'base64')), Array.from(raw));
    }
});

test('empty input stays empty', () => {
    assert.equal(b64uToBuf('').length, 0);
    assert.equal(bufToB64(new Uint8Array(0)), '');
});
