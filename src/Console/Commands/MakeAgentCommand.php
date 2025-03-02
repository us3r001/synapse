<?php

namespace UseTheFork\Synapse\Console\Commands;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputOption;
use UseTheFork\Synapse\Contracts\Agent\HasMemory;
use UseTheFork\Synapse\Contracts\Agent\HasOutputSchema;
use UseTheFork\Synapse\Contracts\Memory;
use UseTheFork\Synapse\Memory\CollectionMemory;
use UseTheFork\Synapse\Traits\Agent\ManagesMemory;
use UseTheFork\Synapse\Traits\Agent\ValidatesOutputSchema;
use UseTheFork\Synapse\ValueObject\SchemaRule;

class MakeAgentCommand extends GeneratorCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'synapse:make:agent {name} {--m|memory} {--o|output-schema}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * @var array<int, class-string>
     */
    private array $useStatements = [];

    /**
     * @var array<int, string>
     */
    private array $implements = [];

    /**
     * @var array<int, string>
     */
    private array $traits = [];
    private string $promptName;

    /**
     * Execute the console command.
     */
    public function handle(): bool|null
    {
        $withMemory = (bool)$this->option('memory');
        $withOutputSchema = (bool)$this->option('output-schema');

        $rawName = $this->argument('name');

        if (!is_string($rawName)) {
            $this->error('Name must be a string');
            return false;
        }

        $name = Str::endsWith($rawName, 'Agent') ? $rawName : $rawName . 'Agent';
        $this->promptName = Str::studly($rawName . 'Prompt');

        $name = Str::studly($name);


        $fqcn = $this->getDefaultNamespace($this->rootNamespace()) . '\\' . $name;

        if (class_exists($fqcn)) {
            $this->error('Class already exists');
            return false;
        }
        $path = $this->getPath($fqcn);

        $this->makeDirectory($path);

        $this->files->put($path, $this->createAgent($name, $withMemory, $withOutputSchema));
        $this->info('Agent created successfully');
        $this->info($path . ' added');

        $promptPath = $this->getPromptPath();
        $this->makeDirectory($promptPath);

        $this->files->put($promptPath, $this->createPrompt($withMemory, $withOutputSchema));
        $this->info('Prompt created successfully');
        $this->info($promptPath . ' added');

        return true;
    }

    /**
     * Get the default namespace for the class.
     *
     * @param string $rootNamespace
     * @return string
     */
    protected function getDefaultNamespace($rootNamespace): string
    {
        return is_dir(app_path('Agents')) ? $rootNamespace . '\\Agents' : $rootNamespace;
    }

    protected function createAgent(string $name, bool $withMemory, bool $withOutputSchema): string
    {
        $stub = $this->files->get($this->getStub());

        $stub = $this
            ->replaceNamespace($stub, $this->rootNamespace() . 'Agents\\' . $name)
            ->replaceClass($stub, $name);

        $stub = $this->addMemory($stub, $withMemory);
        $stub = $this->addOutputSchema($stub, $withOutputSchema);

        $stub = str_replace(
            '{{ promptView }}',
            'Prompts.' . $this->promptName,
            $stub
        );


        $stub = $this->addImportsAndTraits($stub);
        return $this->addImplements($stub);
    }

    protected function getStub(): string
    {
        return __DIR__ . '/../stubs/base.agent.stub';
    }

    protected function addMemory(string $stub, bool $withMemory): string
    {
        if (!$withMemory) {
            return str_replace('{{ resolveMemory }}', '', $stub);
        }

        $this->useStatements[] = HasMemory::class;
        $this->useStatements[] = ManagesMemory::class;
        $this->useStatements[] = CollectionMemory::class;
        $this->useStatements[] = Memory::class;

        $this->traits[] = 'ManagesMemory';

        $this->implements[] = 'HasMemory';

        $resolveMemory = <<<PHP
        public function resolveMemory(): Memory
            {
                return new CollectionMemory();
            }
        PHP;


        return str_replace('{{ resolveMemory }}', $resolveMemory, $stub);
    }

    private function addOutputSchema(string $stub, bool $withOutputSchema): string
    {
        if (!$withOutputSchema) {
            return str_replace('{{ resolveOutputSchema }}', '', $stub);
        }

        $this->useStatements[] = HasOutputSchema::class;
        $this->useStatements[] = ValidatesOutputSchema::class;
        $this->useStatements[] = SchemaRule::class;

        $this->traits[] = 'ValidatesOutputSchema';

        $this->implements[] = 'HasOutputSchema';

        $resolveOutputSchema = <<<PHP
        public function resolveOutputSchema(): array
            {
                return [
                    SchemaRule::make([
                        'name' => 'answer',
                        'rules' => 'required|string',
                        'description' => 'The answer',
                    ]),
                ];
            }
        PHP;

        return str_replace('{{ resolveOutputSchema }}', $resolveOutputSchema, $stub);
    }

    private function addImportsAndTraits(string $stub): string
    {
        sort($this->traits);
        sort($this->implements);
        $useStatements = array_map(function ($useStatement) {
            return "use $useStatement;";
        }, $this->useStatements);

        $traits = array_map(function ($trait) {
            return "use $trait;";
        }, $this->traits);

        $stub = str_replace('{{ useStatements }}', implode("\n", $useStatements), $stub);
        return str_replace('{{ traits }}', implode("\n", $traits), $stub);
    }

    private function addImplements(string $stub): string
    {
        return str_replace(
            '{{ implements }}',
            !empty($this->implements) ? 'implements ' . implode(', ', $this->implements) : '',
            $stub
        );

    }

    private function getPromptPath(): string
    {
        return $this->viewPath() . '/Prompts/' . $this->promptName . '.blade.php';
    }

    private function createPrompt(bool $withMemory, bool $withOutputSchema): string
    {
        $stub = $this->files->get($this->getPromptStub());

        $stub = str_replace(
            '{{ hasMemory }}',
            $withMemory ? "@include('synapse::Parts.MemoryAsMessages')" : '',
            $stub
        );

        return str_replace(
            '{{ outputSchema }}',
            $withOutputSchema ? "@include('synapse::Parts.OutputSchema')" : '',
            $stub
        );
    }

    private function getPromptStub(): string
    {
        return __DIR__ . '/../stubs/base.prompt.stub';
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    protected function getOptions(): array
    {
        return [
            ['memory', 'm', InputOption::VALUE_NONE, 'Create an agent with memory'],
            ['output-schema', 'o', InputOption::VALUE_NONE, 'Create an agent with output schema'],
        ];
    }
}
