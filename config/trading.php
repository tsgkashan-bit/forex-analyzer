<?php

return [
    'providers' => [
        'twelve_data' => ['key' => env('TWELVE_DATA_API_KEY')],
    ],
    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3.5-flash'),
    ],
    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4.1-mini'),
    ],
    'disclaimer' => 'This analysis is probabilistic and educational, not financial advice or a guarantee of profit. Verify data independently and use appropriate risk management.',
];
