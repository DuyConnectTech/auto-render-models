<?php

namespace Connecttech\AutoRenderModels\Database\Eloquent;

/**
 * Trait BitBooleans
 *
 * Hỗ trợ convert giá trị boolean <-> kiểu bit (binary) dạng "\x00" / "\x01"
 * thường dùng khi cột trong database là kiểu BIT(1) hoặc tương tự.
 *
 * Use-case điển hình:
 * - Khi đọc từ DB (BIT):
 *      "\x00" => false
 *      "\x01" => true
 *   các giá trị còn lại => giữ nguyên (trả về như cũ)
 *
 * - Khi ghi xuống DB:
 *      false => "\x00"
 *      true  => "\x01"
 *   các giá trị khác => giữ nguyên
 */
trait BitBooleans
{
    /**
     * Convert giá trị từ DB (bit) sang boolean PHP.
     *
     * - "\x00" => false
     * - "\x01" => true
     * - các giá trị khác => trả lại nguyên vẹn (không ép bool)
     *
     * @param mixed $value Giá trị đọc được từ DB (có thể là string binary, bool, số...).
     *
     * @return bool|mixed Trả về bool nếu match "\x00" hoặc "\x01", ngược lại trả về $value gốc.
     */
    public function fromBool($value)
    {
        if ($value === "\x00") {
            return false;
        }
        if ($value === "\x01") {
            return true;
        }

        return $value;
    }

    /**
     * Alias cho fromBool(), giữ lại để tương thích tên method.
     *
     * @param mixed $value Giá trị đọc từ DB.
     *
     * @return bool|mixed
     */
    public function fromBoolean($value)
    {
        return $this->fromBool($value);
    }

    /**
     * Convert boolean PHP sang giá trị bit dạng binary string.
     *
     * - false => "\x00"
     * - true  => "\x01"
     * - giá trị khác => trả lại nguyên vẹn (không ép sang bit)
     *
     * @param mixed $value Giá trị cần convert (bool hoặc kiểu khác).
     *
     * @return mixed Trả về "\x00" / "\x01" nếu là bool, ngược lại trả về $value gốc.
     */
    public function toBool($value)
    {
        if ($value === false) {
            return "\x00";
        }
        if ($value === true) {
            return "\x01";
        }

        return $value;
    }

    /**
     * Alias cho toBool(), giữ lại để phù hợp naming "Boolean".
     *
     * @param mixed $value Giá trị cần convert.
     *
     * @return mixed
     */
    public function toBoolean($value)
    {
        return $this->toBool($value);
    }
}
