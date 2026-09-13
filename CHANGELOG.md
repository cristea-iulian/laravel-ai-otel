# Changelog

All notable changes to `literaj/laravel-ai-otel` are documented here.

## Unreleased

### Added

- `invoke_agent`, `chat` and `execute_tool` spans for Laravel AI agent runs (sync and streaming), including nested sub-agents, failover events and tool approval events.
- `embeddings` spans.
- `otlp`, `log` and `null` drivers.
- Opt-in content capture with truncation and a redaction hook.

### Planned

- Metrics: `gen_ai.client.token.usage` and `gen_ai.client.operation.duration`.
- Spans for image, audio, transcription, reranking and file store operations.
- Prism PHP adapter.
