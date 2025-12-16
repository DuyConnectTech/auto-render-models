<?php

namespace Connecttech\AutoRenderModels\Meta\SQLite;

use Illuminate\Support\Arr;
use Illuminate\Support\Fluent;
use Illuminate\Database\Connection;
use Connecttech\AutoRenderModels\Meta\Blueprint;
use Connecttech\AutoRenderModels\Meta\Schema as SchemaContract;

class Schema implements SchemaContract
{
    protected $schema;
    protected $connection;
    protected $loaded = false;
    protected $tables = [];

    public function __construct($schema, $connection)
    {
        $this->schema = $schema;
        $this->connection = $connection;
        $this->load();
    }

    public function manager()
    {
        return $this->connection->getDoctrineSchemaManager();
    }

    protected function load()
    {
        $tables = $this->fetchTables();
        foreach ($tables as $table) {
            $this->loadTable($table, false);
        }

        // SQLite views cũng nằm trong sqlite_master với type='view'
        $views = $this->fetchViews();
        foreach ($views as $view) {
            $this->loadTable($view, true);
        }

        $this->loaded = true;
    }

    protected function fetchTables()
    {
        // Lấy danh sách bảng, loại bỏ bảng hệ thống sqlite_
        $results = $this->connection->select(
            "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
        );

        return array_map(fn ($row) => $row->name, $results);
    }

    protected function fetchViews()
    {
        $results = $this->connection->select(
            "SELECT name FROM sqlite_master WHERE type='view' ORDER BY name"
        );

        return array_map(fn ($row) => $row->name, $results);
    }

    protected function loadTable($table, $isView = false)
    {
        $blueprint = new Blueprint($this->connection->getName(), $this->schema, $table, $isView);

        $this->fillColumns($blueprint);
        $this->fillConstraints($blueprint);

        $this->tables[$table] = $blueprint;
    }

    protected function fillColumns(Blueprint $blueprint)
    {
        // Sử dụng PRAGMA table_info
        $columns = $this->connection->select("PRAGMA table_info(" . $this->wrap($blueprint->table()) . ")");

        foreach ($columns as $column) {
            $blueprint->withColumn(
                $this->parseColumn((array) $column)
            );
        }
    }

    protected function parseColumn($metadata)
    {
        return (new Column($metadata))->normalize();
    }

    protected function fillConstraints(Blueprint $blueprint)
    {
        $this->fillPrimaryKey($blueprint);
        $this->fillIndexes($blueprint);
        $this->fillRelations($blueprint);
    }

    protected function fillPrimaryKey(Blueprint $blueprint)
    {
        // PRAGMA table_info đã có cột 'pk'. Cần lọc ra.
        // pk > 0 là part của primary key.
        $columns = $this->connection->select("PRAGMA table_info(" . $this->wrap($blueprint->table()) . ")");
        
        $pkColumns = [];
        foreach ($columns as $col) {
            if ($col->pk > 0) {
                $pkColumns[$col->pk] = $col->name; // Key theo thứ tự pk
            }
        }
        
        if (!empty($pkColumns)) {
            ksort($pkColumns);
            $key = [
                'name'    => 'primary',
                'index'   => '', // SQLite PK không có tên index rõ ràng như MySQL
                'columns' => array_values($pkColumns),
            ];
            $blueprint->withPrimaryKey(new Fluent($key));
        }
    }

    protected function fillIndexes(Blueprint $blueprint)
    {
        $indexes = $this->connection->select("PRAGMA index_list(" . $this->wrap($blueprint->table()) . ")");

        foreach ($indexes as $idx) {
            // Bỏ qua các index tự động sinh bởi Unique/PK constraints nếu cần,
            // nhưng thường ta muốn lấy hết để biết unique.
            // origin: 'c' (create index), 'u' (unique constraint), 'pk' (primary key)
            if (isset($idx->origin) && $idx->origin === 'pk') {
                continue; 
            }

            $info = $this->connection->select("PRAGMA index_info(" . $this->wrap($idx->name) . ")");
            $columns = array_map(fn($item) => $item->name, $info);

            $indexData = [
                'name'    => $idx->unique ? 'unique' : 'index',
                'columns' => $columns,
                'index'   => $idx->name,
            ];

            $blueprint->withIndex(new Fluent($indexData));
        }
    }

    protected function fillRelations(Blueprint $blueprint)
    {
        $fks = $this->connection->select("PRAGMA foreign_key_list(" . $this->wrap($blueprint->table()) . ")");
        
        // PRAGMA foreign_key_list trả về 1 dòng cho mỗi cột trong composite FK.
        // Cần group lại theo 'id'.
        $grouped = [];
        foreach ($fks as $fk) {
            $id = $fk->id;
            if (!isset($grouped[$id])) {
                $grouped[$id] = [
                    'table' => $fk->table,
                    'from' => [],
                    'to' => []
                ];
            }
            $grouped[$id]['from'][$fk->seq] = $fk->from;
            $grouped[$id]['to'][$fk->seq] = $fk->to;
        }

        foreach ($grouped as $relation) {
            ksort($relation['from']);
            ksort($relation['to']);

            $setup = [
                'name'       => 'foreign',
                'index'      => '',
                'columns'    => array_values($relation['from']),
                'references' => array_values($relation['to']),
                'on'         => [
                    'database' => $this->schema, // SQLite FK luôn trỏ trong cùng file DB
                    'table'    => $relation['table']
                ],
            ];

            $blueprint->withRelation(new Fluent($setup));
        }
    }

    protected function wrap($table)
    {
        return '"' . str_replace('"', '""', $table) . '"';
    }

    public static function schemas(Connection $connection)
    {
        // SQLite thường chỉ là 1 file, không có khái niệm multiple schemas như MySQL.
        // Trả về tên database từ config hoặc mặc định 'main'.
        return [$connection->getDatabaseName() ?: 'main'];
    }

    // Các method interface bắt buộc
    public function schema() { return $this->schema; }
    public function connection() { return $this->connection; }
    public function tables() { return $this->tables; }
    public function has($table) { return array_key_exists($table, $this->tables); }
    public function table($table) {
        if (!$this->has($table)) {
            throw new \InvalidArgumentException("Table [$table] not found.");
        }
        return $this->tables[$table];
    }

    public function referencing(Blueprint $table)
    {
        $references = [];
        foreach ($this->tables as $blueprint) {
            foreach ($blueprint->references($table) as $reference) {
                $references[] = [
                    'blueprint' => $blueprint,
                    'reference' => $reference,
                ];
            }
        }
        return $references;
    }
}
