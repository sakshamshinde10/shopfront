<?php

namespace Tests\Support;

use App\Services\Extraction\HostResolver;

trait CreatesSites
{
    protected function fakeDns(array $map = []): void
    {
        $this->app->instance(HostResolver::class, new FakeHostResolver($map));
    }

    /** @return array{0:int,1:string} site id and its edit token */
    protected function createSite(array $extra = []): array
    {
        $response = $this->postJson('/api/sites', array_merge([
            'business_name' => 'Sharma Bakery',
            'city' => 'Pune',
        ], $extra))->assertCreated();

        return [$response->json('site.id'), $response->json('edit_token')];
    }

    protected function asOwner(string $token): static
    {
        return $this->withToken($token);
    }

    /** A tiny but valid PDF containing the given line of text. */
    protected function pdfWithText(string $text): string
    {
        $stream = "BT /F1 12 Tf 20 100 Td ({$text}) Tj ET";
        $objects = [
            1 => '<</Type/Catalog/Pages 2 0 R>>',
            2 => '<</Type/Pages/Kids[3 0 R]/Count 1>>',
            3 => '<</Type/Page/Parent 2 0 R/MediaBox[0 0 400 144]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>',
            4 => '<</Length '.strlen($stream).">>\nstream\n{$stream}\nendstream",
            5 => '<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= "{$number} 0 obj\n{$body}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<</Size 6/Root 1 0 R>>\nstartxref\n{$xref}\n%%EOF";
    }
}
