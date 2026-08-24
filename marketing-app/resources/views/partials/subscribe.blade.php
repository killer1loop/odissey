<form
    class="subscribe-form glass"
    hx-post="{{ route('subscribe') }}"
    hx-target="#subscribe-target"
    hx-swap="innerHTML"
    hx-disabled-elt="find button"
>
    @csrf
    <label class="sr-only" for="subscribe-email">Email address</label>
    <input
        id="subscribe-email"
        type="email"
        name="email"
        required
        maxlength="255"
        placeholder="you@example.com"
        autocomplete="email"
        value="{{ $email ?? '' }}"
        aria-describedby="subscribe-note{{ ($validationErrors ?? null)?->has('email') ? ' subscribe-error' : '' }}"
        @if (($validationErrors ?? null)?->has('email')) aria-invalid="true" @endif
    >
    <button type="submit" class="btn-accent">Notify me</button>
    <p class="subscribe-note" id="subscribe-note">One email at launch. Address is never shared.</p>
    @if (($validationErrors ?? null)?->has('email'))
        <p class="subscribe-error" id="subscribe-error" role="alert">{{ $validationErrors->first('email') }}</p>
    @endif
</form>

<style>
    .subscribe-form {
        margin-top: 28px; display: flex; flex-wrap: wrap; gap: 12px; align-items: center;
        padding: 18px 20px; border-radius: 16px;
    }
    .subscribe-form input {
        flex: 1 1 260px; min-width: 0; padding: 12px 14px; border-radius: 10px;
        background: color-mix(in oklch, #fff 6%, transparent);
        border: 1px solid var(--line); color: var(--text); font-size: .95rem;
    }
    .subscribe-form input:focus-visible { outline: 2px solid var(--accent-bright); outline-offset: 2px; }
    .subscribe-form button {
        padding: 12px 22px; border: 0; border-radius: 10px; cursor: pointer;
        background: var(--accent); color: var(--accent-ink);
        font-weight: 780; font-size: .92rem; letter-spacing: -.01em;
    }
    .subscribe-note { width: 100%; margin: 4px 0 0; color: var(--muted); font-size: .8rem; }
    .subscribe-error { width: 100%; margin: 0; color: #ff9d9d; font-size: .82rem; font-weight: 700; }
    .subscribe-done {
        padding: 18px 20px; border-radius: 16px; color: var(--green);
        font-weight: 700;
    }
    .sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); }
</style>
