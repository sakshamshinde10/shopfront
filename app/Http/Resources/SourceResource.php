<?php

namespace App\Http\Resources;

use App\Models\Source;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Source */
class SourceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'label' => $this->label,
            'url' => $this->url,
            'status' => $this->status,
            // For "blocked" and "failed" this tells the owner what to do next.
            'message' => $this->error,
            'created_at' => $this->created_at,
        ];
    }
}
