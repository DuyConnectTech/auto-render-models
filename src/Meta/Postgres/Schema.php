<?php

namespace Connecttech\AutoRenderModels\Meta\Postgres;

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

    public function connection() { return $this->connection; }
    public function schema() { return $this->schema; }
    public function tables() { return $this->tables; }
    public function has($table) { return array_key_exists($table, $this->tables); }
    
    public function table($table) {
        if (!$this->has($table)) {
            throw new \InvalidArgumentException("Table [$table] not found in schema [{$this->schema}]");
        }
        return $this->tables[$table];
    }

    public function referencing(Blueprint $table)
    {
        $references = [];
        foreach ($this->tables as $blueprint) {
            foreach ($blueprint->references($table) as $reference) {
                $references[] = ['blueprint' => $blueprint, 'reference' => $reference];
            }
        }
        return $references;
    }

    protected function load()
    {
        $tables = $this->fetchTables();
        foreach ($tables as $table) {
            $this->loadTable($table, false);
        }

        $views = $this->fetchViews();
        foreach ($views as $view) {
            $this->loadTable($view, true);
        }
        
        $this->loaded = true;
    }

    protected function fetchTables()
    {
        $sql = "SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_type = 'BASE TABLE'";
        $rows = $this->connection->select($sql, [$this->schema]);
        return array_map(fn($r) => $r->table_name, $rows);
    }

    protected function fetchViews()
    {
        $sql = "SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_type = 'VIEW'";
        $rows = $this->connection->select($sql, [$this->schema]);
        return array_map(fn($r) => $r->table_name, $rows);
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
        $sql = "SELECT * FROM information_schema.columns WHERE table_schema = ? AND table_name = ?";
        $columns = $this->connection->select($sql, [$this->schema, $blueprint->table()]);

        foreach ($columns as $column) {
            $blueprint->withColumn((new Column((array)$column))->normalize());
        }
    }

    protected function fillConstraints(Blueprint $blueprint)
    {
        $this->fillPrimaryKey($blueprint);
        $this->fillRelations($blueprint);
    }

    protected function fillPrimaryKey(Blueprint $blueprint)
    {
        $sql = "
            SELECT kcu.column_name
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu
              ON tc.constraint_name = kcu.constraint_name
              AND tc.table_schema = kcu.table_schema
            WHERE tc.constraint_type = 'PRIMARY KEY'
              AND tc.table_schema = ?
              AND tc.table_name = ?
            ORDER BY kcu.ordinal_position
        ";

        $rows = $this->connection->select($sql, [$this->schema, $blueprint->table()]);
        
        if (!empty($rows)) {
            $columns = array_map(fn($r) => $r->column_name, $rows);
            $key = [
                'name'    => 'primary',
                'index'   => 'PRIMARY',
                'columns' => $columns,
            ];
            $blueprint->withPrimaryKey(new Fluent($key));
        }
    }

    protected function fillRelations(Blueprint $blueprint)
    {
        $sql = "
            SELECT
                tc.constraint_name, 
                kcu.column_name, 
                ccu.table_name AS foreign_table_name,
                ccu.column_name AS foreign_column_name,
                ccu.table_schema AS foreign_table_schema
            FROM information_schema.table_constraints AS tc 
            JOIN information_schema.key_column_usage AS kcu
              ON tc.constraint_name = kcu.constraint_name
              AND tc.table_schema = kcu.table_schema
            JOIN information_schema.constraint_column_usage AS ccu
              ON ccu.constraint_name = tc.constraint_name
              AND ccu.table_schema = tc.table_schema
            WHERE tc.constraint_type = 'FOREIGN KEY'
              AND tc.table_schema = ?
              AND tc.table_name = ?
        ";

        $rows = $this->connection->select($sql, [$this->schema, $blueprint->table()]);
        
        $grouped = [];
        foreach ($rows as $row) {
            $name = $row->constraint_name;
            if (!isset($grouped[$name])) {
                $grouped[$name] = [
                    'columns' => [],
                    'references' => [],
                    'table' => $row->foreign_table_name,
                    'schema' => $row->foreign_table_schema
                ];
            }
            $grouped[$name]['columns'][] = $row->column_name;
            $grouped[$name]['references'][] = $row->foreign_column_name;
        }

        foreach ($grouped as $relation) {
            $setup = [
                'name'       => 'foreign',
                'index'      => '',
                'columns'    => $relation['columns'],
                'references' => $relation['references'],
                'on'         => [
                    'database' => $relation['schema'],
                    'table'    => $relation['table']
                ],
            ];
            $blueprint->withRelation(new Fluent($setup));
        }
    }
    
    public static function schemas(Connection $connection)
    {
        $sql = "SELECT schema_name FROM information_schema.schemata 
                WHERE schema_name NOT IN ('information_schema', 'pg_catalog', 'pg_toast') 
                AND schema_name NOT LIKE 'pg_temp_%'";
        
        $rows = $connection->select($sql);
        return array_column(json_decode(json_encode($rows), true), 'schema_name');
    }
}
