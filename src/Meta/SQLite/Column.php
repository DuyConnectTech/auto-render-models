<?php

namespace Connecttech\AutoRenderModels\Meta\SQLite;

use Illuminate\Support\Str;
use Illuminate\Support\Fluent;
use Illuminate\Support\Arr;

/**
 * Class Column
 *
 * Triển khai \Connecttech\AutoRenderModels\Meta\Column cho SQLite.
 */
class Column implements \Connecttech\AutoRenderModels\Meta\Column
{
    /**
     * Metadata thô của cột lấy từ PRAGMA table_info.
     *
     * Keys:
     * - cid: ID cột
     * - name: Tên cột
     * - type: Kiểu dữ liệu
     * - notnull: 1 nếu not null, 0 nếu null
     * - dflt_value: Giá trị mặc định
     * - pk: 1 nếu là primary key
     *
     * @var array
     */
    protected $metadata;

    /**
     * Danh sách meta cần parse.
     */
    protected $metas = [
        'type',
        'name',
        'autoincrement',
        'nullable',
        'default',
        'comment', // SQLite ít hỗ trợ comment cột chuẩn như MySQL
    ];

    /**
     * Mapping SQLite types sang PHP types.
     * SQLite dùng Dynamic Typing, nhưng thường affinity sẽ rơi vào các nhóm này.
     */
    public static $mappings = [
        'string'   => ['varchar', 'text', 'clob', 'char', 'character', 'varying character', 'nchar', 'native character', 'nvarchar'],
        'int'      => ['integer', 'int', 'tinyint', 'smallint', 'mediumint', 'bigint', 'unsigned big int', 'int2', 'int8'],
        'float'    => ['real', 'double', 'double precision', 'float', 'numeric', 'decimal', 'boolean', 'date', 'datetime'], // Date/Time trong SQLite thường là Text hoặc Numeric
        'bool'     => ['boolean', 'bool'],
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
        $type = strtolower($this->get('type', 'text'));
        
        // Loại bỏ phần length vd: VARCHAR(255) -> varchar
        $dataType = preg_replace('/\s*\(.*\)/', '', $type);
        
        $attributes['type'] = 'string'; // Default fallback

        foreach (static::$mappings as $phpType => $database) {
            foreach ($database as $dbType) {
                if (Str::contains($dataType, $dbType)) {
                    $attributes['type'] = $phpType;
                    break 2;
                }
            }
        }

        // Xử lý Boolean đặc biệt (SQLite thường lưu 0/1 hoặc TINYINT)
        if ($dataType === 'boolean' || $dataType === 'bool') {
            $attributes['type'] = 'bool';
        }

        // Parse precision nếu có
        if (preg_match('/\((.+)\)/', $type, $matches)) {
            $this->parsePrecision($dataType, $matches[1], $attributes);
        }
    }

    protected function parsePrecision($databaseType, $precision, Fluent $attributes)
    {
        $precision = explode(',', $precision);
        $attributes['size'] = (int) current($precision);
        
        if ($scale = next($precision)) {
            $attributes['scale'] = (int) $scale;
        }
    }

    protected function parseName(Fluent $attributes)
    {
        $attributes['name'] = $this->get('name');
    }

    protected function parseAutoincrement(Fluent $attributes)
    {
        // Trong SQLite, INTEGER PRIMARY KEY thường là autoincrement.
        // Logic chính xác cần check bảng sqlite_sequence hoặc DDL, 
        // nhưng ở mức Column metadata, ta tạm đoán dựa trên PK + Integer.
        $isPk = $this->get('pk') > 0;
        $type = strtolower($this->get('type'));
        
        if ($isPk && Str::contains($type, 'int')) {
             $attributes['autoincrement'] = true;
        }
    }

    protected function parseNullable(Fluent $attributes)
    {
        // notnull: 1 => không được null => nullable = false
        // notnull: 0 => được null => nullable = true
        $attributes['nullable'] = !$this->get('notnull');
    }

    protected function parseDefault(Fluent $attributes)
    {
        $default = $this->get('dflt_value');
        if ($default !== null) {
            // SQLite trả về string có thể chứa quote 'value', cần clean
            $default = trim($default, "'");
        }
        $attributes['default'] = $default;
    }

    protected function parseComment(Fluent $attributes)
    {
        // SQLite không trả về comment trong table_info
        $attributes['comment'] = null;
    }

    protected function get($key, $default = null)
    {
        return Arr::get($this->metadata, $key, $default);
    }
}
