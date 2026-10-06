import './bootstrap';
import QRCode from 'qrcode';
import {
    decryptFileChunk,
    decryptTextPayload,
    encryptFileChunk,
    encryptTextPayload,
    exportKey,
    generateRandomKey,
    importKey,
    unwrapContentKey,
    wrapContentKey,
} from './crypto.js';

const byId = (id) => document.getElementById(id);

function announce(element, message, isError = false) {
    if (!element) return;
    element.textContent = message;
    element.classList.toggle('error', isError);
}

function getFragmentValues() {
    return new URLSearchParams(window.location.hash.slice(1));
}

function setBusy(button, busy, busyText = 'Working…') {
    if (!button) return;
    if (busy) {
        button.dataset.originalText = button.textContent;
        button.textContent = busyText;
        button.disabled = true;
    } else {
        button.textContent = button.dataset.originalText || button.textContent;
        button.disabled = false;
    }
}

async function readJsonResponse(response, errorMessage) {
    if (!response.ok) {
        if (response.status === 422) throw new Error('Some settings or uploaded chunks are invalid.');
        if (response.status === 429) throw new Error('Too many requests. Wait a moment and try again.');
        throw new Error(errorMessage);
    }

    return response.json();
}

function ensureCryptoAvailable(statusElement) {
    if (!window.isSecureContext || !window.crypto?.subtle) {
        announce(statusElement, 'This page must be opened over HTTPS to encrypt or reveal a secret.', true);
        return false;
    }

    return true;
}

const kindTabs = document.querySelectorAll('[data-kind]');
kindTabs.forEach((tab) => {
    tab.addEventListener('click', () => {
        kindTabs.forEach((item) => {
            const selected = item === tab;
            item.classList.toggle('is-active', selected);
            item.setAttribute('aria-pressed', String(selected));
        });

        const isFile = tab.dataset.kind === 'file';
        byId('kind-input').value = isFile ? 'file' : 'text';
        byId('file-panel').hidden = !isFile;
        byId('text-panel').hidden = isFile;
    });
});

