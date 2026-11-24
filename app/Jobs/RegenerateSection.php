<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\Ai\AiUnavailable;
use App\Services\Pipeline\SectionWriter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Throwable;

/** Rewrites one section from the confirmed facts, in the background, because the AI can be slow. */
class RegenerateSection implements ShouldQueue
{
    use Dispatchable, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Site $site, public string $key) {}

    public function handle(SectionWriter $writer): void
    {
        try {
            $outcome = $writer->write($this->site, [$this->key], force: true);
            $notice = in_array($this->key, $outcome->written, true)
                ? null
                : 'There is not enough confirmed information to write this section yet.';
        } catch (AiUnavailable $e) {
            // Nothing was changed. The owner keeps their current text.
            $notice = $e->getMessage().' Your current text was kept.';
        }

        $this->site->update(['regenerating' => null, 'notice' => $notice]);
    }

    public function failed(Throwable $e): void
    {
        $this->site->update(['regenerating' => null, 'notice' => 'Something went wrong while rewriting that section. Your current text was kept.']);
        report($e);
    }
}
