<?php

namespace Connecttech\AutoRenderModels\Meta\MySql;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Fluent;

/**
 * Class Column
 *
 * Triển khai \Connecttech\AutoRenderModels\Meta\Column cho MySQL/MariaDB.
 *
 * Nhiệm vụ:
 * - Nhận metadata thô từ MySQL (kết quả SHOW COLUMNS, INFORMATION_SCHEMA, ...)
 * - Chuẩn hoá về object Fluent với các field chuẩn:
 *     - type          : kiểu dữ liệu đã mapping sang nhóm PHP (string/int/float/datetime/bool/boolean)
 *     - name          : tên cột
 *     - autoincrement : có auto increment không
 *     - nullable      : có cho phép NULL không
 *     - default       : giá trị mặc định
 *     - comment       : comment/ghi chú của cột
 *     - enum          : (nếu là enum) mảng các giá trị
 *     - size          : kích thước (nếu có)
 *     - scale         : số chữ số sau dấu phẩy (cho numeric/decimal)
 *     - unsigned      : (int) có unsigned không
 *     - mappings      : đặc biệt cho bit boolean ("\x00" => false, "\x01" => true)
 */
class Column implements \Connecttech\AutoRenderModels\Meta\Column
{
    /**
     * Metadata thô của cột lấy từ MySQL.
     *
     * Thường chứa các key như:
     *  - Field
     *  - Type
     *  - Null
     *  - Key
     *  - Default
     *  - Extra
     *  - Comment
     *
     * @var array
     */
    protected $metadata;

    /**
     * Danh sách meta cần parse và convert vào Fluent attributes.
     *
     * Mỗi phần tử tương ứng với một method parseXxx().
     *
     * @var array
     */
    protected $metas = [
        'type',
        'name',
        'autoincrement',
        'nullable',
        'default',
        'comment',
    ];

    /**
     * Mapping giữa type PHP "chuẩn" và các kiểu MySQL tương ứng.
     *
     * Ví dụ:
     *  - 'string'  => ['varchar', 'text', 'char', 'enum', ...]
     *  - 'datetime'=> ['datetime', 'date', 'timestamp', ...]
     *  - 'int'     => ['bigint', 'int', 'tinyint', ...]
     *  - 'float'   => ['float', 'decimal', 'double', ...]
     *  - 'boolean' => ['bit']
     *
     * @var array<string, string[]>
     */
    public static $mappings = [
        'string'   => ['varchar', 'text', 'string', 'char', 'enum', 'set', 'tinytext', 'mediumtext', 'longtext', 'longblob', 'mediumblob', 'tinyblob', 'blob'],
        'datetime' => ['datetime', 'year', 'date', 'time', 'timestamp'],
        'int'      => ['bigint', 'int', 'integer', 'tinyint', 'smallint', 'mediumint'],
        'float'    => ['float', 'decimal', 'numeric', 'dec', 'fixed', 'double', 'real', 'double precision'],
        'boolean'  => ['bit'],
    ];

    /**
     * Mysql Column constructor.
     *
     * @param array $metadata Metadata thô của cột từ MySQL.
     */
    public function __construct(array $metadata = [])
    {
        $this->metadata = $metadata;
    }

    /**
     * Chuẩn hoá metadata cột về Fluent attributes thống nhất.
     *
     * Quy trình:
     * - Tạo Fluent $attributes rỗng.
     * - Lần lượt gọi các hàm parseXxx() tương ứng với meta trong $metas.
     *
     * @return \Illuminate\Support\Fluent
     */
    public function normalize()
    {
        $attributes = new Fluent();

        foreach ($this->metas as $meta) {
            $this->{'parse' . ucfirst($meta)}($attributes);
        }

        return $attributes;
    }

    /**
     * Parse kiểu dữ liệu (Type) từ metadata MySQL sang type "chuẩn".
     *
     * Ví dụ:
     *  - Type = "varchar(255)"   => type = "string", size = 255
     *  - Type = "int(11)"        => type = "int", size = 11, unsigned = true/false
     *  - Type = "enum('A','B')"  => type = "string", enum = ['A','B']
     *  - Type = "bit(1)"         => type = "bool", mappings = ["\x00" => false, "\x01" => true]
     *
     * @param \Illuminate\Support\Fluent $attributes
     *
     * @return void
     */
    protected function parseType(Fluent $attributes)
    {
        $type = $this->get('Type', 'string');

        // Tách kiểu + precision: type(length,scale) / enum('a','b')
        preg_match('/^(\w+)(?:\(([^\)]+)\))?/', $type, $matches);

        $dataType = strtolower($matches[1]);
        $attributes['type'] = $dataType;

        // Mapping sang nhóm type PHP chuẩn (string/int/float/datetime/boolean...)
        foreach (static::$mappings as $phpType => $database) {
            if (in_array($dataType, $database)) {
                $attributes['type'] = $phpType;
            }
        }

        // Nếu có phần precision (vd: (255), (10,2), ('A','B')...)
        if (isset($matches[2])) {
            $this->parsePrecision($dataType, $matches[2], $attributes);
        }

        // Nếu là int, check thêm unsigned
        if ($attributes['type'] == 'int') {
            $attributes['unsigned'] = Str::contains($type, 'unsigned');
        }
    }

