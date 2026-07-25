@extends('layouts.app')
@section('title', 'Add media source · Odissey')
@section('content')
<section class="page-section narrow"><p class="eyebrow">Read-only onboarding</p><h1>Add media source</h1>
<form method="POST" action="{{ route('media.admin.sources.store') }}" class="settings-form">@csrf
<label>Name<input name="name" required maxlength="100" value="{{ old('name') }}"></label>
<label>Type<select name="type" required><option value="local">Local mount</option><option value="s3">S3-compatible</option><option value="webdav">WebDAV</option></select></label>
<fieldset><legend>Local</legend><label>Container path<input name="path" placeholder="/media/movies"></label></fieldset>
<fieldset><legend>S3-compatible</legend><label>Endpoint<input name="endpoint" placeholder="https://s3.example.com"></label><label>Bucket<input name="bucket"></label><label>Prefix<input name="prefix"></label><label>Region<input name="region" value="us-east-1"></label><label>Access key<input name="access_key" autocomplete="off"></label><label>Secret key<input type="password" name="secret_key" autocomplete="new-password"></label></fieldset>
<fieldset><legend>WebDAV</legend><label>Collection URL<input name="url" placeholder="https://example.com/remote.php/dav/files/user/Videos"></label><label>Username<input name="username" autocomplete="off"></label><label>Password<input type="password" name="password" autocomplete="new-password"></label></fieldset>
<label class="checkbox-row"><input type="checkbox" name="allow_private_network" value="1"> Allow HTTP or private-network access for this explicitly trusted source</label>
@if($errors->any())<div class="notice notice-error" role="alert">{{ $errors->first() }}</div>@endif
<button class="button button-primary" type="submit">Validate and scan</button>
</form></section>
@endsection
