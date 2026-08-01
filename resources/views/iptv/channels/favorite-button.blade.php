<button
    class="favorite-button"
    type="button"
    aria-label="{{ $isFavorite ? 'Remove '.$channel->name.' from favorites' : 'Add '.$channel->name.' to favorites' }}"
    aria-pressed="{{ $isFavorite ? 'true' : 'false' }}"
    hx-{{ $isFavorite ? 'delete' : 'post' }}="{{ $isFavorite ? route('iptv.favorites.destroy', $channel) : route('iptv.favorites.store', $channel) }}"
    hx-swap="outerHTML"
>
    <svg aria-hidden="true" viewBox="0 0 24 24" @if($isFavorite) data-filled="true" @endif>
        <path d="m12 3 2.7 5.5 6.1.9-4.4 4.3 1 6.1-5.4-2.9-5.4 2.9 1-6.1-4.4-4.3 6.1-.9z"/>
    </svg>
</button>
