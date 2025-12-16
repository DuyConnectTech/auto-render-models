<?php

namespace Connecttech\AutoRenderModels\Console;

use Illuminate\Console\Command;
use Connecttech\AutoRenderModels\Model\Factory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Schema;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\search;

class AutoRenderModelsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'auto-render:models
                            {--s|schema= : The name of the MySQL database}
                            {--c|connection= : The name of the connection}
                            {--t|table= : The name of the table}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Parse connection schema into models';

    /**
     * @var  \Connecttech\AutoRenderModels\Model\Factory
     */
    protected $models;

    /**
     * @var \Illuminate\Contracts\Config\Repository
     */
    protected $config;

    /**
     * Create a new command instance.
     *
     * @param  \Connecttech\AutoRenderModels\Model\Factory $models
     * @param \Illuminate\Contracts\Config\Repository $config
     */
    public function __construct(Factory $models, Repository $config)
    {
        parent::__construct();

        $this->models = $models;
        $this->config = $config;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Interactive Mode if no options provided
        if (!$this->option('table') && !$this->option('schema') && !$this->option('connection')) {
            $this->runInteractive();
            return;
        }

        $connection = $this->getConnection();
        $schema = $this->getSchema($connection);
        $table = $this->getTable();

        // Check whether we just need to generate one table
        if ($table) {
            $this->models->on($connection)->create($schema, $table);
            $this->info("Check out your models for $table");
        }

        // Otherwise map the whole database
        else {
            $this->models->on($connection)->map($schema);
            $this->info("Check out your models for $schema");
        }
    }

    protected function runInteractive()
    {
        $connections = array_keys($this->config->get('database.connections', []));

        if (empty($connections)) {
            $this->error('No database connections found in config.');
            return;
        }

        // 1. Select Connection
        $connectionName = select(
            label: 'Which database connection do you want to use?',
            options: $connections,
            default: $this->config->get('database.default')
        );

        $this->input->setOption('connection', $connectionName);
        $connection = $this->getConnection();
        $schema = $this->getSchema($connection);

        // 2. Select Mode
        $mode = select(
            label: 'What do you want to render?',
            options: [
                'all' => 'All Tables in Database',
                'select' => 'Select Specific Tables (Interactive)',
                'input' => 'Enter Table Names (Manual)',
            ],
            default: 'all'
        );

        if ($mode === 'select') {
            try {
                // Get all tables from the database
                $tables = Schema::connection($connectionName)->getTableListing();
                
                if (empty($tables)) {
                    $this->error("No tables found in database '$schema'.");
                    return;
                }

                $selectedTables = multiselect(
                    label: 'Select tables to render:',
                    options: $tables,
                    required: true,
                    scroll: 15
                );

                $this->info('Generating models for selected tables...');
                
                foreach ($selectedTables as $table) {
                    // Fix for SQLite/Postgres returning "schema.table" format
                    // We only want the table name part
                    if (str_contains($table, '.')) {
                        $parts = explode('.', $table);
                        $table = end($parts);
                    }

                    $this->models->on($connection)->create($schema, $table);
                    $this->line("<info>✓</info> Rendered: $table");
                }
                
                $this->info('Done!');

            } catch (\Exception $e) {
                $this->error("Error fetching tables: " . $e->getMessage());
            }

        } elseif ($mode === 'input') {
            // 3. Enter Table Names
            $input = text(
                label: 'Enter table names (comma separated):',
                placeholder: 'users, posts, comments',
                required: true,
                validate: fn (string $value) => match (true) {
                    strlen(trim($value)) < 1 => 'Please enter at least one table name.',
                    default => null,
                }
            );

            $tables = array_map('trim', explode(',', $input));
            
            $this->info('Generating models...');

            foreach ($tables as $table) {
                if (empty($table)) continue;
                $this->models->on($connection)->create($schema, $table);
                $this->line("<info>✓</info> Rendered: $table");
            }

            $this->info('Done!');

        } else {
            // Mode: All
            if (confirm("This will render models for ALL tables in '$schema'. Continue?")) {
                $this->models->on($connection)->map($schema);
                $this->info("Successfully rendered all models for schema: $schema");
            } else {
                $this->warn('Operation cancelled.');
            }
        }
    }

    /**
     * @return string
     */
    protected function getConnection()
    {
        return $this->option('connection') ?: $this->config->get('database.default');
    }

    /**
     * @param $connection
     *
     * @return string
     */
    protected function getSchema($connection)
    {
        return $this->option('schema') ?: $this->config->get("database.connections.$connection.database");
    }

    /**
     * @return string
     */
    protected function getTable()
    {
        return $this->option('table');
    }
}
