<?php

namespace App\Jobs;

use App\Models\Source;
use App\Services\Extraction\SourceExtractor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ExtractSource implements ShouldQueue
{
    use Dispatchable, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    // The owner may delete a source before we get to it. Then there is nothing to do.
    public bool $deleteWhenMissingModels = true;

    public function __construct(public Source $source)
    {
        // Do not start before the request that created the source has been saved.
        $this->afterCommit();
    }

    public function handle(SourceExtractor $extractor): void
    {
        $extractor->run($this->source);
    }

    /** Unexpected crash: record it so the owner sees "failed" instead of "pending" forever. */
    public function failed(Throwable $e): void
    {
        $this->source->update(['status' => Source::FAILED, 'error' => 'Reading this source failed. Please try again.']);
        report($e);
    }
}
