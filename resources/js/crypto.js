const DROP_VERSION = 'ephemera-v1';

/**
 * Encode bytes as unpadded base64url for use in ciphertext and URL fragments.
 * @param {Uint8Array} bytes
 * @returns {string}
 */
export function bytesToBase64Url(bytes) {
    let binary = '';
    bytes.forEach((byte) => {
        binary += String.fromCharCode(byte);
    });

    const encoded = typeof btoa === 'function'
        ? btoa(binary)
        : Buffer.from(binary, 'binary').toString('base64');

    return encoded.replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
}

/**
 * Decode unpadded base64url into bytes.
 * @param {string} value
 * @returns {Uint8Array}
 */
export function base64UrlDecode(value) {
    const normalized = value.replace(/-/g, '+').replace(/_/g, '/');
    const padded = normalized + '='.repeat((4 - normalized.length % 4) % 4);
    const binary = typeof atob === 'function'
        ? atob(padded)
        : Buffer.from(padded, 'base64').toString('binary');
    const bytes = new Uint8Array(binary.length);

    for (let i = 0; i < binary.length; i++) {
        bytes[i] = binary.charCodeAt(i);
    }

    return bytes;
}

/** Generate a fresh, extractable AES-256-GCM key for one drop. */
export async function generateRandomKey() {
    return crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, true, ['encrypt', 'decrypt']);
}

/** Export an AES key as base64url for a passwordless URL fragment. */
export async function exportKey(key) {
    return bytesToBase64Url(new Uint8Array(await crypto.subtle.exportKey('raw', key)));
}

/** Import an AES key from its base64url representation or raw bytes. */
export async function importKey(rawKey, usages = ['encrypt', 'decrypt']) {
    const normalized = typeof rawKey === 'string'
        ? base64UrlDecode(rawKey)
        : rawKey instanceof ArrayBuffer
            ? new Uint8Array(rawKey)
            : rawKey;

    return crypto.subtle.importKey('raw', normalized, 'AES-GCM', false, usages);
}

function packIvAndCiphertext(iv, ciphertext) {
    const packed = new Uint8Array(iv.length + ciphertext.length);
    packed.set(iv);
    packed.set(ciphertext, iv.length);
    return bytesToBase64Url(packed);
}

function unpackIvAndCiphertext(blob) {
    const packed = typeof blob === 'string'
        ? base64UrlDecode(blob)
        : blob instanceof ArrayBuffer
            ? new Uint8Array(blob)
            : blob;
    if (packed.length <= 12) {
        throw new Error('Invalid encrypted payload');
    }

    return { iv: packed.slice(0, 12), ciphertext: packed.slice(12) };
}

/**
 * Encrypt the specified text envelope; the returned blob starts with its 12-byte IV.
 * @param {string} text
 * @param {CryptoKey} key
 * @returns {Promise<string>}
 */
export async function encryptTextPayload(text, key) {
    const iv = crypto.getRandomValues(new Uint8Array(12));
    const payload = new TextEncoder().encode(JSON.stringify({ type: 'text', body: text }));
    const ciphertext = new Uint8Array(await crypto.subtle.encrypt(
        { name: 'AES-GCM', iv, tagLength: 128 },
        key,
        payload,
    ));

    return packIvAndCiphertext(iv, ciphertext);
}

/**
 * Decrypt and validate a text envelope.
 * @param {string} blob Base64url-encoded IV followed by AES-GCM ciphertext.
 * @param {CryptoKey} key
 * @returns {Promise<{type: 'text', body: string}>}
 */
export async function decryptTextPayload(blob, key) {
    const { iv, ciphertext } = unpackIvAndCiphertext(blob);
    const clear = await crypto.subtle.decrypt({ name: 'AES-GCM', iv, tagLength: 128 }, key, ciphertext);
    const payload = JSON.parse(new TextDecoder().decode(clear));

    if (payload?.type !== 'text' || typeof payload.body !== 'string') {
        throw new Error('Invalid text payload');
    }

    return payload;
}

function createChunkAad(chunkIndex, totalChunks) {
    const versionBytes = new TextEncoder().encode(DROP_VERSION);
    const buffer = new Uint8Array(versionBytes.length + 8);
    buffer.set(versionBytes, 0);

    const view = new DataView(buffer.buffer);
    view.setUint32(versionBytes.length, chunkIndex, false);
    view.setUint32(versionBytes.length + 4, totalChunks, false);

    return buffer;
}

/** Encrypt one file chunk with its IV prefixed and position bound by AES-GCM AAD. */
export async function encryptFileChunk(chunk, key, chunkIndex, totalChunks) {
    const iv = crypto.getRandomValues(new Uint8Array(12));
    const ciphertext = new Uint8Array(await crypto.subtle.encrypt(
        { name: 'AES-GCM', iv, tagLength: 128, additionalData: createChunkAad(chunkIndex, totalChunks) },
        key,
        chunk,
    ));

    const packed = new Uint8Array(iv.length + ciphertext.length);
    packed.set(iv);
    packed.set(ciphertext, iv.length);
    return packed;
}

/** Decrypt a file chunk and authenticate its expected position and total count. */
export async function decryptFileChunk(blob, key, chunkIndex, totalChunks) {
    const { iv, ciphertext } = unpackIvAndCiphertext(blob);
    const clear = await crypto.subtle.decrypt(
        { name: 'AES-GCM', iv, tagLength: 128, additionalData: createChunkAad(chunkIndex, totalChunks) },
        key,
        ciphertext,
    );

    return new Uint8Array(clear);
}

/** Derive the AES-256-GCM password wrapping key using PBKDF2-SHA256. */
export async function derivePasswordKey(password, saltBase64, iterations = 600000) {
    const salt = base64UrlDecode(saltBase64);
    if (salt.length !== 16 || iterations < 600000) {
        throw new Error('Invalid password key parameters');
    }

    const keyMaterial = await crypto.subtle.importKey(
        'raw',
        new TextEncoder().encode(password),
        'PBKDF2',
        false,
        ['deriveKey'],
    );

    return crypto.subtle.deriveKey(
        { name: 'PBKDF2', salt, iterations, hash: 'SHA-256' },
        keyMaterial,
        { name: 'AES-GCM', length: 256 },
        true,
        ['encrypt', 'decrypt'],
    );
}

/** Wrap the random content key; return the IV-prefixed wrapping ciphertext. */
export async function wrapContentKey(contentKey, password, saltBase64, iterations = 600000) {
    const wrapperKey = await derivePasswordKey(password, saltBase64, iterations);
    const rawKey = await crypto.subtle.exportKey('raw', contentKey);
    const iv = crypto.getRandomValues(new Uint8Array(12));
    const wrapped = new Uint8Array(await crypto.subtle.encrypt(
        { name: 'AES-GCM', iv, tagLength: 128 },
        wrapperKey,
        rawKey,
    ));

    return packIvAndCiphertext(iv, wrapped);
}

/** Unwrap the content key from an IV-prefixed wrapper blob. */
export async function unwrapContentKey(blob, password, saltBase64, iterations = 600000) {
    const wrapperKey = await derivePasswordKey(password, saltBase64, iterations);
    const { iv, ciphertext } = unpackIvAndCiphertext(blob);
    const decrypted = await crypto.subtle.decrypt({ name: 'AES-GCM', iv, tagLength: 128 }, wrapperKey, ciphertext);

    return crypto.subtle.importKey('raw', decrypted, 'AES-GCM', false, ['encrypt', 'decrypt']);
}
