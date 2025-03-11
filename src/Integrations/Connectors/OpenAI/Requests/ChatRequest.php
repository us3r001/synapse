<?php

declare(strict_types=1);

namespace UseTheFork\Synapse\Integrations\Connectors\OpenAI\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;
use UseTheFork\Synapse\Constants\Role;
use UseTheFork\Synapse\ValueObject\Message;

class ChatRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        public readonly array $prompt,
        public readonly array $tools,
        public readonly array $extraAgentArgs = []
    ) {}

    public function createDtoFromResponse(Response $response): Message
    {
        $data = $response->array();
        $message = $data['choices'][0]['message'] ?? [];
        $message['finish_reason'] = $data['choices'][0]['finish_reason'] ?? '';
        if (isset($message['tool_calls'])) {

            $message['tool_call_id'] = $message['tool_calls'][0]['id'];
            $message['tool_name'] = $message['tool_calls'][0]['function']['name'];
            $message['tool_arguments'] = $message['tool_calls'][0]['function']['arguments'];
            unset($message['tool_calls']);

            // Open AI sends a tool call via assistant role. We change it to tool here to make processing easier.
            $message['role'] = Role::TOOL;
        }

        return Message::make($message);
    }

    public function defaultBody(): array
    {
        $model = config('synapse.integrations.openai.chat_model');

        $payload = [
            'model' => $model,
            'messages' => $this->formatMessages(),
        ];

        $toolCalls = [];
        if ($this->tools !== []) {
            foreach ($this->tools as $tool) {
                $payload['tools'][] = $tool['definition'];
            }
            $toolCalls = [
                'parallel_tool_calls' => false,
            ];
        }

        return [
            ...$payload,
            ...$this->extraAgentArgs,
            // Always set parallel_tool_calls to false. True is more headache than its worth.
            ...$toolCalls,
        ];
    }

    private function formatMessages(): array
    {
        $payload = collect();
        foreach ($this->prompt as $message) {
            switch ($message->role()) {
                case Role::TOOL:
                    $toolPayload = $this->formatToolMessage($message);
                    $payload->push(...$toolPayload);
                    break;
                case Role::IMAGE_URL:
                    $toolPayload = $this->formatImageMessage($message);
                    $payload->push(...$toolPayload);
                    break;
                default:
                    $payload->push([
                        'role' => $message->role(),
                        'content' => $message->content(),
                    ]);
                    break;
            }
        }

        return $payload->values()->toArray();
    }

    private function formatToolMessage(Message $message): array
    {
        $message = $message->toArray();

        $payload = [];
        $payload[] = [
            'role' => 'assistant',
            'tool_calls' => [
                [
                    'id' => $message['tool_call_id'],
                    'type' => 'function',
                    'function' => [
                        'name' => $message['tool_name'],
                        'arguments' => $message['tool_arguments'],
                    ],
                ],
            ],
        ];
        $payload[] = [
            'role' => 'tool',
            'tool_call_id' => $message['tool_call_id'],
            'content' => $message['tool_content'],
        ];

        return $payload;
    }

    private function formatImageMessage(Message $message): array
    {
        $message = $message->toArray();

        $payload = [];
        $payload[] = [
            'role' => 'user',
            'content' => [
                [
                    'type' => 'text',
                    'text' => $message['content'],
                ],
                [
                    'type' => 'image_url',
                    'image_url' => [...$message['image']],
                ],
            ],
        ];

        return $payload;
    }

    public function resolveEndpoint(): string
    {
        return '/chat/completions';
    }
}
