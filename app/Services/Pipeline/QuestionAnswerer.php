<?php

namespace App\Services\Pipeline;

use App\Models\Fact;
use App\Models\Question;
use Illuminate\Validation\ValidationException;

/** Applies the owner's answer to a question. Whatever the owner says is trusted over any source. */
class QuestionAnswerer
{
    public function __construct(private readonly Reconciler $reconciler) {}

    public function answer(Question $question, string $answer): void
    {
        $answer = trim($answer);

        match ($question->kind) {
            Question::PICK_BUSINESS => $this->pickBusiness($question, $answer),
            Question::CONFLICT => $this->resolveConflict($question, $answer),
            default => $this->fillMissing($question, $answer),
        };

        $question->update(['answer' => $answer, 'answered_at' => now()]);
    }

    private function pickBusiness(Question $question, string $answer): void
    {
        $allowed = collect($question->options)->pluck('value')->all();
        if (! in_array($answer, $allowed, true)) {
            throw ValidationException::withMessages(['answer' => 'Please choose one of the options.']);
        }

        $this->reconciler->decide($question->source, $answer === 'none' ? 'none' : (int) $answer);
    }

    private function resolveConflict(Question $question, string $answer): void
    {
        $field = (string) $question->field;
        $this->validateValue($field, $answer);

        $key = Grounding::sameValueKey($field, $answer);
        $facts = $question->site->facts()->where('field', $field)->whereIn('status', [Fact::CANDIDATE, Fact::CONFIRMED])->get();
        $matching = $facts->filter(fn (Fact $f) => Grounding::sameValueKey($field, $f->value) === $key);

        // The owner typed something none of the sources said: record it as their own statement.
        if ($matching->isEmpty()) {
            $question->site->facts()->create(['field' => $field, 'value' => $answer, 'status' => Fact::CONFIRMED]);
        }

        $matching->each->update(['status' => Fact::CONFIRMED]);
        $facts->diff($matching)->each->update(['status' => Fact::REJECTED]);
    }

    private function fillMissing(Question $question, string $answer): void
    {
        if (strtolower($answer) === 'skip') {
            return;
        }

        $this->validateValue((string) $question->field, $answer);
        $question->site->facts()->create(['field' => $question->field, 'value' => $answer, 'status' => Fact::CONFIRMED]);
    }

    private function validateValue(string $field, string $value): void
    {
        $bad = match ($field) {
            'phone' => strlen(Grounding::digits($value)) < 7,
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) === false,
            default => $value === '',
        };

        if ($bad || mb_strlen($value) > 300) {
            throw ValidationException::withMessages(['answer' => "That does not look like a valid {$field}."]);
        }
    }
}
