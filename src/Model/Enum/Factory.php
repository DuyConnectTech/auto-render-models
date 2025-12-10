<?php

namespace Connecttech\AutoRenderModels\Model\Enum;

use Connecttech\AutoRenderModels\Meta\Blueprint;
use Connecttech\AutoRenderModels\Meta\SchemaManager;
use Connecttech\AutoRenderModels\Model\Config;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class Factory
{
    protected SchemaManager $schemaManager;
    protected Config $config;
    protected Filesystem $files;

    public function __construct(SchemaManager $schemaManager, Config $config, Filesystem $files)
    {
        $this->schemaManager = $schemaManager;
        $this->config = $config;
        $this->files = $files;
    }

    /**
     * Finds enum columns in the given schema and generates Enum classes.
     *
     * @param string $schemaName
     */
    public function generateEnums($schemaName): void
    {
        if (!$this->config->get(null, 'enums.enabled', false)) {
            return;
        }

        $schema = $this->schemaManager->make($schemaName);
        $enumsToGenerate = []; // [enumClassName => [case1, case2, ...]]

        foreach ($schema->tables() as $blueprint) {
            /** @var Blueprint $blueprint */
            if ($this->shouldNotExclude($blueprint) && $this->shouldTakeOnly($blueprint)) {
                foreach ($blueprint->columns() as $column) {
                    if (Str::startsWith($column->type, 'enum')) {
                        $enumName = Str::studly(Str::singular($blueprint->table())) . Str::studly($column->name);
                        // Extract enum values from "enum('value1','value2')"
                        preg_match("/enum\((.*)\)/", $column->type, $matches);
                        
                        // Use str_getcsv to correctly parse comma-separated quoted strings
                        $values = str_getcsv($matches[1], ',', "'");
                        
                        $enumsToGenerate[$enumName] = $values;
                    }
                }
            }
        }

        foreach ($enumsToGenerate as $enumName => $values) {
            $this->createEnumClass($enumName, $values);
        }
    }

    protected function createEnumClass(string $enumName, array $values): void
    {
        $enumNamespace = $this->config->get(null, 'enums.namespace', 'App\Enums');
        $enumPath = $this->config->get(null, 'enums.path', app_path('Enums'));

        $fullPath = rtrim($enumPath, '/') . '/' . $enumName . '.php';

        if ($this->files->exists($fullPath)) {
            // Don't overwrite existing enums unless forced or specified
            return;
        }

        if (!$this->files->isDirectory($enumPath)) {
            $this->files->makeDirectory($enumPath, 0755, true);
        }

        $cases = '';
        foreach ($values as $value) {
            $caseName = Str::upper(Str::snake($value));
            $cases .= "    case {$caseName} = '{$value}';\n";
        }

        // Load Template
        $template = $this->loadTemplate();

        $content = str_replace(
            ['{{namespace}}', '{{enumName}}', '{{cases}}'],
            [$enumNamespace, $enumName, $cases],
            $template
        );

        $this->files->put($fullPath, $content);
    }

    protected function loadTemplate()
    {
        $path = __DIR__ . '/../../Templates/enum';
        return $this->files->get($path);
    }
    
    /**
     * Kiểm tra bảng hiện tại có nằm trong danh sách "except" không.
     *
     * @param \Connecttech\AutoRenderModels\Meta\Blueprint $blueprint
     *
     * @return bool true nếu KHÔNG bị exclude, false nếu bị loại.
     */
    protected function shouldNotExclude(Blueprint $blueprint)
    {
        foreach ($this->config($blueprint, 'except', []) as $pattern) {
            if (Str::is($pattern, $blueprint->table())) {
                return false;
            }
        }

        return true;
    }

    /**
     * Kiểm tra xem có cấu hình "only" hay không,
     * và nếu có thì bảng hiện tại có match với bất kỳ pattern nào không.
     * Không có cấu hình only => mặc định là lấy tất cả (true).
     *
     * @param \Connecttech\AutoRenderModels\Meta\Blueprint $blueprint
     *
     * @return bool true nếu nên lấy, false nếu không match.
     */
    protected function shouldTakeOnly(Blueprint $blueprint)
    {
        if ($patterns = $this->config($blueprint, 'only', [])) {
            foreach ($patterns as $pattern) {
                if (Str::is($pattern, $blueprint->table())) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }

    /**
     * Truy cập config:
     * @param \Connecttech\AutoRenderModels\Meta\Blueprint|null $blueprint
     * @param string|null $key
     * @param mixed $default
     * @return mixed|\Connecttech\AutoRenderModels\Model\Config
     */
    public function config(?Blueprint $blueprint = null, $key = null, $default = null)
    {
        if (is_null($blueprint)) {
            return $this->config;
        }

        return $this->config->get($blueprint, $key, $default);
    }
}
