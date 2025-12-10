<?php

namespace Connecttech\AutoRenderModels\Model\Relations;

use Illuminate\Support\Fluent;
use Connecttech\AutoRenderModels\Model\Model;
use Connecttech\AutoRenderModels\Model\Relation;

/**
 * Class HasOneOrManyStrategy
 *
 * Strategy pattern để chọn giữa:
 *  - HasOne
 *  - HasMany
 *
 * Dựa trên Fluent $command và $related model:
 *  - Nếu constraint là primary key hoặc unique key trên related:
 *      => dùng HasOne
 *  - Ngược lại:
 *      => dùng HasMany
 *
 * Sau đó class này implement Relation và chỉ đơn giản
 * forward các call (hint, name, body, returnType) sang
 * relation cụ thể được chọn.
 */
class HasOneOrManyStrategy implements Relation
{
    /**
     * Instance relation thực sự (HasOne hoặc HasMany).
     *
     * @var \Connecttech\AutoRenderModels\Model\Relation
     */
    protected $relation;

    /**
     * HasOneOrManyStrategy constructor.
     *
     * @param \Illuminate\Support\Fluent                 $command Thông tin constraint (columns, references,...).
     * @param \Connecttech\AutoRenderModels\Model\Model $parent  Model cha (nơi quan hệ được khai báo).
     * @param \Connecttech\AutoRenderModels\Model\Model $related Model liên quan (bên kia quan hệ).
     */
    public function __construct(Fluent $command, Model $parent, Model $related)
    {
        // Nếu constraint là primary key hoặc unique key bên bảng related
        // => quan hệ chỉ trỏ tới 1 record => HasOne
        if (
            $related->isPrimaryKey($command) ||
            $related->isUniqueKey($command)
        ) {
            $this->relation = new HasOne($command, $parent, $related);
        } else {
            // Ngược lại, có thể nhiều record => HasMany
            $this->relation = new HasMany($command, $parent, $related);
        }
    }

    /**
     * Proxy sang relation thật: hint dùng cho @property PHPDoc.
     *
     * @return string
     */
    public function hint()
    {
        return $this->relation->hint();
    }

    /**
     * Proxy sang relation thật: tên method quan hệ trên model.
     *
     * @return string
     */
    public function name()
    {
        return $this->relation->name();
    }

    /**
     * Proxy sang relation thật: thân method quan hệ.
     *
     * @return string
     */
    public function body()
    {
        return $this->relation->body();
    }

    /**
     * Return type của method quan hệ.
     *
     * Vì $this->relation có thể là:
     *  - HasMany  => Relations\HasMany
     *  - HasOne   => Relations\HasOne
     *
     * nên ở đây check class cụ thể để trả về phù hợp.
     *
     * @return string
     */
    public function returnType()
    {
        return get_class($this->relation) === HasMany::class
            ? \Illuminate\Database\Eloquent\Relations\HasMany::class
            : \Illuminate\Database\Eloquent\Relations\HasOne::class;
    }
}
