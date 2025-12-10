<?php

namespace Connecttech\AutoRenderModels\Meta;

/**
 * Interface Column
 *
 * Chuẩn hoá metadata cột lấy từ database driver về một dạng thống nhất.
 *
 * Các implementation (MySQL, PostgreSQL, SQLServer, ...) sẽ:
 * - Nhận raw column info từ hệ thống (information_schema, system catalogs, v.v.)
 * - Ánh xạ và convert về một object \Illuminate\Support\Fluent
 *   với các key chuẩn (name, type, nullable, default, comment, autoincrement, ...)
 *
 * Blueprint/SchemeManager sau đó sẽ làm việc chỉ với Column::normalize()
 * thay vì phụ thuộc vào từng loại driver riêng.
 */
interface Column
{
    /**
     * Chuẩn hoá metadata của cột về dạng Fluent thống nhất.
     *
     * Yêu cầu tối thiểu (tuỳ implementation, nhưng thường sẽ bao gồm):
     * - name        : tên cột
     * - type        : kiểu dữ liệu (đã mapping về kiểu chung)
     * - nullable    : có cho phép null không
     * - default     : giá trị mặc định
     * - comment     : mô tả/ghi chú cột (nếu có)
     * - autoincrement: có auto increment hay không
     * - ... và các field khác phục vụ quá trình generate Model
     *
     * @return \Illuminate\Support\Fluent
     */
    public function normalize();
}
