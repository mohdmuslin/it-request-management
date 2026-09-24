<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">

    <title>{{ isset($title) ? $title.' — IT Request Management' : 'IT Request Management' }}</title>

    @vite(['resources/css/app.css'])
    @livewireStyles
</head>
<body class="h-full bg-slate-100 text-slate-900 antialiased">

<div class="min-h-screen">

    {{--
        Skip link.

        The first focusable element on the page. A keyboard user should not have to
        tab through twelve navigation items to reach the content on every page —
        that is the difference between "supports keyboard use" and "technically
        can be used with a keyboard".
    --}}
    <a href="#main"
       class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-indigo-600 focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-white">
        Skip to content
    </a>

    <header class="sticky top-0 z-30 border-b border-slate-200 bg-white">
        <div class="flex items-center gap-3 px-4 py-3">

            <button type="button" @click="$dispatch('toggle-nav')"
                    class="-ml-1 rounded-lg p-2 hover:bg-slate-100 lg:hidden"
                    aria-label="Open navigation">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
            </button>

            <div class="flex items-center gap-2">
                <div class="hidden h-8 w-8 items-center justify-center rounded-lg bg-indigo-600 text-xs font-bold text-white sm:flex">
                    IT
                </div>
                <span class="text-sm font-semibold sm:text-base">IT Request Management</span>
            </div>

            <div class="ml-auto flex items-center gap-3">
                @auth
                    <div class="hidden text-right sm:block">
                        <p class="text-xs font-medium leading-tight text-slate-700">{{ auth()->user()->name }}</p>
                        <p class="text-[11px] leading-tight text-slate-500">
                            {{ auth()->user()->roles->pluck('label')->join(', ') ?: 'No role assigned yet' }}
                        </p>
                    </div>

                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit"
                                class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-50">
                            Sign out
                        </button>
                    </form>
                @endauth
            </div>
        </div>
    </header>

    <div class="flex">

        @auth
            <livewire:navigation />
        @endauth

        <main id="main" class="min-w-0 flex-1 p-4 sm:p-6">
            {{ $slot }}
        </main>
    </div>
</div>

@livewireScripts
</body>
</html>
