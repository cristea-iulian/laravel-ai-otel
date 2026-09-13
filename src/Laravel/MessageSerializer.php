<?php

declare(strict_types=1);

namespace CristeaIulian\AiOtel\Laravel;

use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Stringable;

/**
 * Serializes Laravel AI messages into the message structures defined by the
 * GenAI semantic conventions (gen_ai.input.messages / gen_ai.output.messages).
 */
final class MessageSerializer
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function instructions(string $instructions): array
    {
        return [['type' => 'text', 'content' => $instructions]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function userPrompt(string $prompt): array
    {
        return [['role' => 'user', 'parts' => [['type' => 'text', 'content' => $prompt]]]];
    }

    /**
     * @param  array<array-key, mixed>  $messages
     * @return list<array<string, mixed>>
     */
    public static function messages(array $messages): array
    {
        $serialized = [];

        foreach ($messages as $message) {
            if ($message instanceof Message) {
                $serialized[] = self::message($message);
            }
        }

        return $serialized;
    }

    /**
     * @param  array<array-key, mixed>  $toolCalls
     * @return list<array<string, mixed>>
     */
    public static function output(string $text, array $toolCalls = [], ?string $finishReason = null): array
    {
        $parts = [];

        if ($text !== '') {
            $parts[] = ['type' => 'text', 'content' => $text];
        }

        foreach ($toolCalls as $toolCall) {
            if ($toolCall instanceof ToolCall) {
                $parts[] = self::toolCallPart($toolCall);
            }
        }

        $message = ['role' => 'assistant', 'parts' => $parts];

        if ($finishReason !== null) {
            $message['finish_reason'] = $finishReason;
        }

        return [$message];
    }

    /**
     * @return array<string, mixed>
     */
    private static function message(Message $message): array
    {
        if ($message instanceof ToolResultMessage) {
            $parts = [];

            foreach ($message->toolResults as $result) {
                if ($result instanceof ToolResult) {
                    $parts[] = [
                        'type' => 'tool_call_response',
                        'id' => $result->id,
                        'response' => self::stringify($result->result),
                    ];
                }
            }

            return ['role' => 'tool', 'parts' => $parts];
        }

        $parts = [];

        if ($message->content !== null && $message->content !== '') {
            $parts[] = ['type' => 'text', 'content' => $message->content];
        }

        if ($message instanceof AssistantMessage) {
            foreach ($message->toolCalls as $toolCall) {
                if ($toolCall instanceof ToolCall) {
                    $parts[] = self::toolCallPart($toolCall);
                }
            }
        }

        $role = $message->role->value;

        return ['role' => $role === 'tool_result' ? 'tool' : $role, 'parts' => $parts];
    }

    /**
     * @return array<string, mixed>
     */
    private static function toolCallPart(ToolCall $toolCall): array
    {
        return [
            'type' => 'tool_call',
            'id' => $toolCall->id,
            'name' => $toolCall->name,
            'arguments' => $toolCall->arguments,
        ];
    }

    private static function stringify(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            $value instanceof Stringable => (string) $value,
            is_int($value), is_float($value) => (string) $value,
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => '',
            default => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '',
        };
    }
}
