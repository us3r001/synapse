<?php

declare(strict_types=1);

namespace UseTheFork\Synapse\Agents\Rat;

use UseTheFork\Synapse\Agent;
use UseTheFork\Synapse\Traits\Agent\ValidatesOutputSchema;
use UseTheFork\Synapse\ValueObject\SchemaRule;

class ReviseAnswerAgent extends Agent
{
    use ValidatesOutputSchema;

    protected string $promptView = 'synapse::Prompts.Rat.ReviseAnswerPrompt';

    public function resolveOutputSchema(): array
    {
        return [
            SchemaRule::make([
                'name' => 'answer',
                'rules' => 'required|string',
                'description' => 'Your answer.',
            ]),
        ];
    }
}