const form = byId('drop-form');
if (form) {
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const submitButton = form.querySelector('[type="submit"]');
        const status = byId('create-status');
        const kind = byId('kind-input').value;

        try {
            announce(status, '');
            if (!ensureCryptoAvailable(status)) return;
            setBusy(submitButton, true, 'Encrypting…');

            const password = byId('password').value;
            const kdfIterations = Number(form.dataset.kdfIterations);
            const saltBytes = password ? crypto.getRandomValues(new Uint8Array(16)) : null;
            const salt = saltBytes
                ? btoa(String.fromCharCode(...saltBytes)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '')
                : null;

            const key = await generateRandomKey();
            const keyString = await exportKey(key);
            const wrappedKey = password ? await wrapContentKey(key, password, salt, kdfIterations) : null;
            const options = {
                expires_in: Number(byId('expires_in').value),
                max_views: Number(byId('max_views').value),
                has_password: Boolean(password),
                kdf_salt: salt,
                notify_url: byId('notify_url').value || null,
            };

            let result;
            if (kind === 'text') {
                const text = byId('drop-text').value;
                if (!text.trim()) throw new Error('Enter a message before creating a link.');

                const ciphertext = await encryptTextPayload(text, key);
                setBusy(submitButton, true, 'Saving encrypted message…');
                result = await readJsonResponse(await fetch('/api/drops', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                    body: JSON.stringify({ ...options, kind, ciphertext }),
                }), 'Could not create the link. Please try again.');
            } else {
                const file = byId('drop-file').files[0];
                const maxFileSize = Number(form.dataset.maxFileBytes);
                if (!file) throw new Error('Choose a file before creating a link.');
                if (file.size < 1) throw new Error('Choose a file that is not empty.');
                if (file.size > maxFileSize) throw new Error('This file is larger than the allowed maximum.');

                const chunkSize = Number(form.dataset.chunkSize);
                const dataChunkCount = Math.ceil(file.size / chunkSize);
                const totalChunks = dataChunkCount + 1;
                const encryptedHeader = await encryptFileChunk(
                    new TextEncoder().encode(JSON.stringify({
                        type: 'file',
                        name: file.name,
                        mime: file.type || 'application/octet-stream',
                        size: file.size,
                    })),
                    key,
                    0,
                    totalChunks,
                );

                setBusy(submitButton, true, 'Creating private upload…');
                result = await readJsonResponse(await fetch('/api/drops', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                    body: JSON.stringify({
                        ...options,
                        kind,
                        size_bytes: file.size,
                        chunk_count: totalChunks,
                    }),
                }), 'Could not start the file upload.');

                const progress = byId('upload-progress');
                const progressLabel = byId('upload-progress-label');
                progress.hidden = false;
                progress.value = 0;
                const uploadChunk = async (index, bytes) => {
                    const response = await fetch(`/api/drops/${encodeURIComponent(result.id)}/chunks/${index}`, {
                        method: 'PUT',
                        headers: {
                            'Content-Type': 'application/octet-stream',
                            'X-Upload-Token': result.upload_token,
                        },
                        body: bytes,
                    });

                    if (!response.ok) {
                        if (response.status === 413) throw new Error('An encrypted chunk exceeded the server upload limit.');
                        if (response.status === 429) throw new Error('Too many upload requests. Wait and try again.');
                        throw new Error('A file chunk could not be uploaded. Create a new link and retry.');
                    }

                    progress.value = ((index + 1) / totalChunks) * 100;
                    progressLabel.textContent = `Uploading encrypted chunks: ${index + 1} of ${totalChunks}`;
                };

                await uploadChunk(0, encryptedHeader);
                for (let chunkIndex = 0; chunkIndex < dataChunkCount; chunkIndex++) {
                    const start = chunkIndex * chunkSize;
                    const end = Math.min(start + chunkSize, file.size);
                    const plainChunk = new Uint8Array(await file.slice(start, end).arrayBuffer());
                    const encryptedChunk = await encryptFileChunk(
                        plainChunk,
                        key,
                        chunkIndex + 1,
                        totalChunks,
                    );
                    await uploadChunk(chunkIndex + 1, encryptedChunk);
                }

                setBusy(submitButton, true, 'Finalizing encrypted file…');
                await readJsonResponse(await fetch(`/api/drops/${encodeURIComponent(result.id)}/finalize`, {
                    method: 'POST',
                    headers: { 'X-Upload-Token': result.upload_token, Accept: 'application/json' },
                }), 'The encrypted file could not be finalized.');
            }

            const shareUrl = new URL(`/d/${result.id}`, window.location.origin);
            const fragment = new URLSearchParams();
            if (password) fragment.set('w', wrappedKey);
            else fragment.set('k', keyString);
            shareUrl.hash = fragment.toString();

            const shareLink = byId('share-link');
            shareLink.href = shareUrl.toString();
            shareLink.textContent = shareUrl.toString();
            byId('share-result').hidden = false;
            byId('drop-form').hidden = true;

            const deleteUrl = new URL(`/d/${result.id}/delete`, window.location.origin);
            deleteUrl.hash = new URLSearchParams({ token: result.delete_token }).toString();
            byId('delete-link').href = deleteUrl.toString();
            byId('delete-link').textContent = deleteUrl.toString();

            await QRCode.toCanvas(byId('share-qr'), shareUrl.toString(), {
                width: 192,
                margin: 2,
                color: { dark: '#101827', light: '#ffffff' },
            });
        } catch (error) {
            announce(status, error instanceof Error ? error.message : 'Could not create the link.', true);
        } finally {
            setBusy(submitButton, false);
        }
    });
}

