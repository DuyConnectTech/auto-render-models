<?php

namespace Connecttech\AutoRenderModels\Model;

use Illuminate\Support\Str;

/**
 * Class Mutation
 *
 * Đại diện cho một accessor (getXxxAttribute) được generate cho Eloquent Model.
 * - $name: tên "logical" của field/mutation (ví dụ: full_name)
 * - $body: phần biểu thức body (vd: '$this->first_name . " " . $this->last_name')
 *
 * Khi render:
 *  - name()  => getFullNameAttribute
 *  - body()  => return $this->first_name . " " . $this->last_name;
 */
class Mutation
{
    /**
     * Tên logical của mutation (chưa format thành method).
     *
     * @var string
     */
    protected $name;

    /**
     * Biểu thức PHP dùng làm body cho accessor (không kèm "return" và dấu ;).
     *
     * @var string
     */
    protected $body;

    /**
     * Mutation constructor.
     *
     * @param string $name Tên logical cho mutation (sẽ được convert thành method accessor).
     * @param string $body Biểu thức PHP, sẽ được wrap thành "return ...;".
     */
    public function __construct($name, $body)
    {
        $this->name = $name;
        $this->body = $body;
    }

    /**
     * Lấy tên method accessor theo chuẩn Eloquent:
     *  get{StudlyName}Attribute
     *
     * Ví dụ:
     *  - $name = "full_name" => "getFullNameAttribute"
     *
     * @return string
     */
    public function name()
    {
        return 'get' . Str::studly($this->name) . 'Attribute';
    }

    /**
     * Lấy body hoàn chỉnh cho method accessor,
     * tự động wrap thành "return {body};".
     *
     * @return string
     */
    public function body()
    {
        return 'return ' . $this->body . ';';
    }
}
