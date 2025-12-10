<?php

namespace Connecttech\AutoRenderModels\Meta;

use Illuminate\Support\Fluent;

/**
 * Class Blueprint
 *
 * Đại diện metadata của một bảng (hoặc view) trong database.
 *
 * Dùng để mô tả:
 * - Kết nối (connection)
 * - Schema (database)
 * - Tên bảng
 * - Danh sách cột (columns)
 * - Indexes, unique keys
 * - Quan hệ (foreign keys)
 * - Primary key (có thể composite)
 * - Có phải view hay không
 *
 * Class này kế thừa từ Illuminate\Support\Fluent để có thể chứa thêm
 * data dynamic nếu cần, ngoài các thuộc tính đã define.
 */
class Blueprint extends Fluent
{
    /**
     * Tên connection (tên cấu hình trong config/database.php).
     *
     * @var string
     */
    protected $connection;

    /**
     * Tên schema / database.
     *
     * @var string
     */
    protected $schema;

    /**
     * Tên bảng.
     *
     * @var string
     */
    protected $table;

    /**
     * Danh sách cột của bảng.
     *
     * Mỗi phần tử là một \Illuminate\Support\Fluent mô tả metadata của cột đó.
     *
     * @var \Illuminate\Support\Fluent[]
     */
    protected $columns = [];

    /**
     * Danh sách index (KEY/INDEX) trên bảng.
     *
     * @var \Illuminate\Support\Fluent[]
     */
    protected $indexes = [];

    /**
     * Danh sách unique index (UNIQUE KEY) trên bảng.
     *
     * @var \Illuminate\Support\Fluent[]
     */
    protected $unique = [];

    /**
     * Danh sách quan hệ (foreign keys) dưới dạng Fluent.
     *
     * @var \Illuminate\Support\Fluent[]
     */
    protected $relations = [];

    /**
     * Primary key (có thể là composite) dạng Fluent.
     *
     * @var \Illuminate\Support\Fluent
     */
    protected $primaryKey;

    /**
     * Cho biết đây có phải là view hay không.
     *
     * @var bool
     */
    protected $isView;

    /**
     * Blueprint constructor.
     *
     * @param string $connection Tên connection của database.
     * @param string $schema     Tên schema / database.
     * @param string $table      Tên bảng.
     * @param bool   $isView     Có phải là view hay không (mặc định: false).
     */
    public function __construct($connection, $schema, $table, $isView = false)
    {
        $this->connection = $connection;
        $this->schema = $schema;
        $this->table = $table;
        $this->isView = $isView;
    }

    /**
     * Lấy tên schema / database.
     *
     * @return string
     */
    public function schema()
    {
        return $this->schema;
    }

    /**
     * Lấy tên bảng.
     *
     * @return string
     */
    public function table()
    {
        return $this->table;
    }

    /**
     * Lấy tên bảng fully-qualified: "schema.table".
     *
     * @return string
     */
    public function qualifiedTable()
    {
        return $this->schema() . '.' . $this->table();
    }

    /**
     * Thêm một cột vào blueprint.
     *
     * @param \Illuminate\Support\Fluent $column
     *
     * @return $this
     */
    public function withColumn(Fluent $column)
    {
        $this->columns[$column->name] = $column;

        return $this;
    }

    /**
     * Lấy danh sách các cột của bảng.
     *
     * @return \Illuminate\Support\Fluent[]
     */
    public function columns()
    {
        return $this->columns;
    }

    /**
     * Kiểm tra xem bảng có cột với tên cho trước không.
     *
     * @param string $name Tên cột.
     *
     * @return bool
     */
    public function hasColumn($name)
    {
        return array_key_exists($name, $this->columns);
    }

    /**
     * Lấy metadata của một cột theo tên.
     *
     * @param string $name Tên cột.
     *
     * @return \Illuminate\Support\Fluent
     *
     * @throws \InvalidArgumentException Nếu cột không tồn tại trong bảng.
     */
    public function column($name)
    {
        if (! $this->hasColumn($name)) {
            throw new \InvalidArgumentException("Column [$name] does not belong to table [{$this->qualifiedTable()}]");
        }

        return $this->columns[$name];
    }