byId('copy-link')?.addEventListener('click', async () => {
    try {
        await navigator.clipboard.writeText(byId('share-link').href);
        announce(byId('create-status'), 'Share link copied.');
    } catch {
        announce(byId('create-status'), 'Copy failed. Select and copy the link above.', true);
    }
});

const shareButton = byId('share-link-button');
if (shareButton && !navigator.share) shareButton.hidden = true;
shareButton?.addEventListener('click', async () => {
    try {
        await navigator.share({ title: 'EphemeraDrop secret', url: byId('share-link').href });
    } catch (error) {
        if (error?.name !== 'AbortError') announce(byId('create-status'), 'Sharing was unavailable.', true);
    }
});

const revealButton = byId('reveal-button');
if (revealButton) {
    const dropId = revealButton.dataset.drop;
    const revealStatus = byId('reveal-status');
    const passwordPanel = byId('password-panel');

    fetch(`/api/drops/${encodeURIComponent(dropId)}/status`, {
        headers: { Accept: 'application/json' },
        cache: 'no-store',
    }).then(async (response) => {
        if (response.status === 404) {
            throw new Error('This link has expired, has already been viewed, or does not exist.');
        }
        if (!response.ok) throw new Error('Could not check this link. Please try again.');

        const status = await response.json();
        revealButton.hidden = false;
        byId('remaining-views').textContent = `${status.remaining_views} view${status.remaining_views === 1 ? '' : 's'} remaining`;
        byId('drop-expiry').textContent = `Expires ${new Date(status.expires_at).toLocaleString()}`;
        if (status.has_password) {
            passwordPanel.hidden = false;
            passwordPanel.dataset.salt = status.kdf_salt;
            passwordPanel.dataset.iterations = String(status.kdf_iterations);
        }
    }).catch((error) => {
        revealButton.hidden = true;
        const message = navigator.onLine
            ? (error instanceof Error ? error.message : 'Could not load this link.')
            : 'You appear to be offline. Reconnect and try again.';
        announce(revealStatus, message, true);
    });

    revealButton.addEventListener('click', async () => {
        try {
            announce(revealStatus, '');
            if (!ensureCryptoAvailable(revealStatus)) return;
            setBusy(revealButton, true, 'Unlocking…');
            const fragment = getFragmentValues();
            let contentKey;

            if (!passwordPanel.hidden) {
                const password = byId('reveal-password').value;
                if (!password) throw new Error('Enter the password supplied by the sender.');
                const wrapped = fragment.get('w');
                if (!wrapped) throw new Error('This share link is missing its encrypted key.');
                try {
                    contentKey = await unwrapContentKey(
                        wrapped,
                        password,
                        passwordPanel.dataset.salt,
                        Number(passwordPanel.dataset.iterations),
                    );
                } catch {
                    throw new Error('Incorrect password. Check it and try again.');
                }
                byId('reveal-password').value = '';
            } else {
                const key = fragment.get('k');
                if (!key) throw new Error('This share link is missing its decryption key.');
                try {
                    contentKey = await importKey(key);
                } catch {
                    throw new Error('Link is corrupted or the key is wrong');
                }
            }

            const response = await fetch(`/api/drops/${encodeURIComponent(dropId)}/claim`, {
                method: 'POST',
                headers: { Accept: 'application/json' },
                cache: 'no-store',
            });
            if (response.status === 404) {
                throw new Error('This link has expired, has already been viewed, or does not exist.');
            }
            if (!response.ok) throw new Error('Could not reveal this message. Please try again.');

            const result = await response.json();
            if (result.kind === 'file') {
                await revealFile(result, contentKey, revealStatus);
                byId('copy-message').hidden = true;
            } else {
                let payload;
                try {
                    payload = await decryptTextPayload(result.ciphertext, contentKey);
                } catch {
                    throw new Error('Link is corrupted or the key is wrong');
                }

                byId('reveal-body').textContent = payload.body;
                byId('copy-message').hidden = false;
                byId('copy-message').addEventListener('click', () => copyMessage(payload.body, revealStatus), { once: true });
            }

            byId('reveal-content').hidden = false;
            byId('reveal-interstitial').hidden = true;
            announce(revealStatus, result.kind === 'file' ? 'File decrypted and ready to download.' : 'Message revealed.');
        } catch (error) {
            announce(revealStatus, error instanceof Error ? error.message : 'Link is corrupted or the key is wrong', true);
        } finally {
            setBusy(revealButton, false);
        }
    });
}

