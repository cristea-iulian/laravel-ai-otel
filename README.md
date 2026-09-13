# OpenTelemetry tracing for the Laravel AI SDK

[![Tests](https://github.com/literaj/laravel-ai-otel/actions/workflows/tests.yml/badge.svg)](https://github.com/literaj/laravel-ai-otel/actions/workflows/tests.yml)
[![Nightly against laravel/ai dev](https://github.com/literaj/laravel-ai-otel/actions/workflows/nightly.yml/badge.svg)](https://github.com/literaj/laravel-ai-otel/actions/workflows/nightly.yml)
[![Latest Version](https://img.shields.io/packagist/v/literaj/laravel-ai-otel.svg)](https://packagist.org/packages/literaj/laravel-ai-otel)
[![License](https://img.shields.io/packagist/l/literaj/laravel-ai-otel.svg)](LICENSE.md)

`literaj/laravel-ai-otel` turns the events the [Laravel AI SDK](https://laravel.com/docs/ai-sdk) already fires into
[OpenTelemetry](https://opentelemetry.io) spans that follow the
[GenAI semantic conventions](https://github.com/open-telemetry/semantic-conventions-genai).
Point it at any OTLP backend and you get, for every agent run:

```text
invoke_agent SupportAgent              INTERNAL   1.9 s   gen_ai.usage.input_tokens=2431 …
├── chat claude-sonnet-4-5             CLIENT     620 ms  finish_reasons=[tool_calls]
├── execute_tool LookupOrder           INTERNAL   85 ms   gen_ai.tool.call.id=toolu_01…
├── chat claude-sonnet-4-5             CLIENT     540 ms  finish_reasons=[tool_calls]
├── execute_tool ResearchAgent         INTERNAL   610 ms
│   └── invoke_agent ResearchAgent     INTERNAL   600 ms
│       └── chat gpt-5-mini            CLIENT     590 ms
└── chat claude-sonnet-4-5             CLIENT     410 ms  finish_reasons=[stop]
```

Which step was slow, which tool failed, how many tokens each turn burned, and how a sub-agent's work nests under the
tool that delegated to it. Works with Arize Phoenix, Langfuse, Grafana Tempo, Jaeger, Datadog, Honeycomb, or anything
else that speaks OTLP, because the spans use the standard `gen_ai.*` attributes those tools already understand.

Prompts and completions are **never recorded unless you opt in**. See [Privacy](#privacy-and-content-capture).

## Requirements

- PHP 8.3+
- Laravel 12 or 13
- `laravel/ai` 0.11.x (the SDK is pre-1.0; this package pins the minor and is
  [tested nightly](.github/workflows/nightly.yml) against the SDK's development branch)

## Installation

```bash
composer require literaj/laravel-ai-otel
```

The package registers itself. It depends only on the lightweight OpenTelemetry **API**; how spans leave your
application depends on which of the three setups below you use.

### Setup A: you already run OpenTelemetry

If [`open-telemetry/opentelemetry-auto-laravel`](https://packagist.org/packages/open-telemetry/opentelemetry-auto-laravel)
(or any SDK autoloader) registers a global `TracerProvider`, there is nothing more to do. Agent spans are recorded
through it and nest under the HTTP request or queue job span that triggered them.

### Setup B: you have no OpenTelemetry yet

Install the SDK and the OTLP exporter, then tell the package where to send spans:

```bash
composer require open-telemetry/sdk open-telemetry/exporter-otlp
```

```dotenv
OTEL_EXPORTER_OTLP_ENDPOINT=http://localhost:4318
OTEL_SERVICE_NAME=my-app
```

The package bootstraps a `TracerProvider` with a batch processor and an OTLP/HTTP exporter, and flushes it after every
request (Octane included), after every queue job, and at shutdown.

### Setup C: no OpenTelemetry at all

```dotenv
AI_OTEL_DRIVER=log
```

The same data is written as one structured log line per operation, using the same `gen_ai.*` keys. Useful for local
development or when a log pipeline is all you have.

## See it locally in two minutes

[Arize Phoenix](https://github.com/Arize-ai/phoenix) is a single container with a UI built for GenAI traces:

```bash
docker run -p 6006:6006 -p 4317:4317 arizephoenix/phoenix:latest
```

```dotenv
OTEL_EXPORTER_OTLP_ENDPOINT=http://localhost:6006
```

Run any agent, then open <http://localhost:6006>.

For [Langfuse](https://langfuse.com) point the endpoint at `https://cloud.langfuse.com/api/public/otel` and set
`OTEL_EXPORTER_OTLP_HEADERS="Authorization=Basic <base64(public:secret)>"`.

There is a ready-made demo app with a support agent, two tools and a sub-agent at
[literaj/laravel-ai-otel-demo](https://github.com/literaj/laravel-ai-otel-demo). It runs with `--fake`, so no API
key is needed to see a full trace.

## What is recorded

| Span | Kind | Created from | Key attributes |
| --- | --- | --- | --- |
| `invoke_agent {Agent}` | `INTERNAL` | `PromptingAgent` / `StreamingAgent` → `AgentPrompted` / `AgentStreamed` / `AgentFailed` | `gen_ai.agent.name`, `gen_ai.provider.name`, `gen_ai.request.model`, `gen_ai.request.stream`, `gen_ai.usage.*`, `gen_ai.response.finish_reasons`, `gen_ai.conversation.id`, `laravel.ai.invocation.id` |
| `chat {model}` | `CLIENT` | `StartingStep` → `StepCompleted` / `StepFailed` | `gen_ai.request.max_tokens`, `gen_ai.request.temperature`, `gen_ai.request.top_p`, `gen_ai.response.model`, `gen_ai.response.finish_reasons`, `gen_ai.usage.*` (incl. cache and reasoning tokens), `laravel.ai.step.number` |
| `execute_tool {tool}` | `INTERNAL` | `InvokingTool` → `ToolInvoked` / `ToolFailed` | `gen_ai.tool.name`, `gen_ai.tool.type`, `gen_ai.tool.call.id`, `gen_ai.agent.name`, `laravel.ai.tool_invocation.id` |
| `embeddings {model}` | `CLIENT` | `GeneratingEmbeddings` → `EmbeddingsGenerated` | `gen_ai.embeddings.dimension.count`, `gen_ai.usage.input_tokens`, `laravel.ai.embeddings.input_count` |

Also recorded:

- **Errors.** A failed step, tool or run sets span status `ERROR`, records the exception as a span event, sets
  `error.type` to the exception class and `gen_ai.response.finish_reasons=["error"]`.
- **Failover.** When the SDK fails over to another provider, the `invoke_agent` span gets a `laravel.ai.failover`
  event with the provider, model and error, then a `laravel.ai.retry` event; `laravel.ai.invocation.attempt` counts
  attempts. The failed attempt's `chat` span keeps its error status.
- **Sub-agents.** An agent used as a tool produces its own `invoke_agent` span as a child of the parent's
  `execute_tool` span, with `laravel.ai.invocation.parent_id` and `laravel.ai.tool_invocation.parent_id`.
- **Tool approvals.** A run that pauses for approval ends its span with `laravel.ai.pending_approvals` and a
  `laravel.ai.tool_approval.requested` event.
- **Structured output.** `gen_ai.output.type=json` and `laravel.ai.structured=true`.

Attributes under `laravel.ai.*` carry SDK-specific detail that has no GenAI equivalent: invocation ids, the provider's
name as configured in `config/ai.php` (`laravel.ai.provider.name`, so `primary` and `backup` stay distinguishable while
`gen_ai.provider.name` says `groq` for both), agent and tool class names, and step numbers.

## Privacy and content capture

By default no prompt, instruction, model output, tool argument or tool result leaves your application. Only metadata
(models, providers, token counts, durations, tool names, finish reasons, ids) is recorded. This follows the GenAI
conventions, which make content capture opt-in.

To capture content, enable it and, if you need to, bound and scrub it:

```dotenv
OTEL_INSTRUMENTATION_GENAI_CAPTURE_MESSAGE_CONTENT=true
AI_OTEL_CAPTURE_MAX_LENGTH=8192
```

```php
// config/ai-otel.php
'capture' => [
    'content' => true,
    'max_length' => 8192,
    'redactor' => App\Telemetry\PiiRedactor::class,
],
```

```php
use Literaj\AiOtel\Contracts\Redactor;

class PiiRedactor implements Redactor
{
    public function redact(string $content, string $attribute): string
    {
        return preg_replace('/[\w.+-]+@[\w-]+\.[\w.]+/', '[email]', $content) ?? '';
    }
}
```

With capture on, `gen_ai.system_instructions`, `gen_ai.input.messages`, `gen_ai.output.messages`,
`gen_ai.tool.call.arguments`, `gen_ai.tool.call.result` and `gen_ai.tool.description` are recorded. Messages use the
JSON structure defined by the GenAI conventions (`{"role": "user", "parts": [{"type": "text", "content": "…"}]}`),
serialized to a JSON string on the span. Every string is passed through the redactor and then truncated to
`max_length` characters, individually, so the JSON structure stays intact.

## Queues and trace context

Spans are created under whatever OpenTelemetry context is active. With `opentelemetry-auto-laravel` installed, a
queued agent run appears under its job span, and the job under the request that dispatched it, because that package
propagates trace context through the queue. Without it, each queued run is its own trace; use
`laravel.ai.invocation.id` (also available as `$response->invocationId`) to correlate.

By default agent, step and tool spans are also made the *active* span while they run, so spans from other
instrumentation (the provider's HTTP call, database queries inside a tool) nest under them. Set
`'activate_scopes' => false` to only link spans by explicit parent.

## Configuration

```bash
php artisan vendor:publish --tag=ai-otel-config
```

| Key | Env | Default | Meaning |
| --- | --- | --- | --- |
| `enabled` | `AI_OTEL_ENABLED` | `true` | Register listeners at all. |
| `driver` | `AI_OTEL_DRIVER` | `otlp` | `otlp`, `log` or `null`. |
| `drivers.otlp.endpoint` | `OTEL_EXPORTER_OTLP_ENDPOINT` | `http://localhost:4318` | Used only when the package bootstraps the SDK itself. `/v1/traces` is appended if missing. |
| `drivers.otlp.protocol` | `OTEL_EXPORTER_OTLP_PROTOCOL` | `http/protobuf` | `http/protobuf` or `http/json`. For gRPC register your own provider. |
| `drivers.otlp.headers` | `OTEL_EXPORTER_OTLP_HEADERS` | | `key=value,key2=value2`. |
| `drivers.otlp.processor` | `AI_OTEL_SPAN_PROCESSOR` | `batch` | `batch` or `simple`. |
| `drivers.otlp.service_name` | `OTEL_SERVICE_NAME` | `APP_NAME` | Resource `service.name`. |
| `drivers.log.channel` | `AI_OTEL_LOG_CHANNEL` | default channel | Log channel for the `log` driver. |
| `drivers.log.level` | `AI_OTEL_LOG_LEVEL` | `info` | Level for successful operations. Failures log at `error`. |
| `activate_scopes` | | `true` | Make agent, step and tool spans the active context while they run. |
| `capture.content` | `OTEL_INSTRUMENTATION_GENAI_CAPTURE_MESSAGE_CONTENT` | `false` | Record prompts, outputs, tool arguments and results. |
| `capture.max_length` | `AI_OTEL_CAPTURE_MAX_LENGTH` | `8192` | Per-string truncation when capturing. |
| `capture.redactor` | | `null` | Class implementing `Literaj\AiOtel\Contracts\Redactor`. |
| `operations.agents` | | `true` | Trace agent runs. |
| `operations.embeddings` | | `true` | Trace embeddings. |

### How the `otlp` driver finds a TracerProvider

1. A `OpenTelemetry\API\Trace\TracerProviderInterface` bound in the container.
2. The globally registered provider (`Globals::tracerProvider()`), if it is not the no-op one.
3. A provider bootstrapped from the config above, if `open-telemetry/sdk` and `open-telemetry/exporter-otlp` are
   installed.
4. Otherwise a `MissingOpenTelemetrySdkException` that names the packages to install.

### Custom drivers

Anything can consume the telemetry by implementing `Literaj\AiOtel\Contracts\Recorder`, which receives SDK-agnostic
events (`InvocationStarted`, `StepEnded`, `ToolFailed`, `EmbeddingsGenerated`, …):

```php
use Literaj\AiOtel\RecorderManager;

app(RecorderManager::class)->extend('metrics', fn ($app, array $config) => new MyMetricsRecorder);
```

```dotenv
AI_OTEL_DRIVER=metrics
```

The Laravel AI listener is the only class that knows about the SDK's event payloads; recorders never see them. That is
what keeps a future adapter for another SDK a small addition.

## Guarantees and limits

- **Step semantics come from the SDK.** A `chat` span is exactly one `StartingStep` → `StepCompleted`/`StepFailed`
  pair as the SDK reports it. Failover attempts share one `invoke_agent` span (one invocation id in the SDK) and are
  distinguished by `laravel.ai.provider.name` on their `chat` spans and by the `laravel.ai.failover` event.
- **Abandoned streams.** If a consumer stops iterating a stream before it ends, the SDK fires no end event. Open step
  and tool spans are closed with `laravel.ai.abandoned=true` when the run ends; if the run never ends, they are
  closed when the process ends.
- **Time to first chunk** (`gen_ai.response.time_to_first_chunk`) is not recorded: stream chunks are yielded to
  your code, not dispatched as events.
- **Embeddings failures** are not recorded: the SDK has no `EmbeddingsFailed` event. An embeddings span is created
  once generation completes, with the recorded start time.
- **`ProviderFailedOver` outside agent runs** (embeddings, images, audio) is not recorded in this version.
- **Images, audio, transcription, reranking and file stores** are not traced yet. See the roadmap.

## Roadmap

- Metrics (`gen_ai.client.token.usage`, `gen_ai.client.operation.duration`) through the same recorder contract.
- Spans for image, audio, transcription, reranking and file store operations.
- A Prism PHP adapter, when its event surface settles.

## Testing

```bash
composer test
composer analyse
composer lint
```

The suite drives the real Laravel AI event flow with the SDK's own fakes (`Agent::fake()`, `Embeddings::fake()`,
faked HTTP for failover) and asserts on spans captured by an in-memory exporter.

## License

MIT. See [LICENSE.md](LICENSE.md).
