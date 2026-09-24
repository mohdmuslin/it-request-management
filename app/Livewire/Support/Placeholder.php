<?php

namespace App\Livewire\Support;

use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * A screen that is routed but not yet built.
 *
 * WHY THIS EXISTS RATHER THAN A 404
 *
 * The navigation resolves every menu item by route name, so the routes have to
 * exist for the shell to be testable. Pointing them here means the navigation,
 * layout, responsive behaviour and role filtering can all be verified now, before
 * the screens behind them are written.
 *
 * HOW SUBCLASSES USE IT
 *
 * A placeholder extends this and overrides `details()`. Some also define their own
 * `mount()` to accept route parameters — which is why this class deliberately does
 * NOT define `mount()` itself.
 *
 * PHP requires an overriding method to have a compatible signature, so a parent
 * `mount(): void` makes `mount(ItRequest $request): void` in a child a fatal
 * error. Two earlier drafts hit exactly that. Resolving the copy in `render()`
 * sidesteps the whole problem and costs one array allocation per request.
 *
 * Each placeholder names the phase that delivers the real screen. A placeholder
 * saying only "coming soon" invites a bug report; one naming a phase tells the
 * reader it is planned rather than broken.
 */
class Placeholder extends Component
{
    public string $title = 'Screen';

    public string $phase = '';

    public string $summary = '';

    /**
     * The copy for this placeholder.
     *
     * @return array{title: string, phase: string, summary: string}
     */
    protected function details(): array
    {
        return [
            'title' => 'Screen',
            'phase' => '',
            'summary' => '',
        ];
    }

    public function render(): View
    {
        ['title' => $title, 'phase' => $phase, 'summary' => $summary] = $this->details();

        $this->title = $title;
        $this->phase = $phase;
        $this->summary = $summary;

        return view('livewire.support.placeholder');
    }
}