async function revealFile(claim, key, statusElement) {
    if (!Array.isArray(claim.chunks) || claim.chunks.length < 2 || claim.chunks.length !== claim.chunk_count) {
        throw new Error('The encrypted file is incomplete or corrupted.');
    }

    byId('file-download-progress').hidden = false;
    const progress = byId('download-progress');
    const progressLabel = byId('download-progress-label');
    progress.value = 0;
    const decryptedChunks = [];
    let header;

    for (let index = 0; index < claim.chunks.length; index++) {
        const response = await fetch(claim.chunks[index], { cache: 'no-store' });
        if (!response.ok) throw new Error('An encrypted file chunk could not be downloaded.');
        const cipherChunk = new Uint8Array(await response.arrayBuffer());
        const plainChunk = await decryptFileChunk(cipherChunk, key, index, claim.chunk_count);

        if (index === 0) {
            try {
                header = JSON.parse(new TextDecoder().decode(plainChunk));
            } catch {
                throw new Error('Link is corrupted or the key is wrong');
            }
            if (header?.type !== 'file'
                || typeof header.name !== 'string'
                || typeof header.mime !== 'string'
                || !Number.isSafeInteger(header.size)
                || header.size !== Number(claim.size_bytes)) {
                throw new Error('Link is corrupted or the key is wrong');
            }
        } else {
            decryptedChunks.push(plainChunk);
        }

        progress.value = ((index + 1) / claim.chunks.length) * 100;
        progressLabel.textContent = `Decrypting encrypted chunks: ${index + 1} of ${claim.chunks.length}`;
    }

    const file = new Blob(decryptedChunks, { type: header.mime });
    if (file.size !== header.size) throw new Error('The decrypted file is incomplete.');

    const link = byId('file-download');
    link.href = URL.createObjectURL(file);
    link.download = header.name.replace(/[\\/:*?"<>|]/g, '_') || 'download';
    link.hidden = false;
    announce(statusElement, 'File decrypted and ready to download.');
}

async function copyMessage(message, statusElement) {
    try {
        await navigator.clipboard.writeText(message);
        announce(statusElement, 'Copied. Clipboard will be cleared in 30 seconds.');
        window.setTimeout(async () => {
            try {
                const current = await navigator.clipboard.readText();
                if (current === message) await navigator.clipboard.writeText('');
            } catch {
                announce(statusElement, 'Clipboard could not be cleared automatically.', true);
            }
        }, 30000);
    } catch {
        announce(statusElement, 'Copy failed. Select the message to copy it.', true);
    }
}

byId('delete-drop-button')?.addEventListener('click', async (event) => {
    const button = event.currentTarget;
    const status = byId('delete-status');
    try {
        setBusy(button, true, 'Deleting…');
        const token = getFragmentValues().get('token');
        if (!token) throw new Error('This delete link is missing its token.');

        const response = await fetch(`/api/drops/${encodeURIComponent(button.dataset.drop)}`, {
            method: 'DELETE',
            headers: { 'X-Delete-Token': token, Accept: 'application/json' },
        });
        if (response.status === 403) throw new Error('This delete token is invalid.');
        if (!response.ok) throw new Error('Could not delete this drop. Please try again.');

        announce(status, 'Drop deleted.');
        button.hidden = true;
    } catch (error) {
        announce(status, error instanceof Error ? error.message : 'Could not delete this drop.', true);
    } finally {
        setBusy(button, false);
    }
});
