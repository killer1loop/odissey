<button
    class="favorite-button"
    type="button"
    aria-label="{{ $isFavorite ? 'Remove '.$channel->name.' from favorites' : 'Add '.$channel->name.' to favorites' }}"
    aria-pressed="{{ $isFavorite ? 'true' : 'false' }}"
    hx-{{ $isFavorite ? 'delete' : 'post' }}="{{ $isFavorite ? route('iptv.favorites.destroy', $channel) : route('iptv.favorites.store', $channel) }}"
    hx-swap="outerHTML"
>
    {{ $isFavorite ? '★' : '☆' }}
</button>
