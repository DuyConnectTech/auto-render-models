<?php

namespace Connecttech\AutoRenderModels\Meta\MySql;

use Illuminate\Support\Arr;
use Illuminate\Support\Fluent;
use Illuminate\Database\Connection;
use Connecttech\AutoRenderModels\Meta\Blueprint;
use Connecttech\AutoRenderModels\Meta\Schema as SchemaContract;

/**
 * Class Schema
 *
 * Triển khai \Connecttech\AutoRenderModels\Meta\Schema cho MySQL/MariaDB.
 *
 * Nhiệm vụ:
 * - Load danh sách bảng & view trong một schema/database cụ thể.
 * - Parse metadata bảng:
 *      + Cột (columns) thông qua `SHOW FULL COLUMNS`
 *      + Primary key, index, foreign key thông qua `SHOW CREATE TABLE`
 * - Đóng gói toàn bộ thông tin vào các đối tượng Blueprint.
 *
 * Cấu trúc:
 * - Mỗi instance của class này đại diện cho 1 schema/database (vd: "my_app_db").
 * - Trong đó:
 *      + $tables: danh sách Blueprint của tất cả bảng + view trong schema đó.
 */
class Schema implements SchemaContract
{
    /**
     * Tên schema/database hiện tại.
     *
     * @var string
     */
    protected $schema;

    /**
     * Connection MySQL dùng để query metadata.
     *
     * @var \Illuminate\Database\MySqlConnection
     */
    protected $connection;

    /**
     * Flag đánh dấu đã load metadata hay chưa.
     * (Hiện tại luôn load trong __construct nên giá trị này không được dùng nhiều.)
     *
     * @var bool
     */
    protected $loaded = false;

    /**
     * Danh sách bảng (và view) thuộc schema này.
     *
     * Key   : tên bảng
     * Value : \Connecttech\AutoRenderModels\Meta\Blueprint
     *
     * @var \Connecttech\AutoRenderModels\Meta\Blueprint[]
     */
    protected $tables = [];

    /**
     * Schema (mapper) constructor.
     *
     * @param string                                      $schema     Tên schema/database.
     * @param \Illuminate\Database\MySqlConnection|mixed $connection Connection MySQL tương ứng.
     */
    public function __construct($schema, $connection)
    {
        $this->schema = $schema;
        $this->connection = $connection;

        $this->load();
    }

    /**
     * Lấy Doctrine SchemaManager tương ứng với connection này.
     *
     * Hiện tại chưa dùng, chỉ là hook cho future refactor:
     * @todo: Use Doctrine instead of raw database queries
     *
     * @return \Doctrine\DBAL\Schema\AbstractSchemaManager
     */
    public function manager()
    {
        return $this->connection->getDoctrineSchemaManager();
    }

    /**
     * Load thông tin tất cả bảng + view trong schema từ database.
     *
     * Quy trình:
     * - fetchTables() để lấy danh sách bảng (BASE TABLE)
     * - fetchViews() để lấy danh sách view
     * - Mỗi tên bảng/view => loadTable($name, $isView)
     *
     * @return void
     */
    protected function load()
    {
        $tables = $this->fetchTables($this->schema);
        foreach ($tables as $table) {
            $this->loadTable($table);
        }

        $views = $this->fetchViews($this->schema);
        foreach ($views as $table) {
            $this->loadTable($table, true);
        }

        $this->loaded = true;
    }

    /**
     * Lấy danh sách tên bảng (BASE TABLE) trong schema.
     *
     * Dựa trên lệnh:
     *  SHOW FULL TABLES FROM `schema` WHERE Table_type="BASE TABLE"
     *
     * @param string $schema Tên schema/database.
     *
     * @return array Danh sách tên bảng.
     */
    protected function fetchTables($schema)
    {
        $rows = $this->arraify(
            $this->connection->select(
                'SHOW FULL TABLES FROM ' . $this->wrap($schema) . ' WHERE Table_type="BASE TABLE"'
            )
        );

        $names = array_column($rows, 'Tables_in_' . $schema);

        return Arr::flatten($names);
    }

    /**
     * Lấy danh sách tên view trong schema.
     *
     * Dựa trên lệnh:
     *  SHOW FULL TABLES FROM `schema` WHERE Table_type="VIEW"
     *
     * @param string $schema Tên schema/database.
     *
     * @return array Danh sách tên view.
     */
    protected function fetchViews($schema)
    {
        $rows = $this->arraify(
            $this->connection->select(
                'SHOW FULL TABLES FROM ' . $this->wrap($schema) . ' WHERE Table_type="VIEW"'
            )
        );

        $names = array_column($rows, 'Tables_in_' . $schema);

        return Arr::flatten($names);
    }

