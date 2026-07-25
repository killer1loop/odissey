@extends('layouts.app')
@section('title','Metadata & captions · Odissey')
@section('content')
<section class="page-section narrow"><p class="eyebrow">Administration</p><h1>Metadata & captions</h1><p>Configure free-access providers. Stored keys are encrypted and never shown again.</p>
@if(session('status'))<p class="notice">{{ session('status') }}</p>@endif
<form class="settings-form" method="POST" action="{{ route('media.admin.integrations.update') }}">@csrf @method('PUT')
<fieldset><legend>Movies and TV</legend><label>TMDB read access token <span>{{ $configured['tmdb_api_token'] ? '· configured' : '· not configured' }}</span><input type="password" name="tmdb_api_token" autocomplete="new-password"></label><label class="checkbox-row"><input type="checkbox" name="clear[]" value="tmdb_api_token"> Remove stored TMDB token</label><p>TVmaze is enabled automatically and needs no key.</p></fieldset>
<fieldset><legend>Caption websites</legend><label>SubDL API key <span>{{ $configured['subdl_api_key'] ? '· configured' : '· not configured' }}</span><input type="password" name="subdl_api_key" autocomplete="new-password"></label><label class="checkbox-row"><input type="checkbox" name="clear[]" value="subdl_api_key"> Remove stored SubDL key</label><label>OpenSubtitles API key <span>{{ $configured['opensubtitles_api_key'] ? '· configured' : '· not configured' }}</span><input type="password" name="opensubtitles_api_key" autocomplete="new-password"></label><label class="checkbox-row"><input type="checkbox" name="clear[]" value="opensubtitles_api_key"> Remove stored OpenSubtitles key</label><label>Languages<input name="caption_languages" required value="{{ $languages }}" placeholder="en,de,it"></label></fieldset>
@if($errors->any())<div class="notice notice-error" role="alert">{{ $errors->first() }}</div>@endif
<button class="button button-primary" type="submit">Save integrations</button></form></section>
@endsection
