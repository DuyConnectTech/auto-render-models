<?php

namespace Connecttech\AutoRenderModels\Model\Relations;

use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Collection;

/**
 * Class HasMany
 *
 * Đại diện cho quan hệ hasMany được sinh ra trên Eloquent Model.
 *
 * Kế thừa HasOneOrMany, nên dùng chung:
 * - $parent   : model cha (nơi khai báo quan hệ)
 * - $related  : model con (bên kia quan hệ)
 * - foreignKey / localKey xử lý trong HasOneOrMany
 *
 * Nhiệm vụ chính ở đây:
 * - hint()      : type hint cho @property (Collection|RelatedModel[])
 * - name()      : sinh tên method quan hệ theo strategy
 * - method()    : tên method Eloquent sử dụng ('hasMany')
 * - returnType(): kiểu trả về của method (Relations\HasMany)
 */
class HasMany extends HasOneOrMany
{
    /**
     * Hint dùng cho PHPDoc @property trên model.
     *
     * Ví dụ:
     *  - "\Illuminate\Database\Eloquent\Collection|\App\Models\Post[]"
     *
     * @return string
     */
    public function hint()
    {
        return '\\' . Collection::class . '|' . $this->related->getQualifiedUserClassName() . '[]';
    }

    /**
     * Tên method quan hệ hasMany.
     *
     * Logic:
     * - Nếu relation_name_strategy = 'foreign_key':
     *     + Dùng RelationHelper::stripSuffixFromForeignKey() để lấy phần name từ foreignKey/localKey
     *     + Nếu kết quả trùng với tên class của parent:
     *         -> dùng dạng plural của related class (ví dụ: posts)
     *       Ngược lại:
     *         -> "{PluralRelated}Where{SingularRelationName}"
     *         ví dụ: OrdersWhereStatus, CommentsWhereType
     * - Nếu strategy = 'related' (mặc định):
     *     -> dùng plural của related class (Users, Posts, Comments, ...)
     *
     * Sau đó:
     * - Nếu usesSnakeAttributes() => snake_case
     * - Ngược lại => camelCase
     *
     * @return string
     */
    public function name()
    {
        switch ($this->parent->getRelationNameStrategy()) {
            case 'foreign_key':
                $relationName = RelationHelper::stripSuffixFromForeignKey(
                    $this->parent->usesSnakeAttributes(),
                    $this->localKey(),
                    $this->foreignKey()
                );

                // Nếu tên quan hệ trùng với tên model cha => chỉ pluralize tên model con
                if (Str::snake($relationName) === Str::snake($this->parent->getClassName())) {
                    $relationName = Str::plural($this->related->getClassName());
                } else {
                    // Ngược lại: đặt kiểu "{PluralRelated}Where{Condition}"
                    $relationName = Str::plural($this->related->getClassName()) . 'Where' . ucfirst(Str::singular($relationName));
                }
                break;

            default:
            case 'related':
                $relationName = Str::plural($this->related->getClassName());
                break;
        }

        if ($this->parent->usesSnakeAttributes()) {
            return Str::snake($relationName);
        }

        return Str::camel($relationName);
    }

    /**
     * Tên method Eloquent sẽ được gọi.
     *
     * Được HasOneOrMany::body() (hoặc tương tự) sử dụng để sinh:
     *  - return $this->hasMany(...);
     *
     * @return string
     */
    public function method()
    {
        return 'hasMany';
    }

    /**
     * Return type của method quan hệ.
     *
     * Dùng cho PHP return type hint trong method generate.
     *
     * @return string
     */
    public function returnType()
    {
        return \Illuminate\Database\Eloquent\Relations\HasMany::class;
    }
}
