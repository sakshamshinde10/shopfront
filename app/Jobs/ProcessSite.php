<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\Pipeline\SiteProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessSite implements ShouldQueue
{
    use Dispatchable, Queueable, SerializesModels;

    // The AI can take a while, and retrying a half-done run would only repeat paid work.
    public int $tries = 1;

    public int $timeout = 900;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Site $site) {}

    public function handle(SiteProcessor $processor): void
    {
        $processor->run($this->site);
    }

    public function failed(Throwable $e): void
    {
        $this->site->update([
            'status' => Site::FAILED,
            'notice' => 'Something went wrong while building your site. Your sources are saved, please try again.',
        ]);
        report($e);
    }
}
