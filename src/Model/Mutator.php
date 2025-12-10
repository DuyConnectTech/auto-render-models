<?php

namespace Connecttech\AutoRenderModels\Model;

use Connecttech\AutoRenderModels\Meta\Blueprint;

/**
 * Class Mutator
 *
 * Đại diện cho một rule dùng để sinh ra Mutation (accessor) cho Model.
 *
 * Cấu trúc:
 *  - when(Closure $condition): xác định điều kiện cột nào áp dụng mutator.
 *  - name(Closure $name): định nghĩa cách generate tên method cho mutation.
 *  - body(Closure $body): định nghĩa thân method (biểu thức) cho mutation.
 *
 * Khi chạy:
 *  - applies($column, $blueprint) => true/false
 *  - getName($attribute, $model)  => tên method (string)
 *  - getBody($attribute, $model)  => body (string expression)
 */
class Mutator
{
    /**
     * Điều kiện để mutator được áp dụng cho một column nào đó.
     *
     * Closure nhận:
     *  - string $column
     *  - \Connecttech\AutoRenderModels\Meta\Blueprint $blueprint
     * Trả về: bool (áp dụng hay không).
     *
     * @var \Closure
     */
    protected $condition;

    /**
     * Closure tạo tên method mutation.
     *
     * Closure nhận:
     *  - string $attribute
     *  - \Connecttech\AutoRenderModels\Model\Model $model
     * Trả về: string (tên method, ví dụ: fullName).
     *
     * @var \Closure
     */
    protected $name;

    /**
     * Closure tạo phần body (biểu thức) cho mutation.
     *
     * Closure nhận:
     *  - string $attribute
     *  - \Connecttech\AutoRenderModels\Model\Model $model
     * Trả về: string (biểu thức PHP, không kèm "return" và dấu chấm phẩy).
     *
     * @var \Closure
     */
    protected $body;

    /**
     * Khai báo điều kiện để mutator được áp dụng.
     *
     * Ví dụ:
     *  $mutator->when(function ($column, Blueprint $blueprint) {
     *      return Str::endsWith($column, '_json');
     *  });
     *
     * @param \Closure $condition
     *
     * @return $this
     */
    public function when(\Closure $condition)
    {
        $this->condition = $condition;

        return $this;
    }

    /**
     * Kiểm tra mutator có áp dụng được cho column + blueprint này không.
     *
     * @param string                                        $column
     * @param \Connecttech\AutoRenderModels\Meta\Blueprint  $blueprint
     *
     * @return mixed Kết quả trả về từ Closure condition (thường là bool).
     */
    public function applies($column, Blueprint $blueprint)
    {
        return call_user_func($this->condition, $column, $blueprint);
    }

    /**
     * Định nghĩa cách đặt tên cho mutation.
     *
     * Ví dụ:
     *  $mutator->name(function ($attribute, Model $model) {
     *      return 'formatted_' . $attribute;
     *  });
     *
     * @param \Closure $name
     *
     * @return $this
     */
    public function name(\Closure $name)
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Lấy tên mutation thực tế từ Closure name.
     *
     * Ở bước sau, tên này sẽ được đưa vào `Mutation`,
     * rồi `Mutation::name()` convert thành accessor kiểu getXxxAttribute.
     *
     * @param string                                       $attribute Tên thuộc tính/cột.
     * @param \Connecttech\AutoRenderModels\Model\Model    $model     Model hiện tại.
     *
     * @return string
     */
    public function getName($attribute, Model $model)
    {
        return call_user_func($this->name, $attribute, $model);
    }

    /**
     * Định nghĩa body cho mutation.
     *
     * Ví dụ:
     *  $mutator->body(function ($attribute, Model $model) {
     *      return "\$this->{$attribute} . ' extra'";
     *  });
     *
     * @param \Closure $body
     *
     * @return $this
     */
    public function body(\Closure $body)
    {
        $this->body = $body;

        return $this;
    }

    /**
     * Lấy body thực tế cho mutation từ Closure body.
     *
     * Giá trị trả về là biểu thức PHP (không kèm từ khóa return và dấu ;)
     * sau đó sẽ được `Mutation::body()` wrap thành "return ...;".
     *
     * @param string                                       $attribute Tên thuộc tính/cột.
     * @param \Connecttech\AutoRenderModels\Model\Model    $model     Model hiện tại.
     *
     * @return string
     */
    public function getBody($attribute, Model $model)
    {
        return call_user_func($this->body, $attribute, $model);
    }
}