    /**
     * Thêm một index vào danh sách index.
     *
     * Đồng thời:
     * - Nếu index có name == 'unique' => push vào danh sách unique.
     *
     * @param \Illuminate\Support\Fluent $index
     *
     * @return $this
     */
    public function withIndex(Fluent $index)
    {
        $this->indexes[] = $index;

        if ($index->name == 'unique') {
            $this->unique[] = $index;
        }

        return $this;
    }

    /**
     * Lấy danh sách index trên bảng.
     *
     * @return \Illuminate\Support\Fluent[]
     */
    public function indexes()
    {
        return $this->indexes;
    }

    /**
     * Thêm thông tin quan hệ (foreign key) vào blueprint.
     *
     * @param \Illuminate\Support\Fluent $index
     *
     * @return $this
     */
    public function withRelation(Fluent $index)
    {
        $this->relations[] = $index;

        return $this;
    }

    /**
     * Lấy danh sách các quan hệ (foreign keys).
     *
     * @return \Illuminate\Support\Fluent[]
     */
    public function relations()
    {
        return $this->relations;
    }

    /**
     * Set primary key cho bảng (có thể composite).
     *
     * @param \Illuminate\Support\Fluent $primaryKey
     *
     * @return $this
     */
    public function withPrimaryKey(Fluent $primaryKey)
    {
        $this->primaryKey = $primaryKey;

        return $this;
    }

    /**
     * Lấy thông tin primary key.
     *
     * Ưu tiên:
     * - Nếu đã set $this->primaryKey => trả về nó
     * - Nếu chưa set nhưng có unique index => lấy unique đầu tiên làm "primary" tạm thời
     * - Nếu không có gì => trả về Fluent với ['columns' => []] (primary key rỗng)
     *
     * @return \Illuminate\Support\Fluent
     */
    public function primaryKey()
    {
        if ($this->primaryKey) {
            return $this->primaryKey;
        }

        if (! empty($this->unique)) {
            return current($this->unique);
        }

        $nullPrimaryKey = new Fluent(['columns' => []]);

        return $nullPrimaryKey;
    }

    /**
     * Kiểm tra xem primary key có phải composite key (nhiều cột) không.
     *
     * @return bool
     */
    public function hasCompositePrimaryKey()
    {
        return count($this->primaryKey->columns) > 1;
    }

    /**
     * Lấy tên connection của blueprint.
     *
     * @return string
     */
    public function connection()
    {
        return $this->connection;
    }

    /**
     * Kiểm tra xem blueprint này có trỏ tới cùng một bảng với cặp (database, table) truyền vào không.
     *
     * @param string $database Tên schema/database.
     * @param string $table    Tên bảng.
     *
     * @return bool
     */
    public function is($database, $table)
    {
        return $database == $this->schema() && $table == $this->table();
    }

    /**
     * Lấy danh sách các foreign key trong blueprint hiện tại
     * mà trỏ tới bảng được truyền vào.
     *
     * @param \Connecttech\AutoRenderModels\Meta\Blueprint $table Blueprint của bảng target.
     *
     * @return \Illuminate\Support\Fluent[] Danh sách Fluent mô tả foreign key.
     */
    public function references(self $table)
    {
        $references = [];

        foreach ($this->relations() as $relation) {
            // $relation->on thường chứa ['database' => ..., 'table' => ...]
            list($foreignDatabase, $foreignTable) = array_values($relation->on);

            if ($table->is($foreignDatabase, $foreignTable)) {
                $references[] = $relation;
            }
        }

        return $references;
    }

    /**
     * Kiểm tra constraint truyền vào có tương ứng với một unique key
     * đơn cột trên bảng này hay không.
     *
     * Chỉ xét các unique index mà:
     * - Có đúng 1 cột
     * - Và cột đó nằm trong danh sách columns của constraint.
     *
     * Dùng để support logic phân biệt HasOne / HasMany.
     *
     * @param \Illuminate\Support\Fluent $constraint
     *
     * @return bool
     */
    public function isUniqueKey(Fluent $constraint)
    {
        foreach ($this->unique as $index) {

            // Chỉ quan tâm unique key mà chỉ có đúng 1 column
            if (count($index->columns) === 1 && isset($index->columns[0])) {
                if (in_array($index->columns[0], $constraint->columns)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Kiểm tra xem blueprint đang mô tả view hay table.
     *
     * @return bool
     */
    public function isView()
    {
        return $this->isView;
    }
}
