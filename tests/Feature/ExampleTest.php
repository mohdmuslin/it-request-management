<?php

/*
 * The scaffolded example test asserted that `/` returns 200. That is no longer
 * true by design: the root now redirects to the dashboard, and the dashboard
 * requires authentication. Keeping the original assertion would mean either an
 * anonymous dashboard — which would be a security defect — or a permanently red
 * suite, which trains people to ignore it.
 *
 * Replaced with the behaviour the application actually has.
 */

it('redirects the root to the dashboard', function () {
    $this->get('/')->assertRedirect('/dashboard');
});

it('sends an anonymous visitor to the sign-in screen', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});
