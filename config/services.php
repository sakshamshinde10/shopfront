<?php

return [

    // Keys come from .env and are never committed. See .env.example.
    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        // The first model is tried first. If it is busy or gone, the next ones are tried.
        'models' => array_values(array_filter(array_map('trim', explode(',', (string) env(
            'GEMINI_MODELS',
            'gemini-3.5-flash,gemini-3.8-flash,gemini-3.1-flash-lite',
        ))))),
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
        'timeout' => 60,
    ],

];
