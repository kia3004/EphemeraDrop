@extends('layouts.app')

@section('content')
<div class="card reveal-card">
    <a class="brand" href="/">EphemeraDrop</a>
    <section id="reveal-interstitial">
        <h1>Someone shared a secret with you.</h1>
        <p class="muted">Opening a message uses one view. The message is decrypted only in this browser.</p>
        <p id="remaining-views" class="drop-meta">Checking link…</p>
        <p id="drop-expiry" class="drop-meta"></p>
        <div id="password-panel" hidden>
            <label for="reveal-password">Password from the sender</label>
            <input id="reveal-password" type="password" autocomplete="current-password">
        </div>
        <button id="reveal-button" class="primary-button" type="button" data-drop="{{ $dropId }}" hidden>Reveal message</button>
    </section>
    <section id="reveal-content" hidden>
        <h1>Your message</h1>
        <pre id="reveal-body" class="secret-message"></pre>
        <div id="file-download-progress" hidden>
            <progress id="download-progress" max="100" value="0" aria-label="Download progress"></progress>
            <p id="download-progress-label" class="muted" aria-live="polite"></p>
        </div>
        <a id="file-download" class="primary-button download-button" href="#" hidden>Download decrypted file</a>
        <button id="copy-message" type="button">Copy message</button>
        <p class="muted">Clipboard contents are cleared after 30 seconds when the browser permits it.</p>
    </section>
    <p id="reveal-status" class="status" role="status" aria-live="polite"></p>
</div>
@endsection
