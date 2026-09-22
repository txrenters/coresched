<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\JsonResponse;

class OrganizationController extends Controller
{
    /**
     * The organizations that have a booking page.
     *
     * So another application can be pointed at one by id — which survives a
     * rename — rather than by a slug somebody has to look up and keep in step.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Team::query()
                ->whereNotNull('slug')
                ->orderBy('name')
                ->get(['id', 'name', 'slug'])
                ->map(fn (Team $team): array => [
                    'id' => $team->id,
                    'name' => $team->name,
                    'slug' => $team->slug,
                    'bookingUrl' => route('book.page', ['page' => $team->slug]),
                ])
                ->values(),
        ]);
    }
}
