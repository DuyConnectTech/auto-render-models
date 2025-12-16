<?php

namespace Connecttech\AutoRenderModels\Console;

use Illuminate\Console\Command;
use Connecttech\AutoRenderModels\Model\Factory\Generator;
use Connecttech\AutoRenderModels\Model\Factory as ModelFactory;
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
    protected $modelFactory;

    public function __construct(Generator $generator, ModelFactory $modelFactory)
    {
        parent::__construct();
        $this->generator = $generator;
        $this->modelFactory = $modelFactory;
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

        $schemaBuilder = DB::connection($connection)->getSchemaBuilder();
        $tables = $schemaBuilder->getTableListing();
        $databaseName = DB::connection($connection)->getDatabaseName();

        $targetTable = $this->option('table');

        if ($targetTable) {
             // Basic check, might fail with prefix
             $found = false;
             foreach ($tables as $t) {
                 if ($t === $targetTable || str_ends_with($t, '.' . $targetTable)) {
                     $found = true;
                     break;
                 }
             }
             
             if (!$found) {
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
                    required: true
                    // validate removed for simplicity with prefixes
                );
                $tables = [$targetTable];
            }
        }

        $bar = $this->output->createProgressBar(count($tables));
        $bar->start();

        // Initialize factory connection
        $this->modelFactory->on($connection);

        foreach ($tables as $table) {
            // Fix for SQLite/Postgres returning "schema.table" format
            if (str_contains($table, '.')) {
                $parts = explode('.', $table);
                $table = end($parts);
            }

            // Skip migrations and ignored tables
            if ($table === 'migrations' || in_array($table, config('models.except', []))) {
                $bar->advance();
                continue;
            }

            try {
                // Create Model instance (no relations needed for factory generation)
                $model = $this->modelFactory->makeModel($databaseName, $table, false);
                $this->generator->generate($model);
            } catch (\Exception $e) {
                $this->newLine();
                $this->error("Error generating factory for table '$table': " . $e->getMessage());
            }
            
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Factories generated successfully!");
    }
}
