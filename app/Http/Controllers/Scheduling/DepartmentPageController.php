<?php

namespace App\Http\Controllers\Scheduling;

use App\Http\Controllers\Controller;
use App\Http\Requests\Scheduling\SaveDepartmentPageRequest;
use App\Models\DepartmentPage;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Manages the public pages listing a chosen set of teams. They are listed and
 * edited on the Teams page, so every action returns there.
 */
class DepartmentPageController extends Controller
{
    /**
     * Store a new page.
     */
    public function store(SaveDepartmentPageRequest $request, Team $current_team): RedirectResponse
    {
        Gate::authorize('create', [DepartmentPage::class, $current_team]);

        DB::transaction(function () use ($request, $current_team) {
            $page = $current_team->departmentPages()->create($request->safe()->only(['name', 'description']));

            $page->groups()->sync($request->validated('group_ids'));
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Page created.')]);

        return to_route('groups.index', ['current_team' => $current_team->slug]);
    }

    /**
     * Update a page.
     */
    public function update(SaveDepartmentPageRequest $request, Team $current_team, DepartmentPage $department_page): RedirectResponse
    {
        Gate::authorize('update', $department_page);

        DB::transaction(function () use ($request, $department_page) {
            $department_page->update($request->safe()->only(['name', 'description']));

            $department_page->groups()->sync($request->validated('group_ids'));
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Page updated.')]);

        return to_route('groups.index', ['current_team' => $current_team->slug]);
    }

    /**
     * Delete a page. Its teams and their own pages are untouched.
     */
    public function destroy(Team $current_team, DepartmentPage $department_page): RedirectResponse
    {
        Gate::authorize('delete', $department_page);

        $department_page->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Page deleted.')]);

        return to_route('groups.index', ['current_team' => $current_team->slug]);
    }
}
