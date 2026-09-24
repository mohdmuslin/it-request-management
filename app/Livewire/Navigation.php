<?php

namespace App\Livewire;

use App\Enums\UserRole;
use App\Models\ItRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;
use Livewire\Component;

/**
 * The sidebar navigation.
 *
 * WHY THE MENU LIST IS A METHOD AND NOT A PROPERTY
 *
 * It depends on the signed-in user's roles, so it has to be computed per request.
 * A property would be serialised into the component payload and could be tampered
 * with client-side — and while that would only change what is *displayed*, a menu
 * that says "Administration" is an invitation to try the URL.
 *
 * HIDING A MENU ITEM IS NOT A SECURITY CONTROL, and this component says so rather
 * than implying otherwise. Every route is authorised server-side by a policy; the
 * navigation exists so people are not shown work they cannot do, not to prevent
 * them attempting it.
 */
class Navigation extends Component
{
    public bool $open = false;

    protected $listeners = ['toggle-nav' => 'toggle'];

    public function toggle(): void
    {
        $this->open = ! $this->open;
    }

    /**
     * Menu groups visible to the current user.
     *
     * Grouped by the work they represent rather than by role, because a role can
     * span groups — an administrator holds most of them, and a governance reviewer
     * sees both assessment and committee work.
     *
     * @return array<int, array{label: string, items: array<int, array{label: string, route: string, icon: string}>}>
     */
    public function groups(): array
    {
        $user = auth()->user();

        if (! $user) {
            return [];
        }

        // A quick capability check, evaluated once rather than per item.
        $can = fn (UserRole ...$roles): bool => $user->hasAnyRole(...$roles)
            || $user->isAdministrator();

        $all = [
            [
                'label' => 'My work',
                'items' => array_filter([
                    $this->item('Dashboard', 'dashboard', 'home', true),

                    /*
                     * Gated on the POLICY, not on a role list.
                     *
                     * These two items were gated on requestor/owner/sponsor roles while
                     * the policy allowed any non-auditor to create. The two answered the
                     * same question differently, and the symptom was visible in the
                     * browser: signed in as a Project Owner, the /requests page offered
                     * a "New request" button that the navigation did not.
                     *
                     * `can()` is the same call the page and the policy use, so they
                     * cannot drift — a role added to the policy appears in the menu
                     * without anyone remembering to update this file.
                     */
                    $this->item('New Request', 'requests.create', 'plus',
                        $user->can('create', ItRequest::class)),

                    $this->item('My Requests', 'requests.index', 'document',
                        $user->can('viewAny', ItRequest::class)),
                ]),
            ],
            [
                'label' => 'Decisions',
                'items' => array_filter([
                    $this->item('My Approvals', 'approvals.index', 'check',
                        $can(UserRole::ProjectOwner, UserRole::ProjectSponsor)),

                    $this->item('Recommendations', 'recommendations.index', 'clipboard',
                        $can(UserRole::TechnicalReviewer)),
                ]),
            ],
            [
                'label' => 'Governance',
                'items' => array_filter([
                    $this->item('Governance Workspace', 'governance.index', 'shield',
                        $can(UserRole::GovernanceReviewer, UserRole::Hou)),

                    $this->item('Committee Workspace', 'committee.index', 'users',
                        $can(UserRole::CommitteeSecretariat)),
                ]),
            ],
            [
                'label' => 'Insight',
                'items' => array_filter([
                    $this->item('Reports', 'reports.index', 'chart',
                        $can(UserRole::GovernanceReviewer, UserRole::Hou, UserRole::Auditor)),
                ]),
            ],
            [
                'label' => 'Administration',
                'items' => array_filter([
                    $this->item('Reference data', 'admin.reference.index', 'cog',
                        $user->isAdministrator()),

                    $this->item('Due dates and calendar', 'admin.settings.index', 'calendar',
                        $user->isAdministrator()),

                    $this->item('Users and roles', 'admin.users.index', 'user-group',
                        $user->isAdministrator()),

                    $this->item('Audit log', 'admin.audit.index', 'list',
                        $can(UserRole::Auditor)),
                ]),
            ],
        ];

        // Drop any group whose items are all hidden, so an empty heading never
        // appears.
        return array_values(array_filter(
            array_map(function (array $group) {
                $group['items'] = array_values($group['items']);

                return $group;
            }, $all),
            fn (array $group) => $group['items'] !== [],
        ));
    }

    /**
     * One menu item, or null when the user may not see it.
     *
     * Returning null rather than an array with a flag means the filter above is
     * doing real work — a `visible` key that the template then checked would be one
     * forgotten @if away from showing a link to everyone.
     */
    private function item(string $label, string $route, string $icon, bool $visible): ?array
    {
        if (! $visible || ! Route::has($route)) {
            return null;
        }

        return [
            'label' => $label,
            'route' => $route,
            'icon' => $icon,
            'active' => request()->routeIs($route) || request()->routeIs(str_replace('.index', '.*', $route)),
        ];
    }

    /**
     * A plain-language note about why the user sees what they see.
     *
     * Everyone should be able to tell whether a record is missing because it does
     * not exist or because they cannot see it. A silently narrower view is how
     * people conclude the system has lost their work.
     */
    public function scopeNote(): string
    {
        $user = auth()->user();

        return match (true) {
            $user->isAdministrator() => 'Full access, including configuration.',
            $user->hasRole(UserRole::Auditor) => 'Read-only. Nothing here can be changed.',
            $user->hasRole(UserRole::Hou) => 'Requests awaiting consolidation.',
            $user->hasRole(UserRole::GovernanceReviewer) => 'All requests. Internal notes are visible to you.',
            $user->hasRole(UserRole::TechnicalReviewer) => 'Requests assigned to your unit.',
            $user->hasRole(UserRole::CommitteeSecretariat) => 'Full-route requests only.',
            $user->hasRole(UserRole::ProjectOwner),
            $user->hasRole(UserRole::ProjectSponsor) => 'Requests you own or sponsor, plus your own.',
            default => 'Your own requests.',
        };
    }

    public function render(): View
    {
        return view('livewire.navigation');
    }
}
