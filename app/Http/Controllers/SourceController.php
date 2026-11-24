<?php

namespace App\Http\Controllers;

use App\Http\Requests\AddSourceRequest;
use App\Http\Requests\UploadFilesRequest;
use App\Http\Resources\SourceResource;
use App\Models\Site;
use App\Models\Source;
use App\Services\SourceIntake;
use Illuminate\Http\JsonResponse;

class SourceController extends Controller
{
    public function store(AddSourceRequest $request, Site $site, SourceIntake $intake): JsonResponse
    {
        $source = $request->input('kind') === Source::LINK
            ? $intake->link($site, $request->input('url'), $request->input('label'))
            : $intake->note($site, $request->input('text'), $request->input('label'));

        return (new SourceResource($source))->response()->setStatusCode(201);
    }

    public function upload(UploadFilesRequest $request, Site $site, SourceIntake $intake): JsonResponse
    {
        $sources = collect($request->file('files'))
            ->map(fn ($file) => $intake->file($site, $file));

        return SourceResource::collection($sources)->response()->setStatusCode(201);
    }

    public function destroy(Site $site, Source $source, SourceIntake $intake): JsonResponse
    {
        $intake->remove($source);

        return response()->json(null, 204);
    }
}
