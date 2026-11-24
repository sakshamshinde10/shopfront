<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateSectionRequest;
use App\Http\Resources\SectionResource;
use App\Http\Resources\SiteResource;
use App\Jobs\RegenerateSection;
use App\Models\Section;
use App\Models\Site;
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

    /**
     * Write one section again from the confirmed facts. This replaces the owner's edits, because they asked.
     * It runs in the background (the AI can take a while), so the answer is "accepted" and the page polls.
     */
    public function regenerate(Site $site, string $key): JsonResponse
    {
        if ($site->status === Site::PROCESSING) {
            return response()->json(['message' => 'The site is being built. Please wait a moment.'], 409);
        }
        if ($site->hasBlockingQuestions()) {
            return response()->json(['message' => 'Please answer the open questions first, so we know which details are right.'], 422);
        }

        // Claim the site in one step, so two clicks cannot start two rewrites.
        $claimed = Site::whereKey($site->id)->whereNull('regenerating')->update(['regenerating' => $key, 'notice' => null]);

        if (! $claimed) {
            return response()->json(['message' => 'Another section is being rewritten. Please wait a moment.'], 409);
        }

        RegenerateSection::dispatch($site, $key);

        return (new SiteResource($site->refresh()->load(['sections'])))->response()->setStatusCode(202);
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
