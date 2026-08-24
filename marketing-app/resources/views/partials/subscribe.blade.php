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
        placeholder="you@example.com"
        autocomplete="email"
    >
    <button type="submit" class="btn-accent">Notify me</button>
    <p class="subscribe-note">One email at launch. Address is never shared.</p>
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
    .subscribe-done {
        padding: 18px 20px; border-radius: 16px; color: var(--green);
        font-weight: 700;
    }
    .sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); }
</style>
