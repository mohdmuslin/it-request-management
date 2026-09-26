{{-- Sign in. Kept deliberately plain: no illustration, no marketing copy. --}}
@php
    /*
     * What the screen offers depends on the configured identity driver (FR-001).
     *
     * `status()` rather than `provider()`, because the third case is the one that matters: a
     * driver selected and not yet configured. That state has to be VISIBLE — an administrator
     * who switches to Entra before the app registration exists would otherwise see a form that
     * silently keeps working, conclude SSO was live, and never finish the setup.
     */
    $identity = app(\App\Services\IdentityManager::class)->status();
    $usesRedirect = $identity['ready'] && app(\App\Services\IdentityManager::class)->provider()->requiresRedirect();
    $misconfigured = ! $identity['ready'] && $identity['driver'] !== 'local';
@endphp

<div class="flex min-h-screen items-center justify-center bg-slate-100 px-4 py-12">
    <div class="w-full max-w-sm">

        <div class="mb-6 text-center">
            <div class="mx-auto inline-flex h-12 w-12 items-center justify-center rounded-xl bg-indigo-600 text-lg font-bold text-white">
                IT
            </div>
            <h1 class="mt-3 text-lg font-semibold text-slate-900">IT Request Management</h1>
            <p class="mt-1 text-sm text-slate-500">Sign in to continue</p>
        </div>

        @if ($misconfigured)
            {{--
                A driver is selected and cannot be used. Shown to the ADMINISTRATOR rather than
                described to the user, because the user cannot act on it — but hiding it entirely
                would leave sign-in working by local password while the configuration claims SSO,
                which is the state nobody investigates.
            --}}
            <div class="mb-4 rounded-xl bg-amber-50 p-4 text-xs leading-relaxed text-amber-900 ring-1 ring-amber-200" role="alert">
                <p class="font-semibold">Single sign-on is selected but not configured.</p>
                <p class="mt-1">{{ $identity['problem'] }}</p>
                <p class="mt-2">
                    Until it is, sign-in falls back to local accounts. Set
                    <code class="font-mono">ITREQUEST_IDENTITY_DRIVER=local</code> to remove this notice.
                </p>
            </div>
        @endif

        @if ($usesRedirect)
            {{--
                The redirect button REPLACES the password form rather than sitting above it.
                Offering both would invite people to keep using a password that an administrator
                may have intended to retire, and on a domain with MFA the provider would refuse
                it anyway.
            --}}
            <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
                @error('email')
                    <p class="mb-4 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700 ring-1 ring-red-200" role="alert">{{ $message }}</p>
                @enderror

                <button type="button" wire:click="redirectToProvider"
                        class="w-full rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-60"
                        wire:loading.attr="disabled" wire:target="redirectToProvider">
                    <span wire:loading.remove wire:target="redirectToProvider">Sign in with your organisation account</span>
                    <span wire:loading wire:target="redirectToProvider">Redirecting…</span>
                </button>

                <p class="mt-4 text-xs leading-relaxed text-slate-500">
                    You will be taken to Microsoft to sign in, and returned here.
                </p>
            </div>
        @else
        <form wire:submit="authenticate" class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
            <div class="space-y-4">
                <div>
                    <label for="email" class="mb-1 block text-sm font-medium text-slate-700">Email</label>
                    <input id="email" type="email" wire:model="email" autocomplete="username" autofocus
                           @class([
                               'w-full rounded-lg border px-3 py-2 text-sm outline-none focus:ring-1',
                               'border-slate-300 focus:border-indigo-500 focus:ring-indigo-500' => ! $errors->has('email'),
                               'border-red-400 focus:border-red-500 focus:ring-red-500' => $errors->has('email'),
                           ])>
                    @error('email')
                        {{-- Beside the field it belongs to, not as a banner at the top. --}}
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password" class="mb-1 block text-sm font-medium text-slate-700">Password</label>
                    <input id="password" type="password" wire:model="password" autocomplete="current-password"
                           @class([
                               'w-full rounded-lg border px-3 py-2 text-sm outline-none focus:ring-1',
                               'border-slate-300 focus:border-indigo-500 focus:ring-indigo-500' => ! $errors->has('password'),
                               'border-red-400 focus:border-red-500 focus:ring-red-500' => $errors->has('password'),
                           ])>
                    @error('password')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input type="checkbox" wire:model="remember"
                           class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                    Keep me signed in
                </label>
            </div>

            <button type="submit"
                    class="mt-5 w-full rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-60"
                    wire:loading.attr="disabled" wire:target="authenticate">
                <span wire:loading.remove wire:target="authenticate">Sign in</span>
                <span wire:loading wire:target="authenticate">Signing in…</span>
            </button>

            {{--
                Stated on the screen rather than buried in a document: which provider is in use,
                so a reviewer can see the SSO requirement was not overlooked. This text follows
                the configuration rather than asserting one state — it said "this demonstration
                uses local accounts" while the driver was configurable, which is the kind of
                static claim that goes stale the moment somebody changes the setting.
            --}}
            <p class="mt-4 text-xs leading-relaxed text-slate-500">
                @if ($identity['driver'] === 'entra')
                    Signing in through Microsoft Entra ID.
                @else
                    Production uses Microsoft Entra ID single sign-on. This demonstration uses local
                    accounts, which is a declared deviation. Set
                    <code class="font-mono">ITREQUEST_IDENTITY_DRIVER=entra</code> to use it.
                @endif
            </p>
        </form>
        @endif
    </div>
</div>