    /**
     * Đổ thông tin cột vào Blueprint.
     *
     * Sử dụng câu lệnh:
     *  SHOW FULL COLUMNS FROM `schema`.`table`
     *
     * Mỗi dòng column metadata sẽ được parse bởi Meta\MySql\Column::normalize().
     *
     * @param \Connecttech\AutoRenderModels\Meta\Blueprint $blueprint
     *
     * @return void
     */
    protected function fillColumns(Blueprint $blueprint)
    {
        $rows = $this->arraify(
            $this->connection->select(
                'SHOW FULL COLUMNS FROM ' . $this->wrap($blueprint->qualifiedTable())
            )
        );

        foreach ($rows as $column) {
            $blueprint->withColumn(
                $this->parseColumn($column)
            );
        }
    }

    /**
     * Parse raw metadata cột thành Fluent dùng trong Blueprint.
     *
     * @param array $metadata Raw metadata của MySQL.
     *
     * @return \Illuminate\Support\Fluent
     */
    protected function parseColumn($metadata)
    {
        return (new Column($metadata))->normalize();
    }

    /**
     * Đổ thông tin primary key, indexes, relations vào Blueprint.
     *
     * Dựa trên lệnh:
     *  SHOW CREATE TABLE `schema`.`table`
     * hoặc
     *  SHOW CREATE VIEW `schema`.`view`
     *
     * @param \Connecttech\AutoRenderModels\Meta\Blueprint $blueprint
     *
     * @return void
     */
    protected function fillConstraints(Blueprint $blueprint)
    {
        $row = $this->arraify(
            $this->connection->select(
                'SHOW CREATE TABLE ' . $this->wrap($blueprint->qualifiedTable())
            )
        );

        $row = array_change_key_case($row[0]);

        // Với view thì key là 'create view', với table là 'create table'
        $sql = ($blueprint->isView() ? $row['create view'] : $row['create table']);
        $sql = str_replace('`', '', $sql);

        $this->fillPrimaryKey($sql, $blueprint);
        $this->fillIndexes($sql, $blueprint);
        $this->fillRelations($sql, $blueprint);
    }

    /**
     * Quick hack: convert kết quả query (stdClass array) thành mảng thuần.
     *
     * Lý do: từ một số phiên bản trở đi không set được PDO::FETCH_ASSOC
     * trực tiếp trên connection như trước, nên dùng JSON encode/decode.
     *
     * @param mixed $data
     *
     * @return mixed
     */
    protected function arraify($data)
    {
        return json_decode(json_encode($data), true);
    }

    /**
     * Parse phần PRIMARY KEY từ SQL DDL và gán vào Blueprint.
     *
     * Ví dụ pattern:
     *  PRIMARY KEY (`id`, `another_id`)
     *
     * @param string                                       $sql       Câu lệnh CREATE TABLE/VIEW.
     * @param \Connecttech\AutoRenderModels\Meta\Blueprint $blueprint
     *
     * @return void
     *
     * @todo: Support named primary keys
     */
    protected function fillPrimaryKey($sql, Blueprint $blueprint)
    {
        $pattern = '/\s*(PRIMARY KEY)\s+\(([^\)]+)\)/mi';

        if (preg_match_all($pattern, $sql, $indexes, PREG_SET_ORDER) == false) {
            return;
        }

        $key = [
            'name'    => 'primary',
            'index'   => '',
            'columns' => $this->columnize($indexes[0][2]),
        ];

        $blueprint->withPrimaryKey(new Fluent($key));
    }

    /**
     * Parse các INDEX/UNIQUE KEY từ SQL DDL và gán vào Blueprint.
     *
     * Ví dụ pattern:
     *  UNIQUE KEY some_index (`col1`, `col2`)
     *  KEY another_index (`col3`)
     *
     * @param string                                       $sql       Câu lệnh CREATE TABLE.
     * @param \Connecttech\AutoRenderModels\Meta\Blueprint $blueprint
     *
     * @return void
     */
    protected function fillIndexes($sql, Blueprint $blueprint)
    {
        $pattern = '/\s*(UNIQUE)?\s*(KEY|INDEX)\s+(\w+)\s+\(([^\)]+)\)/mi';

        if (preg_match_all($pattern, $sql, $indexes, PREG_SET_ORDER) == false) {
            return;
        }

        foreach ($indexes as $setup) {
            $index = [
                'name'    => strcasecmp($setup[1], 'unique') === 0 ? 'unique' : 'index',
                'columns' => $this->columnize($setup[4]),
                'index'   => $setup[3],
            ];

            $blueprint->withIndex(new Fluent($index));
        }
    }

    /**
     * Parse foreign key constraints từ SQL DDL và gán vào Blueprint.
     *
     * Ví dụ pattern match:
     *  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
     *  FOREIGN KEY (`a`,`b`) REFERENCES `other_db`.`table` (`x`,`y`)
     *
     * @param string                                       $sql       Câu lệnh CREATE TABLE.
     * @param \Connecttech\AutoRenderModels\Meta\Blueprint $blueprint
     *
     * @return void
     *
     * @todo: Support named foreign keys
     */
    protected function fillRelations($sql, Blueprint $blueprint)
    {
        $pattern = '/FOREIGN KEY\s+\(([^\)]+)\)\s+REFERENCES\s+([^\(^\s]+)\s*\(([^\)]+)\)/mi';
        preg_match_all($pattern, $sql, $relations, PREG_SET_ORDER);

        foreach ($relations as $setup) {
            $table = $this->resolveForeignTable($setup[2], $blueprint);

            $relation = [
                'name'       => 'foreign',
                'index'      => '',
                'columns'    => $this->columnize($setup[1]),
                'references' => $this->columnize($setup[3]),
                'on'         => $table,
            ];

            $blueprint->withRelation(new Fluent($relation));
        }
    }

