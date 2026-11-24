<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateSectionRequest;
use App\Http\Resources\SectionResource;
use App\Models\Section;
use App\Models\Site;
use App\Services\Ai\AiUnavailable;
use App\Services\Pipeline\SectionWriter;
use Illuminate\Http\JsonResponse;

class SectionController extends Controller
{
    /** The owner edits the words themselves. Whatever they write is theirs, so we keep it as it is. */
    public function update(UpdateSectionRequest $request, Site $site, string $key): SectionResource
    {
        $section = $site->sections()->where('key', $key)->first()
            ?? new Section(['site_id' => $site->id, 'key' => $key, 'content' => []]);

        $content = array_merge($section->content ?? [], $this->clean($request->validated()));
        // An emptied field is removed rather than stored as blank.
        $content = $key === 'contact'
            ? array_filter($content, fn ($value) => filled($value))
            : $content;

        $section->fill([
            'position' => array_search($key, Section::KEYS, true),
            'content' => $content,
            'edited_by_owner' => true,
        ])->save();

        return new SectionResource($section);
    }

    /** Write one section again from the confirmed facts. This replaces the owner's edits, because they asked for it. */
    public function regenerate(Site $site, string $key, SectionWriter $writer): SectionResource|JsonResponse
    {
        if ($site->status === Site::PROCESSING) {
            return response()->json(['message' => 'The site is being built. Please wait a moment.'], 409);
        }
        if ($site->hasBlockingQuestions()) {
            return response()->json(['message' => 'Please answer the open questions first, so we know which details are right.'], 422);
        }

        try {
            $outcome = $writer->write($site, [$key], force: true);
        } catch (AiUnavailable $e) {
            // Nothing was changed. The owner keeps the current text.
            return response()->json(['message' => $e->getMessage()], 503);
        }

        if (! in_array($key, $outcome->written, true)) {
            return response()->json(['message' => 'There is not enough confirmed information to write this section yet.'], 422);
        }

        return new SectionResource($site->sections()->where('key', $key)->firstOrFail());
    }

    /** @param array<string, mixed> $data */
    private function clean(array $data): array
    {
        return collect($data)->map(function ($value) {
            if (is_string($value)) {
                return trim($value);
            }

            return is_array($value) ? $this->cleanList($value) : $value;
        })->all();
    }

    private function cleanList(array $list): array
    {
        return collect($list)->map(fn ($item) => match (true) {
            is_string($item) => trim($item),
            is_array($item) => $this->clean($item),
            default => $item,
        })->filter(fn ($item) => $item !== '' && $item !== [])->values()->all();
    }
}
