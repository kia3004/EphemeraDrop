@extends('layouts.app')

@section('content')
<div class="card">
    <h1>Security</h1>
    <p>EphemeraDrop is a zero-knowledge system. The browser encrypts everything locally, and the server stores only ciphertext.</p>
    <p>We never log passwords, secret content, or URLs that contain decryption keys. The key lives in the URL fragment and never reaches the server.</p>
    <p>Honest limitation: if the server is compromised or malicious, it could serve modified JavaScript that attempts to steal secrets. Inspect the app source, use HTTPS, and verify the public build before trusting a server.</p>
</div>
@endsection
