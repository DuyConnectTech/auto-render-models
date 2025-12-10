<?php

namespace Connecttech\AutoRenderModels\Support;

/**
 * Class Dumper
 *
 * Helper để convert giá trị PHP (array, string, scalar...) thành code PHP dạng string,
 * phục vụ việc generate file (model, config...) một cách đẹp, dễ đọc.
 *
 * Đặc biệt:
 * - Hỗ trợ format array theo dạng nhiều dòng, indent bằng tab.
 * - Nếu gặp string có chứa '::' (ví dụ: SomeClass::CONSTANT), coi đó là static call/constant
 *   và giữ nguyên, không bọc trong dấu nháy hay var_export.
 */
class Dumper
{
    /**
     * Kiểm tra xem value có là một "static call" / class constant kiểu 'ClassName::SOMETHING' hay không.
     *
     * Dùng để quyết định:
     * - Nếu là string bình thường => bọc bằng var_export (thêm nháy)
     * - Nếu là 'ClassName::CONSTANT' => giữ nguyên, vì đó là code PHP hợp lệ.
     *
     * @param mixed $value Giá trị cần kiểm tra.
     *
     * @return bool true nếu là string và có chứa '::', ngược lại false.
     */
    private static function hasStaticCall($value)
    {
        return is_string($value) && strpos($value, '::') !== false;
    }

    /**
     * Export một giá trị PHP thành chuỗi PHP code.
     *
     * Rules:
     * - Nếu là array:
     *     + In dạng nhiều dòng:
     *       [
     *           'key' => 'value',
     *           'nested' => [...],
     *       ]
     *     + Key số (0,1,2,...) => in dạng value bình thường, không '0 =>'...
     *     + Key string:
     *         * Nếu chứa '::' => coi như static call/constant, không bọc nháy
     *         * Ngược lại => bọc nháy: 'key'
     * - Nếu KHÔNG phải array:
     *     + Nếu là static call (có '::') => trả về nguyên string
     *     + Ngược lại => dùng var_export để convert thành literal (chuỗi, số, bool...)
     *
     * @param mixed $value Giá trị cần export.
     * @param int   $tabs  Số lượng tab indent hiện tại (dùng cho array lồng nhau).
     *
     * @return string Chuỗi code PHP tương ứng với $value.
     */
    public static function export($value, $tabs = 2)
    {
        // Custom array exporting (định dạng array nhiều dòng, indent đẹp)
        if (is_array($value)) {
            $indent = str_repeat("\t", $tabs);
            $closingIndent = str_repeat("\t", $tabs - 1);
            $keys = array_keys($value);

            $array = array_map(function ($value, $key) use ($tabs) {
                // Nếu key là số, coi như array indexed, chỉ cần value
                if (is_numeric($key)) {
                    return static::export($value, $tabs + 1);
                }

                // Nếu key trông giống static call thì giữ nguyên, ngược lại bọc trong nháy
                $key = static::hasStaticCall($key) ? $key : "'$key'";

                return "$key => " . static::export($value, $tabs + 1);
            }, $value, $keys);

            return "[\n$indent" . implode(",\n$indent", $array) . "\n$closingIndent]";
        }

        // Default variable exporting (scalar, string, bool, null...)
        // Nếu là static call thì trả nguyên string, không var_export
        return static::hasStaticCall($value) ? $value : var_export($value, true);
    }
}