    /**
     * Convert danh sách cột trong DDL (vd: "`id`, `name`") thành mảng tên cột.
     *
     * @param string $columns Chuỗi cột trong ngoặc.
     *
     * @return array
     */
    protected function columnize($columns)
    {
        return array_map('trim', explode(',', $columns));
    }

    /**
     * Bọc tên schema/bảng với backtick chuẩn MySQL.
     *
     * Hỗ trợ cả "schema.table" => `schema`.`table`
     *
     * @param string $table
     *
     * @return string
     */
    protected function wrap($table)
    {
        $pieces = explode('.', str_replace('`', '', $table));

        return implode('.', array_map(function ($piece) {
            return "`$piece`";
        }, $pieces));
    }

    /**
     * Xác định schema + table của foreign key REFERENCES.
     *
     * - Nếu $table có dạng "db.table":
     *      => database = db, table = table
     * - Nếu chỉ có "table":
     *      => database = schema hiện tại của blueprint
     *
     * @param string                                       $table     Chuỗi references table trong DDL.
     * @param \Connecttech\AutoRenderModels\Meta\Blueprint $blueprint Blueprint của bảng hiện tại.
     *
     * @return array{database:string,table:string}
     */
    protected function resolveForeignTable($table, Blueprint $blueprint)
    {
        $referenced = explode('.', $table);

        if (count($referenced) == 2) {
            return [
                'database' => current($referenced),
                'table'    => next($referenced),
            ];
        }

        return [
            'database' => $blueprint->schema(),
            'table'    => current($referenced),
        ];
    }

    /**
     * Lấy danh sách schema/database có trong server MySQL (trừ system schemas).
     *
     * Query:
     *  SELECT schema_name FROM information_schema.schemata
     *
     * Filter bỏ:
     *  - information_schema
     *  - sys
     *  - mysql
     *  - performance_schema
     *
     * @param \Illuminate\Database\Connection $connection
     *
     * @return array Danh sách tên schema/database.
     */
    public static function schemas(Connection $connection)
    {
        $schemas = $connection->select('SELECT schema_name FROM information_schema.schemata');
        $schemas = array_column($schemas, 'schema_name');

        return array_diff($schemas, [
            'information_schema',
            'sys',
            'mysql',
            'performance_schema',
        ]);
    }

    /**
     * Tên schema/database hiện tại.
     *
     * @return string
     */
    public function schema()
    {
        return $this->schema;
    }

    /**
     * Kiểm tra xem schema này có chứa bảng với tên cho trước hay không.
     *
     * @param string $table Tên bảng.
     *
     * @return bool
     */
    public function has($table)
    {
        return array_key_exists($table, $this->tables);
    }

    /**
     * Lấy toàn bộ Blueprint (bảng + view) trong schema.
     *
     * @return \Connecttech\AutoRenderModels\Meta\Blueprint[]
     */
    public function tables()
    {
        return $this->tables;
    }

    /**
     * Lấy Blueprint của một bảng theo tên.
     *
     * @param string $table Tên bảng.
     *
     * @return \Connecttech\AutoRenderModels\Meta\Blueprint
     *
     * @throws \InvalidArgumentException Nếu bảng không thuộc schema này.
     */
    public function table($table)
    {
        if (! $this->has($table)) {
            throw new \InvalidArgumentException("Table [$table] does not belong to schema [{$this->schema}]");
        }

        return $this->tables[$table];
    }

    /**
     * Lấy connection tương ứng với schema này.
     *
     * @return \Illuminate\Database\MySqlConnection
     */
    public function connection()
    {
        return $this->connection;
    }

    /**
     * Tìm tất cả các bảng trong schema này có foreign key trỏ tới bảng $table.
     *
     * Trả về mảng:
     *  [
     *      [
     *          'blueprint' => Blueprint của bảng tham chiếu,
     *          'reference' => Fluent mô tả foreign key
     *      ],
     *      ...
     *  ]
     *
     * Dùng cho logic generate quan hệ ngược (HasOne/HasMany/BelongsToMany,...).
     *
     * @param \Connecttech\AutoRenderModels\Meta\Blueprint $table Bảng được tham chiếu tới.
     *
     * @return array
     */
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

    /**
     * Load metadata cho một bảng hoặc view cụ thể và đưa vào $this->tables.
     *
     * @param string $table  Tên bảng/view.
     * @param bool   $isView Có phải view không (mặc định: false = bảng).
     *
     * @return void
     */
    protected function loadTable($table, $isView = false)
    {
        $blueprint = new Blueprint($this->connection->getName(), $this->schema, $table, $isView);

        $this->fillColumns($blueprint);
        $this->fillConstraints($blueprint);

        $this->tables[$table] = $blueprint;
    }
}
