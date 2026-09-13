<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | When disabled the package registers no listeners at all, so the Laravel
    | AI SDK runs exactly as if the package were not installed.
    |
    */

    'enabled' => env('AI_OTEL_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Driver
    |--------------------------------------------------------------------------
    |
    | Where spans go. "otlp" records real OpenTelemetry spans through the OTel
    | API (and exports them over OTLP when this package has to bootstrap the
    | SDK itself). "log" writes the same attributes to a Laravel log channel
    | and needs no OpenTelemetry SDK. "null" records nothing.
    |
    */

    'driver' => env('AI_OTEL_DRIVER', 'otlp'),

    'drivers' => [

        'otlp' => [
            // Used only when no TracerProvider is registered globally (for
            // example by open-telemetry/opentelemetry-auto-laravel). When one
            // exists it is reused and these settings are ignored.
            'endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT', 'http://localhost:4318'),
            'protocol' => env('OTEL_EXPORTER_OTLP_PROTOCOL', 'http/protobuf'), // http/protobuf or http/json
            'headers' => env('OTEL_EXPORTER_OTLP_HEADERS', ''), // "key=value,key2=value2"
            'timeout' => (float) env('OTEL_EXPORTER_OTLP_TIMEOUT', 10),
            'processor' => env('AI_OTEL_SPAN_PROCESSOR', 'batch'), // batch or simple
            'service_name' => env('OTEL_SERVICE_NAME', env('APP_NAME', 'laravel')),
            'resource' => [
                // 'deployment.environment.name' => env('APP_ENV'),
            ],
        ],

        'log' => [
            'channel' => env('AI_OTEL_LOG_CHANNEL'),
            'level' => env('AI_OTEL_LOG_LEVEL', 'info'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Scope activation
    |--------------------------------------------------------------------------
    |
    | When true, agent, step and tool spans are made the active OpenTelemetry
    | context while they run, so spans created by other instrumentation (the
    | provider HTTP call, database queries inside a tool) nest under them.
    |
    */

    'activate_scopes' => true,

    /*
    |--------------------------------------------------------------------------
    | Content capture
    |--------------------------------------------------------------------------
    |
    | Prompts, instructions, model output, tool arguments and tool results are
    | NEVER recorded unless "content" is true. This mirrors the OpenTelemetry
    | GenAI opt-in flag OTEL_INSTRUMENTATION_GENAI_CAPTURE_MESSAGE_CONTENT.
    | Individual strings are truncated to "max_length" characters and passed
    | through the optional redactor before they are written to a span.
    |
    */

    'capture' => [
        'content' => (bool) env('OTEL_INSTRUMENTATION_GENAI_CAPTURE_MESSAGE_CONTENT', false),
        'max_length' => (int) env('AI_OTEL_CAPTURE_MAX_LENGTH', 8192),
        'redactor' => null, // class-string<\Literaj\AiOtel\Contracts\Redactor>
    ],

    /*
    |--------------------------------------------------------------------------
    | Operations
    |--------------------------------------------------------------------------
    */

    'operations' => [
        'agents' => true,
        'embeddings' => true,
    ],

];
