<?php

namespace App\Services\Pipeline;

use App\Models\Site;
use App\Models\Source;
use App\Services\Ai\AiRefusedContent;
use App\Services\Ai\AiUnavailable;

/**
 * Runs the whole job for one site: search the web, read every source, decide what can be trusted,
 * ask the owner about anything doubtful, and only then write the sections.
 * It can be started again at any time and carries on where it left off.
 */
class SiteProcessor
{
    public function __construct(
        private readonly WebResearcher $researcher,
        private readonly FactExtractor $extractor,
        private readonly Reconciler $reconciler,
        private readonly SectionWriter $writer,
    ) {}

    public function run(Site $site): void
    {
        $site->update(['status' => Site::PROCESSING, 'notice' => null]);

        try {
            $this->researcher->research($site);
            $this->readSources($site);
            $this->reconciler->run($site);
        } catch (AiUnavailable $e) {
            // Everything the owner gave us is saved. They can press "generate" again later.
            $site->update(['status' => Site::FAILED, 'notice' => $e->getMessage()]);

            return;
        }

        if ($site->hasBlockingQuestions()) {
            $site->update([
                'status' => Site::NEEDS_INPUT,
                'notice' => 'We need a few answers from you before we can write your site.',
            ]);

            return;
        }

        $usedAi = $this->writer->write($site)->usedAi;

        $site->update([
            'status' => Site::READY,
            'notice' => $usedAi ? null : 'The AI was not available, so this is a plain version built only from your confirmed details. Use "regenerate" later for polished text.',
        ]);
    }

    private function readSources(Site $site): void
    {
        $waiting = $site->sources()->where('status', Source::DONE)->whereNull('analysed_at')->get();

        foreach ($waiting as $source) {
            try {
                $this->extractor->analyse($site, $source);
            } catch (AiRefusedContent) {
                // The AI declined this one source. Skip it and keep going. Anything else (no key,
                // outage) is not about this source, so it is allowed to stop the run.
                $source->update(['analysed_at' => now(), 'warning' => 'The AI could not read this source.']);
            }
        }
    }
}
