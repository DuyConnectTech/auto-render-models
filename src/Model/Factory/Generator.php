<?php

namespace Connecttech\AutoRenderModels\Model\Factory;

use Illuminate\Support\Str;
use Illuminate\Filesystem\Filesystem;
use Connecttech\AutoRenderModels\Model\Model;

class Generator
{
    protected $files;

    public function __construct(Filesystem $files)
    {
        $this->files = $files;
    }

    public function generate(Model $model)
    {
        // Determine path: database/factories/UserFactory.php
        // We assume typical Laravel structure relative to the project root.
        // Since we are in a package, we can't easily guess 'app' path without helpers, 
        // but 'database/factories' is standard.
        
        $factoryPath = base_path('database/factories');
        if (!$this->files->exists($factoryPath)) {
            $this->files->makeDirectory($factoryPath, 0755, true);
        }

        $name = $model->getClassName() . 'Factory';
        $filePath = $factoryPath . DIRECTORY_SEPARATOR . $name . '.php';

        // Do not overwrite existing factories
        if ($this->files->exists($filePath)) {
            return;
        }

        $body = $this->buildBody($model);
        
        // Load template
        $template = file_get_contents(__DIR__ . '/../Templates/factory');
        
        // Fill template
        $content = str_replace(
            ['{{modelNamespace}}', '{{modelClass}}', '{{body}}'],
            [$model->getNamespace(), $model->getClassName(), $body],
            $template
        );

        $this->files->put($filePath, $content);
    }

    protected function buildBody(Model $model)
    {
        $lines = [];
        $blueprint = $model->getBlueprint();

        foreach ($blueprint->columns() as $column) {
            // Skip PK, Timestamps, SoftDeletes
            if ($column->name === $model->getPrimaryKey()) continue;
            if (in_array($column->name, [
                $model->getCreatedAtField(), 
                $model->getUpdatedAtField(), 
                $model->getDeletedAtField()
            ])) continue;

            $faker = $this->guessFaker($column->name, $column->type, $column->size ?? null);
            
            $lines[] = str_repeat(' ', 12) . "'{$column->name}' => {$faker},";
        }

        return implode("\n", $lines);
    }

    protected function guessFaker($name, $type, $size)
    {
        $nameLower = strtolower($name);

        // 1. Guess by Name
        if (Str::contains($nameLower, 'email')) return 'fake()->unique()->safeEmail()';
        if (Str::contains($nameLower, 'password')) return 'static::$password ??= \Illuminate\Support\Facades\Hash::make(\'password\')';
        if (Str::contains($nameLower, 'phone')) return 'fake()->phoneNumber()';
        if ($nameLower === 'name' || $nameLower === 'username' || $nameLower === 'fullname') return 'fake()->name()';
        if ($nameLower === 'first_name') return 'fake()->firstName()';
        if ($nameLower === 'last_name') return 'fake()->lastName()';
        if ($nameLower === 'slug') return 'fake()->slug()';
        if ($nameLower === 'description' || $nameLower === 'content' || $nameLower === 'body') return 'fake()->paragraph()';
        if ($nameLower === 'address') return 'fake()->address()';
        if ($nameLower === 'city') return 'fake()->city()';
        if ($nameLower === 'country') return 'fake()->country()';
        if ($nameLower === 'image' || $nameLower === 'avatar' || $nameLower === 'photo') return 'fake()->imageUrl()';
        if ($nameLower === 'url' || $nameLower === 'website' || $nameLower === 'link') return 'fake()->url()';
        if ($nameLower === 'ip') return 'fake()->ipv4()';
        if ($nameLower === 'mac') return 'fake()->macAddress()';
        if ($nameLower === 'uuid') return 'fake()->uuid()';
        if ($nameLower === 'company') return 'fake()->company()';
        if ($nameLower === 'title') return 'fake()->sentence()';
        if (Str::endsWith($nameLower, '_id')) return 'fake()->randomNumber()'; 

        // 2. Guess by Type
        switch ($type) {
            case 'string':
                if (isset($size) && $size > 255) return 'fake()->text()';
                return 'fake()->word()';
            case 'int':
            case 'integer':
            case 'bigint':
            case 'smallint':
            case 'tinyint':
                return 'fake()->randomNumber()';
            case 'text':
            case 'mediumtext':
            case 'longtext':
                return 'fake()->text()';
            case 'bool':
            case 'boolean':
                return 'fake()->boolean()';
            case 'date':
                return 'fake()->date()';
            case 'datetime':
            case 'timestamp':
                return 'fake()->dateTime()';
            case 'float':
            case 'decimal':
            case 'double':
            case 'real':
            case 'money':
                return 'fake()->randomFloat(2, 0, 10000)';
            case 'json':
            case 'jsonb':
                return '[]';
            case 'uuid':
                return 'fake()->uuid()';
        }

        return 'null';
    }
}