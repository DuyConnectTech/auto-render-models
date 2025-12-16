<?php

namespace Connecttech\AutoRenderModels\Meta\Postgres;

use Illuminate\Support\Str;
use Illuminate\Support\Fluent;
use Illuminate\Support\Arr;

/**
 * Class Column
 *
 * Triển khai \Connecttech\AutoRenderModels\Meta\Column cho PostgreSQL.
 */
class Column implements \Connecttech\AutoRenderModels\Meta\Column
{
    /**
     * Metadata thô từ information_schema.columns
     */
    protected $metadata;

    protected $metas = [
        'type',
        'name',
        'autoincrement',
        'nullable',
        'default',
        'comment',
    ];

    public static $mappings = [
        'string'   => ['character varying', 'varchar', 'character', 'char', 'text', 'citext', 'uuid', 'json', 'jsonb', 'xml', 'inet', 'cidr', 'macaddr'],
        'int'      => ['integer', 'int', 'int4', 'smallint', 'int2', 'bigint', 'int8', 'serial', 'bigserial', 'serial4', 'serial8'],
        'float'    => ['numeric', 'decimal', 'double precision', 'float8', 'real', 'float4', 'money'],
        'bool'     => ['boolean', 'bool'],
        'datetime' => ['timestamp', 'timestamptz', 'date', 'time', 'timetz', 'interval'],
    ];

    public function __construct(array $metadata = [])
    {
        $this->metadata = $metadata;
    }

    public function normalize()
    {
        $attributes = new Fluent();

        foreach ($this->metas as $meta) {
            $this->{'parse' . ucfirst($meta)}($attributes);
        }

        return $attributes;
    }

    protected function parseType(Fluent $attributes)
    {
        $dataType = strtolower($this->get('data_type'));
        $attributes['type'] = 'string'; // Fallback

        foreach (static::$mappings as $phpType => $dbTypes) {
            // Postgres type có thể chứa ' without time zone' v.v... nên check contains hoặc exact
            foreach ($dbTypes as $dbType) {
                if (Str::startsWith($dataType, $dbType)) {
                    $attributes['type'] = $phpType;
                    break 2;
                }
            }
        }

        // Parse precision/length
        $maxLength = $this->get('character_maximum_length');
        if ($maxLength) {
            $attributes['size'] = $maxLength;
        }

        $numericPrecision = $this->get('numeric_precision');
        $numericScale = $this->get('numeric_scale');
        
        if ($numericPrecision) {
            $attributes['size'] = $numericPrecision;
            if ($numericScale) {
                $attributes['scale'] = $numericScale;
            }
        }
    }

    protected function parseName(Fluent $attributes)
    {
        $attributes['name'] = $this->get('column_name');
    }

    protected function parseAutoincrement(Fluent $attributes)
    {
        $default = $this->get('column_default');
        // Postgres dùng nextval(...) cho serial
        if (Str::contains((string)$default, 'nextval')) {
            $attributes['autoincrement'] = true;
        }
    }

    protected function parseNullable(Fluent $attributes)
    {
        $attributes['nullable'] = $this->get('is_nullable') === 'YES';
    }

    protected function parseDefault(Fluent $attributes)
    {
        $default = $this->get('column_default');
        
        // Clean up Postgres default format (e.g., "'value'::text")
        if ($default !== null) {
            if (Str::contains((string)$default, '::')) {
                $default = substr($default, 0, strpos($default, '::'));
                $default = trim($default, "'");
            }
            // Ignore nextval defaults as they are handled by autoincrement
            if (Str::contains((string)$default, 'nextval')) {
                $default = null;
            }
        }
        
        $attributes['default'] = $default;
    }

    protected function parseComment(Fluent $attributes)
    {
        // Comment trong Postgres thường lấy từ bảng khác, 
        // nhưng nếu user join sẵn query thì key này sẽ có.
        // Tạm thời để null nếu query ở Schema không join pg_description.
        $attributes['comment'] = null; 
    }

    protected function get($key, $default = null)
    {
        return Arr::get($this->metadata, $key, $default);
    }
}
