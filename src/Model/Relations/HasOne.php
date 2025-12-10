<?php

namespace Connecttech\AutoRenderModels\Model\Relations;

use Illuminate\Support\Str;

/**
 * Class HasOne
 *
 * Đại diện cho quan hệ hasOne được generate trên Eloquent Model.
 *
 * Kế thừa HasOneOrMany, nên dùng chung:
 * - $parent   : model cha (nơi khai báo quan hệ)
 * - $related  : model con (bên kia quan hệ)
 * - foreignKey / localKey xử lý trong HasOneOrMany
 *
 * Nhiệm vụ chính:
 * - hint()      : type hint cho @property (RelatedModel|null)
 * - name()      : sinh tên method quan hệ
 * - method()    : tên method Eloquent sử dụng ('hasOne')
 * - returnType(): kiểu trả về của method (Relations\HasOne)
 */
class HasOne extends HasOneOrMany
{
    /**
     * Hint dùng cho PHPDoc @property trên model.
     *
     * Ví dụ:
     *  - "\App\Models\Profile|null"
     *
     * @return string
     */
    public function hint()
    {
        return $this->related->getQualifiedUserClassName() . '|null';
    }

    /**
     * Tên method quan hệ hasOne trên model.
     *
     * Ở đây logic đơn giản hơn HasMany:
     * - Dùng tên class của related model
     * - Nếu parent dùng snake attributes:
     *      => snake_case
     *   Ngược lại:
     *      => camelCase
     *
     * Ví dụ:
     *  - Related class: UserProfile
     *      + snake: user_profile
     *      + camel: userProfile
     *
     * @return string
     */
    public function name()
    {
        if ($this->parent->usesSnakeAttributes()) {
            return Str::snake($this->related->getClassName());
        }

        return Str::camel($this->related->getClassName());
    }

    /**
     * Tên method Eloquent sẽ được gọi cho quan hệ này.
     *
     * Được HasOneOrMany sử dụng để generate:
     *  - return $this->hasOne(...);
     *
     * @return string
     */
    public function method()
    {
        return 'hasOne';
    }

    /**
     * Return type của method quan hệ.
     *
     * Dùng khi generate method với return type:
     *  - function userProfile(): \Illuminate\Database\Eloquent\Relations\HasOne
     *
     * @return string
     */
    public function returnType()
    {
        return \Illuminate\Database\Eloquent\Relations\HasOne::class;
    }
}