    /**
     * Parse phần precision/length/scale/enum từ type MySQL.
     *
     * Ví dụ:
     * - databaseType = 'enum', precision = "'A','B'"
     *      => enum = ['A', 'B']
     *
     * - databaseType = 'bit', precision = "1"
     *      => size = 1 => type = bool, mappings = ["\x00" => false, "\x01" => true]
     *
     * - databaseType = 'tinyint', precision = "1"
     *      => type = bool (trường hợp tinyint(1) dùng như boolean)
     *
     * - databaseType = 'decimal', precision = "10,2"
     *      => size = 10, scale = 2
     *
     * @param string                       $databaseType Kiểu MySQL gốc (varchar/int/enum/bit/...).
     * @param string                       $precision    Chuỗi bên trong ngoặc (vd: "255", "10,2", "'A','B'").
     * @param \Illuminate\Support\Fluent   $attributes   Fluent attributes để ghi kết quả vào.
     *
     * @return void
     */
    protected function parsePrecision($databaseType, $precision, Fluent $attributes)
    {
        $precision = explode(',', str_replace("'", '', $precision));

        // Nếu là enum => precision chính là danh sách giá trị enum
        if ($databaseType == 'enum') {
            $attributes['enum'] = $precision;

            return;
        }

        $size = (int) current($precision);

        // Xử lý kiểu boolean:
        // - bit(1) hoặc tinyint(1) => coi là bool
        if ($size == 1 && in_array($databaseType, ['bit', 'tinyint'])) {
            $attributes['type'] = 'bool';

            // Đối với bit(1), map thêm binary value -> bool
            if ($databaseType == 'bit') {
                $attributes['mappings'] = ["\x00" => false, "\x01" => true];
            }

            return;
        }

        // Không phải boolean => ghi size/scale bình thường
        $attributes['size'] = $size;

        if ($scale = next($precision)) {
            $attributes['scale'] = (int) $scale;
        }
    }

    /**
     * Parse tên cột.
     *
     * @param \Illuminate\Support\Fluent $attributes
     *
     * @return void
     */
    protected function parseName(Fluent $attributes)
    {
        $attributes['name'] = $this->get('Field');
    }

    /**
     * Parse cờ auto_increment.
     *
     * Nếu Extra = 'auto_increment' => autoincrement = true
     *
     * @param \Illuminate\Support\Fluent $attributes
     *
     * @return void
     */
    protected function parseAutoincrement(Fluent $attributes)
    {
        if ($this->same('Extra', 'auto_increment')) {
            $attributes['autoincrement'] = true;
        }
    }

    /**
     * Parse nullable flag.
     *
     * Nếu Null = 'YES' => nullable = true
     *
     * @param \Illuminate\Support\Fluent $attributes
     *
     * @return void
     */
    protected function parseNullable(Fluent $attributes)
    {
        $attributes['nullable'] = $this->same('Null', 'YES');
    }

    /**
     * Parse default value.
     *
     * @param \Illuminate\Support\Fluent $attributes
     *
     * @return void
     */
    protected function parseDefault(Fluent $attributes)
    {
        $attributes['default'] = $this->get('Default');
    }

    /**
     * Parse comment của cột.
     *
     * @param \Illuminate\Support\Fluent $attributes
     *
     * @return void
     */
    protected function parseComment(Fluent $attributes)
    {
        $attributes['comment'] = $this->get('Comment');
    }

    /**
     * Helper: lấy giá trị từ metadata với key cho trước.
     *
     * @param string $key     Tên key trong metadata (vd: 'Type', 'Field', ...).
     * @param mixed  $default Giá trị fallback nếu không tồn tại.
     *
     * @return mixed
     */
    protected function get($key, $default = null)
    {
        return Arr::get($this->metadata, $key, $default);
    }

    /**
     * Helper: so sánh giá trị metadata với một giá trị cố định (case-insensitive).
     *
     * @param string $key   Tên key trong metadata.
     * @param string $value Giá trị cần so sánh.
     *
     * @return bool
     */
    protected function same($key, $value)
    {
        return strcasecmp($this->get($key, ''), $value) === 0;
    }
}
