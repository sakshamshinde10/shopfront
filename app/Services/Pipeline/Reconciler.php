<?php

namespace App\Services\Pipeline;

use App\Models\Fact;
use App\Models\Question;
use App\Models\Site;
use App\Models\Source;
use Illuminate\Support\Collection;

/**
 * Decides which facts can be trusted, and asks the owner about everything else.
 * It never picks a side when sources disagree. It can be run again at any time: it only
 * touches facts that are still undecided.
 */
class Reconciler
{
    private const FIELD_NAMES = [
        'phone' => 'phone number',
        'email' => 'e-mail address',
        'address' => 'address',
        'hours' => 'opening hours',
    ];

    private const WANTED = [
        'phone' => 'What phone number should customers call?',
        'address' => 'What is the address of the business?',
        'service' => 'What do you offer? List your main products or services.',
    ];

    public function run(Site $site): void
    {
        foreach ($site->sources()->whereNotNull('analysed_at')->get() as $source) {
            $this->settleIdentity($site, $source);
        }

        $this->settleFacts($site);
        $this->askForMissing($site);
    }

    /** Record which business in a source is the owner's, and use it to discard facts about others. */
    public function decide(Source $source, int|string $business): void
    {
        $identity = $source->identity ?? ['businesses' => []];
        $identity['decision'] = $business;
        $source->update(['identity' => $identity]);

        $others = $source->facts()->where('status', Fact::CANDIDATE);
        if ($business !== 'none') {
            $others->where('business', '!=', (int) $business);
        }
        $others->update(['status' => Fact::REJECTED]);
    }

    private function settleIdentity(Site $site, Source $source): void
    {
        $identity = $source->identity;
        $businesses = collect($identity['businesses'] ?? []);

        if ($businesses->isEmpty() || array_key_exists('decision', $identity)) {
            return;
        }

        $yes = $businesses->where('matches', 'yes');
        $unsure = $businesses->where('matches', 'unsure');

        // Exactly one business is clearly theirs and nothing is in doubt: no need to ask.
        if ($yes->count() === 1 && $unsure->isEmpty()) {
            $this->decide($source, $yes->first()['index']);

            return;
        }
        // Everything in this source is clearly about somebody else.
        if ($yes->isEmpty() && $unsure->isEmpty()) {
            $this->decide($source, 'none');

            return;
        }

        $this->askWhichBusiness($site, $source, $yes->concat($unsure)->sortBy('index'));
    }

    private function askWhichBusiness(Site $site, Source $source, Collection $candidates): void
    {
        if ($site->questions()->where('kind', Question::PICK_BUSINESS)->where('source_id', $source->id)->exists()) {
            return;
        }

        $label = fn (array $b) => collect([$b['name'] ?? null, $b['city'] ?? null])->filter()->implode(', ') ?: 'Unnamed business';

        $options = $candidates->map(fn ($b) => ['value' => (string) $b['index'], 'label' => $label($b)])->values()->all();
        $options[] = ['value' => 'none', 'label' => 'None of these is my business'];

        $site->questions()->create([
            'source_id' => $source->id,
            'kind' => Question::PICK_BUSINESS,
            'prompt' => $candidates->count() > 1
                ? "\"{$source->label}\" mentions several businesses. Which one is yours?"
                : "\"{$source->label}\" seems to be about \"{$label($candidates->first())}\". Is that your business?",
            'options' => $options,
        ]);
    }

    private function settleFacts(Site $site): void
    {
        $settledSources = $site->sources()->get()
            ->filter(fn (Source $s) => array_key_exists('decision', $s->identity ?? []))
            ->pluck('id');

        // Facts from sources we are still unsure about stay undecided.
        $candidates = $site->facts()->where('status', Fact::CANDIDATE)->get()
            ->filter(fn (Fact $f) => $f->source_id === null || $settledSources->contains($f->source_id));
        $confirmed = $site->facts()->where('status', Fact::CONFIRMED)->get();

        foreach (Fact::FIELDS as $field) {
            $fresh = $candidates->where('field', $field);

            if (in_array($field, Fact::SINGLE_VALUE, true)) {
                $this->settleSingle($site, $field, $fresh, $confirmed->where('field', $field));
            } else {
                $this->settleMany($fresh, $confirmed->where('field', $field));
            }
        }
    }

    /** A phone number, address, e-mail or opening hours: only one can be right. */
    private function settleSingle(Site $site, string $field, Collection $fresh, Collection $confirmed): void
    {
        $byValue = $confirmed->concat($fresh)->groupBy(fn (Fact $f) => Grounding::sameValueKey($field, $f->value));

        if ($byValue->count() > 1) {
            $this->askWhichIsRight($site, $field, $byValue);

            return;
        }

        $fresh->each->update(['status' => Fact::CONFIRMED]);
        // If the disagreement went away (for example a source was removed), drop the old question.
        $site->openQuestions()->where('kind', Question::CONFLICT)->where('field', $field)->delete();
    }

    /** Services, prices, descriptions, links: many are fine, but the same one twice is not. */
    private function settleMany(Collection $fresh, Collection $confirmed): void
    {
        $seen = $confirmed->map(fn (Fact $f) => Grounding::sameValueKey($f->field, $f->value))->flip();

        foreach ($fresh as $fact) {
            $key = Grounding::sameValueKey($fact->field, $fact->value);
            $fact->update(['status' => $seen->has($key) ? Fact::REJECTED : Fact::CONFIRMED]);
            $seen->put($key, true);
        }
    }

    private function askWhichIsRight(Site $site, string $field, Collection $byValue): void
    {
        $options = $byValue->map(function (Collection $facts) {
            $sources = $facts->map(fn (Fact $f) => $f->source?->label ?? 'You')->unique()->implode(', ');

            return ['value' => $facts->first()->value, 'label' => $facts->first()->value." (from {$sources})"];
        })->values()->all();

        $name = self::FIELD_NAMES[$field] ?? $field;
        $existing = $site->openQuestions()->where('kind', Question::CONFLICT)->where('field', $field)->first();

        if ($existing) {
            $existing->update(['options' => $options]);

            return;
        }

        $site->questions()->create([
            'kind' => Question::CONFLICT,
            'field' => $field,
            'prompt' => "Your sources give different {$name}s. Which one is correct? You can also type the right one.",
            'options' => $options,
        ]);
    }

    /** Ask for the basics we could not find. These questions never stop the site from being written. */
    private function askForMissing(Site $site): void
    {
        foreach (self::WANTED as $field => $prompt) {
            $known = $site->facts()->where('field', $field)->whereIn('status', [Fact::CONFIRMED, Fact::CANDIDATE])->exists();
            $asked = $site->questions()->where('kind', Question::MISSING)->where('field', $field)->exists();

            if (! $known && ! $asked) {
                $site->questions()->create(['kind' => Question::MISSING, 'field' => $field, 'prompt' => $prompt]);
            }
        }
    }
}
