<?php

namespace App\Services\Pipeline;

use Illuminate\Support\Str;

/**
 * The "never invent facts" checks. They run in plain code, after the AI has answered,
 * so they hold even if the AI ignores its instructions.
 */
final class Grounding
{
    /** Claims a business could make about itself that we must never write unless the owner supplied them. */
    private const CLAIM_WORDS = [
        'award', 'best ', 'number one', 'no. 1', '#1', 'leading', 'trusted by', 'guarantee', 'certified',
        'founded', 'established', 'since ', 'years of', 'family-owned', 'family owned', 'famous', 'renowned',
        'rated', 'top-rated', 'voted', 'locally sourced', 'organic', 'handmade', 'fresh daily', 'free delivery',
    ];

    /** Lower-case, one space between words, curly quotes straightened. */
    public static function normalise(string $text): string
    {
        $text = str_replace(["\u{2018}", "\u{2019}", "\u{201C}", "\u{201D}", "\u{00A0}", "\u{200B}"], ["'", "'", '"', '"', ' ', ''], $text);

        return trim(preg_replace('/\s+/u', ' ', mb_strtolower($text)) ?? '');
    }

    public static function digits(string $text): string
    {
        return preg_replace('/\D+/', '', $text) ?? '';
    }

    /** Is this quote really in the source, word for word (ignoring case and spacing)? */
    public static function quoteIsInSource(string $quote, string $source): bool
    {
        $quote = self::normalise($quote);

        return mb_strlen($quote) >= 3 && str_contains(self::normalise($source), $quote);
    }

    /** Does the value the AI reported really appear in the source? Phone numbers are compared by digits only. */
    public static function valueIsInSource(string $field, string $value, string $source): bool
    {
        if ($field === 'phone') {
            $digits = self::digits($value);

            return strlen($digits) >= 7 && str_contains(self::digits($source), $digits);
        }

        $value = self::normalise($value);

        return $value !== '' && str_contains(self::normalise($source), $value);
    }

    /** A key for spotting the same value written two ways ("+91 98765-43210" and "9876543210"). */
    public static function sameValueKey(string $field, string $value): string
    {
        return match ($field) {
            'phone' => substr(self::digits($value), -10),
            'email' => self::normalise($value),
            default => Str::of(self::normalise($value))->replaceMatches('/[^\p{L}\p{N} ]+/u', '')->squish()->toString(),
        };
    }

    /**
     * Anything in generated prose that is not backed by the evidence: numbers, e-mail addresses,
     * links, and boastful claims. Returns what was found, empty when the text is clean.
     *
     * @return list<string>
     */
    public static function unsupported(string $prose, string $evidence): array
    {
        $evidenceText = self::normalise($evidence);
        $evidenceDigits = self::digits($evidence);
        $found = [];

        preg_match_all('/\d[\d,.:\/-]*/u', $prose, $numbers);
        foreach ($numbers[0] as $number) {
            $number = rtrim($number, '.,:/-');
            $digits = self::digits($number);
            if ($digits !== '' && ! str_contains($evidenceDigits, $digits)) {
                $found[] = $number;
            }
        }

        preg_match_all('/[\w.+-]+@[\w-]+\.[\w.-]+|https?:\/\/\S+|www\.\S+/iu', $prose, $contacts);
        foreach ($contacts[0] as $contact) {
            $contact = rtrim($contact, '.,)!?');
            if (! str_contains($evidenceText, self::normalise($contact))) {
                $found[] = $contact;
            }
        }

        $proseText = self::normalise($prose);
        foreach (self::CLAIM_WORDS as $word) {
            if (str_contains($proseText, $word) && ! str_contains($evidenceText, trim($word))) {
                $found[] = trim($word);
            }
        }

        return array_values(array_unique($found));
    }

    /** Keep only the sentences that pass the check. */
    public static function keepSupportedSentences(string $prose, string $evidence): string
    {
        $sentences = preg_split('/(?<=[.!?])\s+/u', trim($prose)) ?: [];

        return trim(collect($sentences)
            ->filter(fn ($sentence) => self::unsupported($sentence, $evidence) === [])
            ->implode(' '));
    }
}
