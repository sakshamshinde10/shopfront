<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSiteRequest;
use App\Http\Resources\SiteResource;
use App\Models\Fact;
use App\Models\Site;
use App\Services\SourceIntake;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class SiteController extends Controller
{
    public function store(StoreSiteRequest $request, SourceIntake $intake): JsonResponse
    {
        $site = DB::transaction(function () use ($request, $intake) {
            $site = Site::create($request->safe()->only(['business_name', 'city']));

            // What the owner typed about themselves is theirs to vouch for.
            $site->facts()->create(['field' => 'name', 'value' => $site->business_name, 'status' => Fact::CONFIRMED]);
            if ($site->city) {
                $site->facts()->create(['field' => 'city', 'value' => $site->city, 'status' => Fact::CONFIRMED]);
            }

            foreach ($request->input('links', []) as $url) {
                $intake->link($site, $url);
            }
            if (filled($request->input('note'))) {
                $intake->note($site, $request->input('note'));
            }

            return $site;
        });

        return response()->json([
            'site' => new SiteResource($site->load('sources')),
            // Shown once. It is the only way to edit this site later.
            'edit_token' => $site->edit_token,
        ], 201);
    }

    public function show(Site $site): SiteResource
    {
        return new SiteResource($site->load('sources'));
    }
}
