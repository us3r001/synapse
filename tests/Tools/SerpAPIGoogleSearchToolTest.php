<?php

declare(strict_types=1);

    use Saloon\Http\Faking\MockClient;
    use Saloon\Http\Faking\MockResponse;
    use Saloon\Http\PendingRequest;
    use UseTheFork\Synapse\Agent;
    use UseTheFork\Synapse\Contracts\Agent\HasOutputSchema;
    use UseTheFork\Synapse\Contracts\Integration;
    use UseTheFork\Synapse\Contracts\Tool;
    use UseTheFork\Synapse\Integrations\Connectors\OpenAI\Requests\ChatRequest;
    use UseTheFork\Synapse\Integrations\OpenAIIntegration;
    use UseTheFork\Synapse\Services\SerpApi\Requests\SerpApiSearchRequest;
    use UseTheFork\Synapse\Tests\Fixtures\OpenAi\OpenAiFixture;
    use UseTheFork\Synapse\Tools\BaseTool;
    use UseTheFork\Synapse\Tools\Search\SerpAPIGoogleSearchTool;
    use UseTheFork\Synapse\Traits\Agent\ValidatesOutputSchema;
    use UseTheFork\Synapse\ValueObject\SchemaRule;

    test('Serp API Tool', function (): void {

        class SerpAPIGoogleSearchToolTestAgent extends Agent implements HasOutputSchema
        {
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

            protected function resolveTools(): array
            {
                return [new SerpAPIGoogleSearchTool];
            }
        }

        MockClient::global([
                               ChatRequest::class => function (PendingRequest $pendingRequest): OpenAiFixture {
                                   $hash = md5(json_encode($pendingRequest->body()->get('messages')));

                                   return new OpenAiFixture("Tools/SerpAPIGoogleSearchTool-{$hash}");
                               },
                               SerpApiSearchRequest::class => MockResponse::fixture('Tools/SerpAPIGoogleSearchTool-Tool'),
                           ]);

        $agent = new SerpAPIGoogleSearchToolTestAgent;
        $message = $agent->handle(['input' => 'search google for the current president of the united states.']);

        $agentResponseArray = $message->toArray();
        expect($agentResponseArray['content'])->toBeArray()
                                              ->and($agentResponseArray['content'])->toHaveKey('answer')
                                              ->and($agentResponseArray['content']['answer'])->toBe('Joe Biden');

    });

    test('Architecture', function (): void {

        expect(SerpAPIGoogleSearchTool::class)
            ->toExtend(BaseTool::class)
            ->toImplement(Tool::class);

    });
