@extends('layouts.app')
@section('title','Guide · Odissey')
@section('content')
<section class="content-section"><div class="section-heading"><div><p class="eyebrow">Live TV</p><h1>Programme guide</h1><p>{{ $start->format('D j M, H:i') }}–{{ $end->format('H:i') }}</p></div><a class="button" href="{{ route('iptv.channels.index') }}">Channels</a></div>
<div class="guide-grid" role="grid" aria-label="Six-hour television guide" style="overflow:auto">
@foreach($channels as $channel)<div role="row" style="display:grid;grid-template-columns:14rem minmax(50rem,1fr);border-bottom:1px solid #333"><div role="rowheader" style="position:sticky;left:0;background:#111;padding:1rem;z-index:1"><strong>{{ $channel->name }}</strong><small style="display:block">{{ $channel->group?->name }}</small></div><div role="gridcell" style="display:flex;min-height:5rem">
@forelse($channel->programs as $program)@php($minutes=max(15,$program->starts_at->diffInMinutes($program->ends_at)))<form method="POST" action="{{ route('iptv.playback.store',$channel) }}" style="flex:0 0 {{ $minutes*3 }}px;padding:.3rem">@csrf<button type="submit" class="button" style="width:100%;height:100%;text-align:left" title="{{ $program->description }}"><strong>{{ $program->title }}</strong><small style="display:block">{{ $program->starts_at->timezone(auth()->user()->timezone)->format('H:i') }}–{{ $program->ends_at->timezone(auth()->user()->timezone)->format('H:i') }}</small></button></form>@empty<div style="padding:1rem;color:#999">No guide data</div>@endforelse
</div></div>@endforeach
</div></section>
@endsection
