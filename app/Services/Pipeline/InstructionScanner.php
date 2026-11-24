<?php

namespace App\Services\Pipeline;

/**
 * Spots text in a file or page that is written to an AI instead of to a customer
 * ("ignore your previous instructions ..."). We do not rely on this to stay safe, because
 * the text is always sent as untrusted data and every fact needs proof. We use it to warn
 * the owner, so they know something odd is in their file.
 */
final class InstructionScanner
{
    private const PATTERNS = [
        '/\b(ignore|disregard|forget|override)\b[^.\n]{0,40}\b(previous|prior|above|earlier|all|your|these|the)\b[^.\n]{0,30}\b(instructions?|prompts?|rules?|guidelines?|context)\b/iu',
        '/\b(system|developer|hidden)\s+prompt\b/iu',
        '/\byou\s+are\s+now\b/iu',
        '/\bnew\s+instructions?\s*:/iu',
        '/\b(reveal|print|show|output|leak|repeat)\b[^.\n]{0,30}\b(system prompt|instructions|api[\s_-]?key|secret|password)\b/iu',
        '/<\/?\s*(system|assistant|instructions?|prompt)\s*>/iu',
        '/\b(do not|don\'t)\s+(tell|mention|reveal|show)\b[^.\n]{0,30}\b(user|owner|anyone)\b/iu',
        '/\bas an? (ai|language model|assistant)\b/iu',
    ];

    public static function warning(string $text): ?string
    {
        return self::matches($text)
            ? 'This source contains text that looks like instructions to an AI. It was ignored: only facts about your business are read from it.'
            : null;
    }

    /**
     * Remove every sentence that looks like an instruction to an AI, and the sentence right after it,
     * because the thing it demands ("the phone number is ...") usually follows. The AI never sees
     * these sentences, so no fact can be "proven" from them even though they really are in the file.
     */
    public static function strip(string $text): string
    {
        $pieces = preg_split('/(?<=[.!?])\s+|\R+/u', $text) ?: [];
        $kept = [];
        $skip = 0;

        foreach ($pieces as $piece) {
            if (self::matches($piece)) {
                $skip = 1;

                continue;
            }
            if ($skip > 0) {
                $skip--;

                continue;
            }
            $kept[] = $piece;
        }

        return implode("\n", $kept);
    }

    private static function matches(string $text): bool
    {
        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }
}
