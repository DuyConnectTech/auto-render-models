<?php

namespace Connecttech\AutoRenderModels\Meta;

/**
 * Interface Schema
 *
 * Trừu tượng hoá metadata của một schema/database cụ thể.
 *
 * Mục tiêu:
 * - Cung cấp API thống nhất để làm việc với:
 *      + connection (Laravel DB connection)
 *      + tên schema/database
 *      + danh sách bảng (Blueprint[])
 *      + truy vấn bảng theo tên
 *      + kiểm tra bảng có tồn tại hay không
 *      + tìm các bảng/quan hệ đang tham chiếu đến một bảng khác
 *
 * Các implementation (MySQL, PostgreSQL, v.v.) sẽ triển khai interface này
 * để SchemaManager/Factory có thể làm việc mà không phụ thuộc driver cụ thể.
 */
interface Schema
{
    /**
     * Lấy connection tương ứng với schema này.
     *
     * Thường là instance connection đã được inject/config trong SchemaManager,
     * ví dụ: \Illuminate\Database\MySqlConnection.
     *
     * @return \Illuminate\Database\ConnectionInterface
     */
    public function connection();

    /**
     * Tên schema/database mà implementation này đang đại diện.
     *
     * Ví dụ:
     *  - "public"
     *  - "my_app_db"
     *
     * @return string
     */
    public function schema();

    /**
     * Lấy danh sách tất cả các bảng thuộc schema này.
     *
     * Mỗi bảng được mô tả bằng một Blueprint.
     *
     * @return \Connecttech\AutoRenderModels\Meta\Blueprint[]
     */
    public function tables();

    /**
     * Kiểm tra xem schema có bảng với tên được truyền vào hay không.
     *
     * @param string $table Tên bảng cần kiểm tra.
     *
     * @return bool
     */
    public function has($table);

    /**
     * Lấy Blueprint tương ứng với bảng theo tên.
     *
     * Nếu bảng không tồn tại, tuỳ implementation có thể ném exception hoặc xử lý khác.
     *
     * @param string $table Tên bảng.
     *
     * @return \Connecttech\AutoRenderModels\Meta\Blueprint
     */
    public function table($table);

    /**
     * Trả về danh sách các quan hệ (constraints) trong schema này
     * mà đang tham chiếu tới một bảng được truyền vào.
     *
     * Thường dùng để xác định các bảng nào có foreign key
     * trỏ vào bảng $table (phục vụ generate các quan hệ ngược).
     *
     * @param \Connecttech\AutoRenderModels\Meta\Blueprint $table Bảng target cần tìm tham chiếu tới.
     *
     * @return array Danh sách constraints/relations (tuỳ implementation).
     */
    public function referencing(Blueprint $table);
}
