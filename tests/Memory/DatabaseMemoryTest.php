<?php

declare(strict_types=1);

    use Illuminate\Foundation\Testing\RefreshDatabase;
    use Saloon\Http\Faking\MockClient;
    use Saloon\Http\Faking\MockResponse;
    use Saloon\Http\PendingRequest;
    use UseTheFork\Synapse\Agent;
    use UseTheFork\Synapse\Contracts\Agent\HasMemory;
    use UseTheFork\Synapse\Contracts\Integration;
    use UseTheFork\Synapse\Contracts\Memory;
    use UseTheFork\Synapse\Integrations\Connectors\OpenAI\Requests\ChatRequest;
    use UseTheFork\Synapse\Integrations\OpenAIIntegration;
    use UseTheFork\Synapse\Memory\DatabaseMemory;
    use UseTheFork\Synapse\Traits\Agent\ManagesMemory;
    use UseTheFork\Synapse\Traits\Agent\ValidatesOutputSchema;
    use UseTheFork\Synapse\ValueObject\SchemaRule;

    uses(RefreshDatabase::class);

it('Database Memory', function (): void {

    class DatabaseMemoryAgent extends Agent implements HasMemory
    {
        use ManagesMemory;
        use ValidatesOutputSchema;

        protected string $promptView = 'synapse::Prompts.SimplePrompt';

        public function resolveIntegration(): Integration
        {
            return new OpenAIIntegration;
        }

        public function resolveOutputSchema(): array
        {
            return [
                SchemaRule::make([
                    'name' => 'answer',
                    'rules' => 'required|string',
                    'description' => 'your final answer to the query.',
                ]),
            ];
        }

        public function resolveMemory(): Memory
        {
            return new DatabaseMemory;
        }
    }

    MockClient::global([
        ChatRequest::class => function (PendingRequest $pendingRequest): \Saloon\Http\Faking\Fixture {
            $hash = md5(json_encode($pendingRequest->body()->get('messages')));

            return MockResponse::fixture("Memory/DatabaseMemory-{$hash}");
        },
    ]);

    $this->assertDatabaseCount('synapse_agent_memories', 0);

    $agent = new DatabaseMemoryAgent;
    $message = $agent->handle(['input' => 'hello this a test']);
    $agentResponseArray = $message->toArray();

    expect($agentResponseArray['content'])->toBeArray()
        ->and($agentResponseArray['content'])->toHaveKey('answer')
        ->and($agentResponseArray['content']['answer'])->toBe('Hello! How can I assist you today?');

    $followup = $agent->handle(['input' => 'what did I just say? But Backwards.']);
    $followupResponseArray = $followup->toArray();
    expect($followupResponseArray['content'])->toBeArray()
        ->and($followupResponseArray['content'])->toHaveKey('answer')
        ->and($followupResponseArray['content']['answer'])->toBe('sdrawkcaB .yas tsuj I did tahw');

    $this->assertDatabaseCount('synapse_agent_memories', 1);
    $this->assertDatabaseCount('synapse_messages', 2);

});
