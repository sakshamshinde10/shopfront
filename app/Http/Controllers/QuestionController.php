<?php

namespace App\Http\Controllers;

use App\Http\Requests\AnswerQuestionRequest;
use App\Http\Resources\SiteResource;
use App\Jobs\ProcessSite;
use App\Models\Question;
use App\Models\Site;
use App\Services\Pipeline\QuestionAnswerer;
use Illuminate\Http\JsonResponse;

class QuestionController extends Controller
{
    public function answer(AnswerQuestionRequest $request, Site $site, Question $question, QuestionAnswerer $answerer): SiteResource|JsonResponse
    {
        if (! $question->isOpen()) {
            return response()->json(['message' => 'This question was already answered.'], 409);
        }

        $answerer->answer($question, $request->string('answer')->toString());

        // The last blocking question is answered: carry on and write the site.
        if ($site->status === Site::NEEDS_INPUT && ! $site->hasBlockingQuestions()) {
            Site::whereKey($site->id)->where('status', Site::NEEDS_INPUT)->update(['status' => Site::PROCESSING, 'notice' => null]);
            ProcessSite::dispatch($site);
        }

        return new SiteResource($site->refresh()->load(['sources', 'openQuestions', 'sections']));
    }
}
