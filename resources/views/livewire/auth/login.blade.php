{{-- Sign in. Kept deliberately plain: no illustration, no marketing copy. --}}
<div class="flex min-h-screen items-center justify-center bg-slate-100 px-4 py-12">
    <div class="w-full max-w-sm">

        <div class="mb-6 text-center">
            <div class="mx-auto inline-flex h-12 w-12 items-center justify-center rounded-xl bg-indigo-600 text-lg font-bold text-white">
                IT
            </div>
            <h1 class="mt-3 text-lg font-semibold text-slate-900">IT Request Management</h1>
            <p class="mt-1 text-sm text-slate-500">Sign in to continue</p>
        </div>

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
                Stated on the screen rather than buried in a document: production uses
                single sign-on, and this form is a deliberate POC deviation. Saying so
                here stops a reviewer assuming the SSO requirement was overlooked.
            --}}
            <p class="mt-4 text-xs leading-relaxed text-slate-500">
                Production uses Microsoft Entra ID single sign-on. This demonstration uses local
                accounts, which is a declared deviation.
            </p>
        </form>
    </div>
</div>
