<?php

namespace Connecttech\AutoRenderModels\Meta;

use ArrayIterator;
use RuntimeException;
use IteratorAggregate;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\MariaDbConnection;
use Illuminate\Database\ConnectionInterface;
use Connecttech\AutoRenderModels\Meta\MySql\Schema as MySqlSchema;

/**
 * Class SchemaManager
 *
 * Quản lý danh sách Schema (database/schema metadata) cho một Connection.
 *
 * Nhiệm vụ chính:
 * - Ánh xạ kiểu connection (MySQL, MariaDB, ...) sang class Schema tương ứng (mapper).
 * - Lấy danh sách schema/database từ connection driver (thông qua mapper::schemas()).
 * - Khởi tạo object Schema tương ứng cho từng schema và cache lại.
 * - Cho phép iterate qua tất cả schema đã load (implements IteratorAggregate).
 *
 * Cách hoạt động:
 * - Khi new SchemaManager($connection):
 *      + Lưu connection
 *      + Gọi $this->boot()
 * - boot():
 *      + Check xem connection hiện tại có mapper tương ứng trong self::$lookup không.
 *      + Gọi {MapperClass}::schemas($connection) để lấy danh sách schema/database.
 *      + Mỗi schema => $this->make($schema) => tạo instance mapper cho schema đó.
 *
 * Mở rộng:
 * - Có thể đăng ký mapper mới cho connection khác bằng:
 *      SchemaManager::register(SomeConnection::class, SomeSchemaMapper::class);
 */
class SchemaManager implements IteratorAggregate
{
    /**
     * Bảng ánh xạ giữa loại Connection và lớp Schema mapper tương ứng.
     *
     * Key   : tên class của Connection (MySqlConnection, MariaDbConnection, ...)
     * Value : tên class mapper triển khai \Connecttech\AutoRenderModels\Meta\Schema
     *
     * @var array<string, class-string<\Connecttech\AutoRenderModels\Meta\Schema>>
     */
    protected static $lookup = [
        MySqlConnection::class   => MySqlSchema::class,
        MariaDbConnection::class => MySqlSchema::class,
        // \DoctrineSupport\Connections\MySqlConnection::class => MySqlSchema::class,
        // Staudenmeir\LaravelCte\Connections\MySqlConnection::class => MySqlSchema::class,
    ];

    /**
     * Connection hiện tại mà SchemaManager đang quản lý.
     *
     * @var \Illuminate\Database\ConnectionInterface
     */
    private $connection;

    /**
     * Danh sách các schema đã được load cho connection này.
     *
     * Key   : tên schema
     * Value : instance của mapper triển khai \Connecttech\AutoRenderModels\Meta\Schema
     *
     * @var \Connecttech\AutoRenderModels\Meta\Schema[]
     */
    protected $schemas = [];

    /**
     * SchemaManager constructor.
     *
     * @param \Illuminate\Database\ConnectionInterface $connection Connection cần quản lý schema.
     */
    public function __construct(ConnectionInterface $connection)
    {
        $this->connection = $connection;

        $this->boot();
    }

    /**
     * Load toàn bộ schema từ connection hiện tại.
     *
     * Quy trình:
     * - Kiểm tra xem connection type có mapper tương ứng không (hasMapping()).
     *   + Nếu không có => ném RuntimeException.
     * - Gọi {MapperClass}::schemas($connection) để lấy danh sách schema/database.
     * - Duyệt qua danh sách schema và gọi $this->make($schema) để khởi tạo/cached.
     *
     * @return void
     */
    public function boot()
    {
        if (! $this->hasMapping()) {
            throw new RuntimeException("There is no Schema Mapper registered for [{$this->type()}] connection.");
        }

        // Lấy danh sách tên schema từ mapper static method: SchemaMapper::schemas($connection)
        $schemas = forward_static_call([$this->getMapper(), 'schemas'], $this->connection);

        foreach ($schemas as $schema) {
            $this->make($schema);
        }
    }

    /**
     * Lấy hoặc tạo mới Schema mapper cho một schema cụ thể.
     *
     * - Nếu schema đã tồn tại trong cache $this->schemas => trả về lại.
     * - Nếu chưa => tạo mới thông qua makeMapper() và cache lại.
     *
     * @param string $schema Tên schema/database.
     *
     * @return \Connecttech\AutoRenderModels\Meta\Schema
     */
    public function make($schema)
    {
        if (array_key_exists($schema, $this->schemas)) {
            return $this->schemas[$schema];
        }

        return $this->schemas[$schema] = $this->makeMapper($schema);
    }

    /**
     * Khởi tạo instance mapper cho một schema.
     *
     * Mapper được lấy từ getMapper(), sau đó new {Mapper}($schema, $connection).
     *
     * @param string $schema Tên schema/database.
     *
     * @return \Connecttech\AutoRenderModels\Meta\Schema
     */
    protected function makeMapper($schema)
    {
        $mapper = $this->getMapper();

        return new $mapper($schema, $this->connection);
    }

    /**
     * Lấy tên class mapper tương ứng với connection hiện tại.
     *
     * @return string class-string<\Connecttech\AutoRenderModels\Meta\Schema>
     */
    protected function getMapper()
    {
        return static::$lookup[$this->type()];
    }

    /**
     * Lấy "type" của connection hiện tại, thực chất là tên class.
     *
     * Ví dụ:
     *  - Illuminate\Database\MySqlConnection
     *  - Illuminate\Database\MariaDbConnection
     *
     * @return string
     */
    protected function type()
    {
        return get_class($this->connection);
    }

    /**
     * Kiểm tra xem connection hiện tại có được map tới bất kỳ Schema mapper nào không.
     *
     * @return bool
     */
    protected function hasMapping()
    {
        return array_key_exists($this->type(), static::$lookup);
    }

    /**
     * Đăng ký mapper mới cho một loại Connection.
     *
     * Dùng để mở rộng khi có custom Connection driver.
     *
     * @param string $connection Tên class của Connection.
     * @param string $mapper     Tên class mapper (triển khai \Connecttech\AutoRenderModels\Meta\Schema).
     *
     * @return void
     */
    public static function register($connection, $mapper)
    {
        static::$lookup[$connection] = $mapper;
    }

    /**
     * Trả về iterator để có thể foreach qua toàn bộ schema đã load.
     *
     * Ví dụ:
     *  foreach ($schemaManager as $schemaName => $schemaInstance) { ... }
     *
     * @return \ArrayIterator
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->schemas);
    }
}
