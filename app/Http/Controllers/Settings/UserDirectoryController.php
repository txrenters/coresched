<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Scheduling\CreateDefaultAvailability;
use App\Actions\Teams\CreateTeamUser;
use App\Actions\Teams\SendAccountWelcome;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\CreateDirectoryUserRequest;
use App\Models\CalendarAccount;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use App\Policies\TeamPolicy;
use App\Rules\DialableNumber;
use App\Support\PhoneNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UserDirectoryController extends Controller
{
    public function __construct(
        protected CreateDefaultAvailability $createDefaultAvailability,
    ) {
        //
    }

    /**
     * How many accounts are listed at a time.
     */
    protected int $perPage = 25;

    /**
     * List every account and the organizations it belongs to.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewUsers');

        $viewer = $request->user();
        $canManage = $viewer->can('manageUsers');
        $search = $request->string('search')->toString();

        // An admin sees the people in the organizations they administer; a
        // super admin sees the whole installation.
        $visibleTeamIds = $canManage
            ? null
            : $viewer->teams()->wherePivot('role', TeamRole::Admin->value)->pluck('teams.id');

        $users = User::query()
            ->with([
                'teams:id,name,slug',
                'groups:id,name',
                'calendarAccounts:id,user_id,provider,sync_error,last_synced_at',
            ])
            ->when($visibleTeamIds !== null, fn ($query) => $query->whereHas(
                'teams',
                fn ($teams) => $teams->whereIn('teams.id', $visibleTeamIds),
            ))
            ->when(filled($search), fn ($query) => $query->where(
                fn ($match) => $match
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%"),
            ))
            ->orderBy('name')
            ->paginate($this->perPage)
            ->withQueryString();

        return Inertia::render('users/Index', [
            'users' => $users->through(fn (User $user) => $this->toPayload($user, $viewer))->items(),
            'search' => $search,
            'page' => $users->currentPage(),
            'lastPage' => $users->lastPage(),
            'total' => $users->total(),
            'roles' => TeamRole::assignable(),
            'canManage' => $canManage,
            // Where this viewer may create an account: everywhere for a super
            // admin, their own organizations for an administrator.
            'organizations' => $canManage
                ? Team::query()->orderBy('name')->get(['id', 'name'])
                : $viewer->teams()
                    ->wherePivot('role', TeamRole::Admin->value)
                    ->orderBy('name')
                    ->get(['teams.id', 'teams.name']),
        ]);
    }

    /**
     * Create an account.
     *
     * Either a super admin, who belongs to no organization, or someone placed
     * in one with a role. No password is chosen here in either case: the
     * account gets a throwaway secret and a link to set its own, the way
     * CreateTeamUser and the user:super-admin command both work.
     */
    public function store(CreateDirectoryUserRequest $request, CreateTeamUser $createTeamUser, SendAccountWelcome $sendAccountWelcome): RedirectResponse
    {
        // A super admin is created by a super admin; anyone else is created by
        // whoever administers the organization they are going into.
        if ($request->boolean('is_super_admin')) {
            Gate::authorize('manageUsers');
        } else {
            $team = Team::query()->whereKey($request->validated('team_id'))->firstOrFail();

            Gate::authorize('createMember', $team);
        }

        if ($request->boolean('is_super_admin')) {
            $user = DB::transaction(function () use ($request) {
                $user = User::create([
                    'name' => $request->validated('name'),
                    'email' => $request->validated('email'),
                    'password' => Str::password(32),
                ]);

                $user->forceFill([
                    'is_super_admin' => true,
                    // Being created here by an operator is the verification.
                    'email_verified_at' => now(),
                ])->save();

                $this->createDefaultAvailability->handle($user);

                return $user;
            });

            $sendAccountWelcome->handle($user);
        } else {
            $user = $createTeamUser->handle(
                $team,
                $request->validated('name'),
                $request->validated('email'),
                TeamRole::from($request->validated('role')),
            );
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Account created. :name can set a password from the email we just sent.', ['name' => $user->name]),
        ]);

        return back();
    }

    /**
     * Email the user a link to set a new password.
     *
     * Deliberately a reset link rather than a password this page chooses: a
     * usable password never passes through the operator's hands, the way
     * CreateTeamUser already works.
     */
    public function sendPasswordReset(Request $request, User $user): RedirectResponse
    {
        abort_unless($this->mayActOn($request->user(), $user), 403);

        Password::sendResetLink(['email' => $user->email]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Password reset link sent to :email.', ['email' => $user->email]),
        ]);

        return back();
    }

    /**
     * Add, change or clear a user's mobile number.
     *
     * Open to the same people as the reset link: a super admin for anyone, an
     * admin for the people in the organizations they run.
     */
    public function updatePhone(Request $request, User $user): RedirectResponse
    {
        abort_unless($this->mayActOn($request->user(), $user), 403);

        $validated = $request->validate([
            'phone' => ['nullable', 'string', 'max:20', new DialableNumber],
        ]);

        // Stored the way Twilio dials it, whatever punctuation was typed.
        $user->forceFill(['phone' => PhoneNumber::toE164($validated['phone'] ?? null)])->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $user->phone === null
                ? __('Mobile number removed for :name.', ['name' => $user->name])
                : __('Mobile number saved for :name.', ['name' => $user->name]),
        ]);

        return back();
    }

    /**
     * Change the role a user holds in one of their organizations.
     */
    public function updateRole(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('manageUsers');

        $validated = $request->validate([
            'team_id' => ['required', 'integer'],
            'role' => ['required', Rule::enum(TeamRole::class)],
        ]);

        $membership = $user->teamMemberships()
            ->where('team_id', $validated['team_id'])
            ->firstOrFail();

        $role = TeamRole::from($validated['role']);
        $team = $membership->team;

        // An organization has to keep an administrator, here as much as on the
        // organization's own screen.
        if ($role !== TeamRole::Admin && app(TeamPolicy::class)->isLastAdmin($user, $team)) {
            return back()->withErrors([
                'role' => __('The last administrator of :team cannot be demoted.', ['team' => $team->name]),
            ]);
        }

        $membership->update(['role' => $role]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':name is now :role.', ['name' => $user->name, 'role' => $role->label()]),
        ]);

        return back();
    }

    /**
     * Take someone out of one organization, leaving the account alone.
     *
     * This is the admin's version of removing a user: deleting the account
     * would reach into organizations they have nothing to do with.
     */
    public function removeFromTeam(Request $request, User $user, Team $team): RedirectResponse
    {
        $viewer = $request->user();

        abort_unless(
            $viewer->can('manageUsers') || $viewer->teamRole($team) === TeamRole::Admin,
            403,
        );

        if ($viewer->is($user)) {
            return back()->withErrors(['member' => __('You cannot remove yourself from here.')]);
        }

        if (app(TeamPolicy::class)->isLastAdmin($user, $team)) {
            return back()->withErrors(['member' => __('The last administrator cannot be removed.')]);
        }

        $team->memberships()->where('user_id', $user->id)->delete();

        if ($user->isCurrentTeam($team)) {
            $next = $user->fallbackTeam();

            $next === null
                ? $user->forceFill(['current_team_id' => null])->save()
                : $user->switchTeam($next);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':name has been removed from :team.', ['name' => $user->name, 'team' => $team->name]),
        ]);

        return back();
    }

    /**
     * Delete an account outright.
     *
     * Everything hanging off it goes with the foreign keys: memberships, event
     * types, availability and the bookings it hosts.
     */
    public function destroy(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('manageUsers');

        if ($request->user()->is($user)) {
            return back()->withErrors(['user' => __('You cannot delete your own account here.')]);
        }

        $user->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':name has been deleted.', ['name' => $user->name]),
        ]);

        return back();
    }

    /**
     * Determine whether the viewer may act on this account at all: a super
     * admin may, and an admin may for the people in organizations they run.
     */
    protected function mayActOn(User $viewer, User $subject): bool
    {
        if ($viewer->can('manageUsers')) {
            return true;
        }

        $administered = $this->administeredTeamIds($viewer);

        return $administered->isNotEmpty()
            && $subject->teams()->whereIn('teams.id', $administered)->exists();
    }

    /**
     * Get the ids of the organizations the viewer administers.
     *
     * @return Collection<int, int>
     */
    protected function administeredTeamIds(User $viewer): Collection
    {
        return $viewer->teams()
            ->wherePivot('role', TeamRole::Admin->value)
            ->pluck('teams.id');
    }

    /**
     * Describe one account for the directory.
     *
     * @return array<string, mixed>
     */
    protected function toPayload(User $user, User $viewer): array
    {
        $administered = $viewer->can('manageUsers')
            ? null
            : $this->administeredTeamIds($viewer);

        $mayActOn = $this->mayActOn($viewer, $user);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'initials' => $this->initials($user->name),
            'isSuperAdmin' => $user->isSuperAdmin(),
            'isVerified' => $user->email_verified_at !== null,
            'joinedAt' => $user->created_at?->toFormattedDateString(),
            'bookingUrl' => $user->booking_slug === null
                ? null
                : route('book.page', ['page' => $user->booking_slug]),
            'groups' => $user->groups->pluck('name')->values(),
            'calendar' => $this->calendarStatus($user),
            // What this viewer may do to this row, so the menu offers only
            // the actions that would actually go through.
            'canResetPassword' => $mayActOn,
            'canEditPhone' => $mayActOn,
            'removableFrom' => $viewer->is($user)
                ? collect()
                : $user->teams
                    ->filter(fn (Team $team) => $administered === null || $administered->contains($team->id))
                    // The slug, because that is how a Team binds on the route.
                    ->map(fn (Team $team) => [
                        'id' => $team->id,
                        'slug' => $team->slug,
                        'name' => $team->name,
                    ])
                    ->values(),
            'organizations' => $user->teams
                ->map(fn (Team $team) => [
                    'id' => $team->id,
                    'name' => $team->name,
                    // teams() carries a plain pivot, so the role arrives as a
                    // string rather than the cast Membership enum.
                    'role' => $team->getRelation('pivot')->role,
                    'roleLabel' => TeamRole::from($team->getRelation('pivot')->role)->label(),
                ])
                ->values(),
        ];
    }

    /**
     * Describe the state of the user's connected calendars.
     *
     * Three answers rather than a boolean: a calendar that once connected and
     * is now failing is a different problem from one that was never set up.
     *
     * @return array{state: string, label: string, detail: string|null}
     */
    protected function calendarStatus(User $user): array
    {
        $accounts = $user->calendarAccounts;

        if ($accounts->isEmpty()) {
            return ['state' => 'none', 'label' => 'Not connected', 'detail' => null];
        }

        $failing = $accounts->first(fn (CalendarAccount $account) => filled($account->sync_error));

        if ($failing !== null) {
            return ['state' => 'error', 'label' => 'Sync error', 'detail' => $failing->sync_error];
        }

        $synced = $accounts->max('last_synced_at');

        return [
            'state' => 'synced',
            'label' => 'Synced',
            'detail' => $synced?->diffForHumans(),
        ];
    }

    /**
     * Get the initials shown in place of an avatar.
     */
    protected function initials(string $name): string
    {
        return collect(explode(' ', trim($name)))
            ->filter()
            ->take(2)
            ->map(fn (string $part) => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');
    }
}
