@extends('layouts.app')

@section('content')
<div class="card">
    <header class="page-header">
        <a class="brand" href="/">EphemeraDrop</a>
        <a href="/about/security">Security</a>
    </header>
    <h1>Share a secret. Let it disappear.</h1>
    <p class="muted">Encrypted in your browser. The server only receives ciphertext.</p>

    <form id="drop-form"
          data-kdf-iterations="{{ config('ephemera.kdf_iterations') }}"
          data-chunk-size="{{ config('ephemera.chunk_size') }}"
          data-max-file-bytes="{{ config('ephemera.max_file_size') }}">
        <input type="hidden" id="kind-input" name="kind" value="text">
        <div class="tab-list" role="group" aria-label="Secret type">
            <button type="button" class="is-active" data-kind="text" aria-pressed="true">Text</button>
            <button type="button" data-kind="file" aria-pressed="false">File</button>
        </div>

        <div class="grid">
            <div id="text-panel">
                <label for="drop-text">Your secret</label>
                <textarea id="drop-text" rows="8" maxlength="64000" placeholder="Write a secret or message…" autocomplete="off"></textarea>
            </div>

            <div id="file-panel" hidden>
                <label for="drop-file">Choose a file (up to {{ number_format(config('ephemera.max_file_size') / 1024 / 1024) }} MB)</label>
                <input id="drop-file" type="file">
                <progress id="upload-progress" max="100" value="0" hidden aria-label="Upload progress"></progress>
                <p id="upload-progress-label" class="muted" aria-live="polite"></p>
            </div>

            <div class="settings-grid">
                <div>
                    <label for="expires_in">Expires after</label>
                    <select id="expires_in">
                        <option value="10">10 minutes</option>
                        <option value="60" selected>1 hour</option>
                        <option value="1440">1 day</option>
                        <option value="10080">7 days</option>
                    </select>
                </div>
                <div>
                    <label for="max_views">Maximum views</label>
                    <input id="max_views" type="number" min="1" max="10" value="3">
                </div>
            </div>

            <div>
                <label for="password">Password <span class="muted">(optional)</span></label>
                <input id="password" type="password" maxlength="255" autocomplete="new-password" placeholder="The receiver will need this separately">
                <p class="muted">Share the password through a different channel. It is never sent to the server.</p>
            </div>

            <details>
                <summary>Advanced options</summary>
                <div class="advanced-field">
                    <label for="notify_url">Notification URL</label>
                    <input id="notify_url" type="url" placeholder="https://ntfy.sh/your-topic">
                </div>
            </details>

            <button type="submit" class="primary-button">Create encrypted link</button>
            <p id="create-status" class="status" role="status" aria-live="polite"></p>
        </div>
    </form>

    <section id="share-result" class="share-result" hidden aria-labelledby="share-heading">
        <h2 id="share-heading">Your link is ready</h2>
        <p class="warning">This link is shown only once. Anyone with it can view the secret.</p>
        <a id="share-link" class="share-link" href="#"></a>
        <div class="share-actions">
            <button id="copy-link" type="button">Copy link</button>
            <button id="share-link-button" type="button">Share</button>
        </div>
        <canvas id="share-qr" aria-label="QR code for the share link"></canvas>
        <h3>Separate delete link</h3>
        <p class="muted">Keep this private. Anyone with it can permanently delete the drop.</p>
        <a id="delete-link" class="share-link" href="#"></a>
        <p><a href="/">Create another secret</a></p>
    </section>
</div>
@endsection
