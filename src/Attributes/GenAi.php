<?php

declare(strict_types=1);

namespace CristeaIulian\AiOtel\Attributes;

/**
 * Attribute names from the OpenTelemetry GenAI semantic conventions.
 *
 * The names follow the GenAI conventions as published in the
 * open-telemetry/semantic-conventions-genai repository (v1.44 line). They are
 * declared here instead of depending on open-telemetry/sem-conv because that
 * package still ships the older `gen_ai.system` generation of the names.
 *
 * @see https://github.com/open-telemetry/semantic-conventions-genai
 */
final class GenAi
{
    public const OPERATION_NAME = 'gen_ai.operation.name';

    public const PROVIDER_NAME = 'gen_ai.provider.name';

    public const AGENT_NAME = 'gen_ai.agent.name';

    public const AGENT_DESCRIPTION = 'gen_ai.agent.description';

    public const CONVERSATION_ID = 'gen_ai.conversation.id';

    public const REQUEST_MODEL = 'gen_ai.request.model';

    public const REQUEST_MAX_TOKENS = 'gen_ai.request.max_tokens';

    public const REQUEST_TEMPERATURE = 'gen_ai.request.temperature';

    public const REQUEST_TOP_P = 'gen_ai.request.top_p';

    public const REQUEST_STREAM = 'gen_ai.request.stream';

    public const RESPONSE_MODEL = 'gen_ai.response.model';

    public const RESPONSE_ID = 'gen_ai.response.id';

    public const RESPONSE_FINISH_REASONS = 'gen_ai.response.finish_reasons';

    public const USAGE_INPUT_TOKENS = 'gen_ai.usage.input_tokens';

    public const USAGE_OUTPUT_TOKENS = 'gen_ai.usage.output_tokens';

    public const USAGE_CACHE_READ_INPUT_TOKENS = 'gen_ai.usage.cache_read.input_tokens';

    public const USAGE_CACHE_WRITE_INPUT_TOKENS = 'gen_ai.usage.cache_write.input_tokens';

    public const USAGE_REASONING_OUTPUT_TOKENS = 'gen_ai.usage.reasoning.output_tokens';

    public const OUTPUT_TYPE = 'gen_ai.output.type';

    public const EMBEDDINGS_DIMENSION_COUNT = 'gen_ai.embeddings.dimension.count';

    public const TOOL_NAME = 'gen_ai.tool.name';

    public const TOOL_DESCRIPTION = 'gen_ai.tool.description';

    public const TOOL_TYPE = 'gen_ai.tool.type';

    public const TOOL_CALL_ID = 'gen_ai.tool.call.id';

    public const TOOL_CALL_ARGUMENTS = 'gen_ai.tool.call.arguments';

    public const TOOL_CALL_RESULT = 'gen_ai.tool.call.result';

    public const SYSTEM_INSTRUCTIONS = 'gen_ai.system_instructions';

    public const INPUT_MESSAGES = 'gen_ai.input.messages';

    public const OUTPUT_MESSAGES = 'gen_ai.output.messages';

    public const ERROR_TYPE = 'error.type';

    public const OPERATION_CHAT = 'chat';

    public const OPERATION_INVOKE_AGENT = 'invoke_agent';

    public const OPERATION_EXECUTE_TOOL = 'execute_tool';

    public const OPERATION_EMBEDDINGS = 'embeddings';

    public const OUTPUT_TYPE_TEXT = 'text';

    public const OUTPUT_TYPE_JSON = 'json';

    public const TOOL_TYPE_FUNCTION = 'function';

    public const FINISH_REASON_ERROR = 'error';
}
