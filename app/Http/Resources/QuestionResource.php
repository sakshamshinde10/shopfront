<?php

namespace App\Http\Resources;

use App\Models\Question;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Question */
class QuestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'field' => $this->field,
            'prompt' => $this->prompt,
            // Choices to show as buttons. For "missing" questions the owner just types an answer.
            'options' => $this->options,
            // A blocking question has to be answered before the site can be written.
            'blocking' => in_array($this->kind, Question::BLOCKING, true),
            'answer' => $this->answer,
            'answered' => $this->answered_at !== null,
        ];
    }
}
