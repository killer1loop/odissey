<dl id="foundation-status" class="status-grid">
    @foreach ($checks as $check)
        <div>
            <dt>{{ $check['label'] }}</dt>
            <dd>{{ $check['value'] }}</dd>
        </div>
    @endforeach
</dl>
