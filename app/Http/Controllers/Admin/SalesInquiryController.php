<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SalesInquiryStatus;
use App\Http\Controllers\Controller;
use App\Models\SalesInquiry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The "Contact sales" submissions, for a super admin to work through.
 */
class SalesInquiryController extends Controller
{
    /**
     * List the inquiries, newest first.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('accessAdminConsole');

        $status = SalesInquiryStatus::tryFrom((string) $request->query('status', ''));

        $inquiries = SalesInquiry::query()
            ->when($status !== null, fn (Builder $query) => $query->where('status', $status))
            ->latest()
            ->paginate(25)
            ->withQueryString()
            ->through(fn (SalesInquiry $inquiry) => [
                'id' => $inquiry->id,
                'name' => $inquiry->name,
                'email' => $inquiry->email,
                'company' => $inquiry->company,
                'teamSize' => $inquiry->team_size,
                'plan' => $inquiry->plan ? ucfirst($inquiry->plan) : null,
                'message' => $inquiry->message,
                'status' => $inquiry->status->value,
                'receivedAt' => $inquiry->created_at?->toDayDateTimeString(),
                'receivedAgo' => $inquiry->created_at?->diffForHumans(),
            ]);

        return Inertia::render('admin/SalesInquiries', [
            'inquiries' => $inquiries,
            'statuses' => SalesInquiryStatus::options(),
            'filters' => ['status' => $status?->value ?? ''],
        ]);
    }

    /**
     * Move an inquiry along.
     */
    public function update(Request $request, SalesInquiry $salesInquiry): RedirectResponse
    {
        Gate::authorize('accessAdminConsole');

        $validated = $request->validate([
            'status' => ['required', Rule::enum(SalesInquiryStatus::class)],
        ]);

        $salesInquiry->update($validated);

        return back();
    }
}
