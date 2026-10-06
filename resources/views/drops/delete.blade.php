@extends('layouts.app')

@section('content')
<div class="card">
    <a class="brand" href="/">EphemeraDrop</a>
    <h1>Delete this drop?</h1>
    <p>This permanently removes the encrypted message. This action cannot be undone.</p>
    <button id="delete-drop-button" type="button" data-drop="{{ $dropId }}">Delete permanently</button>
    <p id="delete-status" class="status" role="status" aria-live="polite"></p>
</div>
@endsection
