<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SalesInquiryStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateTeamPlanRequest;
use App\Models\SalesInquiry;
use App\Models\Team;
use App\Services\Activity\ActivityLogger;
use App\Services\Admin\PlatformAnalytics;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The super admin's home: platform-wide analytics. Organizations themselves
 * are listed and managed on the Organizations page (OrganizationDirectory),
 * which shows super admins each one's plan, sender, setup and "Open as".
 */
class OrganizationConsoleController extends Controller
{
    /**
     * Show the platform dashboard.
     */
    public function index(PlatformAnalytics $analytics): Response
    {
        return Inertia::render('admin/Dashboard', [
            'analytics' => $analytics->summary(),
            'newInquiryCount' => SalesInquiry::query()->where('status', SalesInquiryStatus::New)->count(),
        ]);
    }

    /**
     * Disable or re-enable the organization.
     *
     * Disabling takes effect on its members' next request (EnsureActive-
     * Organization), stops its booking pages, reminders and workflows, and
     * refuses its API keys. Re-enabling restores all of it; reminders and
     * workflow messages that fell due meanwhile are not sent late.
     */
    public function updateStatus(Request $request, Team $team, ActivityLogger $activity): RedirectResponse
    {
        Gate::authorize('accessAdminConsole');

        $validated = $request->validate([
            'disabled' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $disable = (bool) $validated['disabled'];

        $team->update($disable
            ? ['disabled_at' => $team->disabled_at ?? now(), 'disabled_reason' => $validated['reason'] ?? null]
            : ['disabled_at' => null, 'disabled_reason' => null]);

        $activity->record(
            $team,
            $disable ? 'organization.disabled' : 'organization.enabled',
            $disable ? __('Disabled the organization') : __('Enabled the organization'),
            $team,
            $disable && filled($validated['reason'] ?? null) ? ['reason' => $validated['reason']] : [],
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $disable
                ? __(':name is disabled. Its members can no longer use it.', ['name' => $team->name])
                : __(':name is enabled again.', ['name' => $team->name]),
        ]);

        return back();
    }

    /**
     * Record the organization's plan and booking mail sender.
     */
    public function updatePlan(UpdateTeamPlanRequest $request, Team $team, ActivityLogger $activity): RedirectResponse
    {
        $team->update([
            'plan' => $request->validated('plan'),
            'plan_note' => $request->validated('plan_note'),
            // Only when sent, so a save that says nothing about the sender
            // never moves the organization back to the platform address.
            ...($request->has('mail_from_address')
                ? ['mail_from_address' => $request->validated('mail_from_address')]
                : []),
        ]);

        $activity->record(
            $team,
            'organization.plan_updated',
            __('Set the plan to :plan', ['plan' => $team->plan?->label() ?? __('none')]),
            $team,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name updated.', ['name' => $team->name])]);

        return back();
    }
}
