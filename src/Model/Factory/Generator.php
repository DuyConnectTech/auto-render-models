<?php

namespace Connecttech\AutoRenderModels\Model\Factory;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Connecttech\AutoRenderModels\Model\Config;

class Generator
{
    protected $files;
    protected $config;

    public function __construct(Filesystem $files, Config $config)
    {
        $this->files = $files;
        $this->config = $config;
    }

    public function generate($connectionName, $tableName)
    {
        $columns = DB::connection($connectionName)->getSchemaBuilder()->getColumns($tableName);
        $modelName = Str::studly(Str::singular($tableName));
        
        // Resolve Model Namespace
        $modelNamespace = config('models.namespace', 'App\Models');
        $modelClass = "{$modelNamespace}\\{$modelName}";

        $definition = "";

        foreach ($columns as $column) {
            $name = $column['name'];
            
            // Skip primary key (usually 'id') and timestamps
            if ($name === 'id' || in_array($name, ['created_at', 'updated_at', 'deleted_at'])) {
                continue;
            }

            $fakerLine = $this->guessFaker($name, $column);
            $definition .= "            '{$name}' => {$fakerLine},
";
        }

        $factoryNamespace = config('models.factories.namespace', 'Database\Factories');
        $factoryPath = config('models.factories.path', database_path('factories'));
        $className = "{$modelName}Factory";

        // Load Template
        $template = $this->loadTemplate();

        $content = str_replace(
            [
                '{{factoryNamespace}}',
                '{{modelClass}}',
                '{{className}}',
                '{{modelName}}',
                '{{modelVar}}',
                '{{definition}}'
            ],
            [
                $factoryNamespace,
                $modelClass,
                $className,
                $modelName,
                'model',
                $definition
            ],
            $template
        );

        if (!$this->files->isDirectory($factoryPath)) {
            $this->files->makeDirectory($factoryPath, 0755, true);
        }

        $this->files->put("{$factoryPath}/{$className}.php", $content);
    }

    protected function loadTemplate()
    {
        $path = __DIR__ . '/../Templates/factory';
        return $this->files->get($path);
    }

    protected function guessFaker($name, $column)
    {
        $type = Str::lower($column['type_name']);
        
        // 1. Check ENUM types (Integration with our Enum feature)
        if ($type === 'enum' || Str::startsWith($column['type'] ?? '', 'enum')) {
            // Try to find the generated Enum class
            $enumClassName = Str::studly(Str::singular(Str::studly($column['type'] ?? ''))) . Str::studly($name);
            // Wait, getting table name here is hard without passing it.
            // Let's use a simpler heuristic or just randomElement if we can't find class.
            
            // Better approach: Parse values from 'enum(a,b)' string if available
            // Note: $column['type'] usually contains full definition like enum('a','b') in MySQL
            if (isset($column['type']) && preg_match("/enum\((.*)\)/", $column['type'], $matches)) {
                 $values = str_getcsv($matches[1], ',', "'");
                 // Export array to string: ['a', 'b']
                 $arrayStr = "['" . implode("', '", $values) . "']";
                 return "$this->faker->randomElement({$arrayStr})";
            }
        }

        // 2. Guess by Name
        if (Str::contains($name, 'email')) return '$this->faker->unique()->safeEmail()';
        if (Str::contains($name, 'phone')) return '$this->faker->phoneNumber()';
        if ($name === 'password') return 'bcrypt("password")'; // Default password
        if (Str::contains($name, 'name') || Str::contains($name, 'title')) return '$this->faker->name()';
        if (Str::contains($name, 'slug')) return '$this->faker->slug()';
        if (Str::contains($name, 'url')) return '$this->faker->url()';
        if (Str::contains($name, 'address')) return '$this->faker->address()';
        if (Str::contains($name, 'text') || Str::contains($name, 'desc')) return '$this->faker->text()';
        if (Str::endsWith($name, '_id')) {
             // Foreign key guess -> User::factory()
             // Remove _id -> user -> User
             $relatedModel = Str::studly(Str::beforeLast($name, '_id'));
             // We assume related model is in same namespace.
             // Ideally we should import it, but for simplicity let's use FQN or assume simple usage.
             // return "{$relatedModel}::factory()"; 
             // To be safe and avoid infinite loops or missing classes, let's just use random digit or create if exists
             return "$this->faker->randomNumber()"; // Placeholder, can be improved to use Factory
        }

        // 3. Guess by Type
        return match ($type) {
            'integer', 'int', 'bigint', 'smallint' => '$this->faker->randomNumber()',
            'decimal', 'float', 'double' => '$this->faker->randomFloat(2, 0, 1000)',
            'boolean', 'bool', 'tinyint' => '$this->faker->boolean()',
            'date' => '$this->faker->date()',
            'datetime', 'timestamp' => '$this->faker->dateTime()',
            'text', 'longtext' => '$this->faker->text()',
            'json' => '[]',
            default => '$this->faker->word()',
        };
    }
}
