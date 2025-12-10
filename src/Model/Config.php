<?php

namespace Connecttech\AutoRenderModels\Model;

use Illuminate\Support\Arr;
use Connecttech\AutoRenderModels\Meta\Blueprint;

/**
 * Class Config
 *
 * Lớp chịu trách nhiệm đọc giá trị cấu hình theo thứ tự ưu tiên
 * dựa trên thông tin của Blueprint (connection, schema, table...).
 *
 * Ví dụ:
 *  - Ưu tiên cấu hình riêng cho từng bảng theo từng connection
 *  - Sau đó tới schema, connection, global (*)
 *
 * @package Connecttech\AutoRenderModels\Model
 */
class Config
{
    /**
     * Mảng cấu hình gốc được inject từ bên ngoài.
     *
     * Cấu trúc thường là dạng nested array, đọc bằng Arr::get().
     *
     * @var array<string, mixed>
     */
    protected array $config = [];

    /**
     * Khởi tạo Config với mảng cấu hình ban đầu.
     *
     * @param array<string, mixed> $config Mảng config đã được load (từ file, DB...).
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * Lấy giá trị cấu hình theo thứ tự ưu tiên dựa trên Blueprint.
     *
     * Thứ tự ưu tiên (từ cao xuống thấp):
     *  1. @connections.{connection}.{table}.{key}
     *  2. @connections.{connection}.{schema}.{key}
     *  3. @connections.{connection}.{key}
     *  4. {qualifiedTable}.{key} (ví dụ: schema.table.key)
     *  5. {schema}.{key}
     *  6. *.{key} (global cho tất cả)
     *
     * Nếu không tìm thấy bất kỳ key nào trong chuỗi ưu tiên thì
     * sẽ trả về giá trị $default.
     *
     * @param  \Connecttech\AutoRenderModels\Meta\Blueprint $blueprint Đối tượng mô tả metadata của model (connection, table, schema...).
     * @param  string                                       $key       Tên key cần lấy trong config.
     * @param  mixed                                        $default   Giá trị mặc định nếu không tìm thấy.
     *
     * @return mixed Giá trị cấu hình tìm được hoặc $default nếu không có.
     */
    public function get(Blueprint $blueprint, $key, $default = null)
    {
        // Danh sách key cần check theo thứ tự ưu tiên.
        // Càng ở trên càng ưu tiên cao.
        $priorityKeys = [
            // Config riêng cho 1 table cụ thể trên 1 connection
            "@connections.{$blueprint->connection()}.{$blueprint->table()}.$key",

            // Config riêng cho 1 schema trên 1 connection
            "@connections.{$blueprint->connection()}.{$blueprint->schema()}.$key",

            // Config chung cho 1 connection
            "@connections.{$blueprint->connection()}.$key",

            // Config cho table (qualified: schema.table)
            "{$blueprint->qualifiedTable()}.$key",

            // Config cho schema
            "{$blueprint->schema()}.$key",

            // Config global cho tất cả (*)
            "*.$key",
        ];

        // Lần lượt duyệt qua các key ưu tiên
        foreach ($priorityKeys as $key) {
            // Dùng Arr::get để đọc nested key trong mảng config
            $value = Arr::get($this->config, $key);

            // Nếu tìm thấy giá trị (khác null) thì trả về ngay
            if (!is_null($value)) {
                return $value;
            }
        }

        // Nếu tất cả key đều không có trong config thì dùng default
        return $default;
    }
}
