<?php

namespace Connecttech\AutoRenderModels\Console;

use Illuminate\Console\Command;
use Connecttech\AutoRenderModels\Model\Factory\Generator;
use Illuminate\Support\Facades\DB;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class AutoRenderFactoryCommand extends Command
{
    protected $signature = 'auto-render:factory
                            {--c|connection= : The database connection to use}
                            {--t|table= : The specific table to generate factory for}';

    protected $description = 'Generate Eloquent Factories from database schema';

    protected $generator;

    public function __construct(Generator $generator)
    {
        parent::__construct();
        $this->generator = $generator;
    }

    public function handle()
    {
        $connection = $this->option('connection') ?? config('database.default');
        
        // Interactive check
        if (!$this->option('connection') && !$this->option('table')) {
            $connections = array_keys(config('database.connections', []));
            $connection = select(
                label: 'Choose database connection:',
                options: $connections,
                default: $connection
            );
        }

        $schema = DB::connection($connection)->getSchemaBuilder();
        $tables = $schema->getTableListing();
        $targetTable = $this->option('table');

        if ($targetTable) {
             if (!in_array($targetTable, $tables)) {
                $this->error("Table '$targetTable' not found!");
                return;
             }
             $tables = [$targetTable];
        } elseif (!$this->option('connection') && !$this->option('table')) {
            // Interactive select table or all
             $mode = select(
                label: 'Do you want to generate factories for all tables or a specific one?',
                options: ['all' => 'All Tables', 'single' => 'Specific Table'],
                default: 'all'
            );

            if ($mode === 'single') {
                 $targetTable = text(
                    label: 'Enter table name:',
                    required: true,
                    validate: fn ($val) => in_array($val, $tables) ? null : "Table '$val' not found."
                );
                $tables = [$targetTable];
            }
        }

        $bar = $this->output->createProgressBar(count($tables));
        $bar->start();

        foreach ($tables as $table) {
            // Skip migrations and ignored tables
            if ($table === 'migrations' || in_array($table, config('models.except', []))) {
                $bar->advance();
                continue;
            }

            $this->generator->generate($connection, $table);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Factories generated successfully!");
    }
}
