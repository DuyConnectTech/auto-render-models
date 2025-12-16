<?php

namespace Connecttech\AutoRenderModels\Model;

/**
 * Interface Relation
 *
 * Chuẩn hoá cấu trúc một quan hệ (relationship) được generator sinh ra cho Model.
 *
 * Một Relation sẽ chịu trách nhiệm:
 *  - hint()       : Trả về PHP type hint dùng cho @property (vd: "\App\Models\Post[]")
 *  - name()       : Tên method quan hệ trên Eloquent model (vd: "posts")
 *  - body()       : Nội dung thân method (vd: 'return $this->hasMany(Post::class);')
 *  - returnType() : Kiểu trả về của method (vd: "\Illuminate\Database\Eloquent\Relations\HasMany")
 *
 * Các class triển khai:
 *  - BelongsTo
 *  - HasMany
 *  - HasOne
 *  - ... (tùy vào implementation của package)
 */
interface Relation
{
    /**
     * Trả về hint dùng cho PHPDoc @property của model.
     *
     * Ví dụ:
     *  - "\App\Models\Post"
     *  - "\App\Models\Post[]"
     *
     * @return string
     */
    public function hint();

    /**
     * Tên method quan hệ trên model.
     *
     * Ví dụ:
     *  - "user"
     *  - "posts"
     *
     * @return string
     */
    public function name();

    /**
     * Thân method quan hệ (body) dưới dạng chuỗi PHP.
     *
     * Ví dụ:
     *  - 'return $this->hasMany(Post::class);'
     *  - 'return $this->belongsTo(User::class, "user_id");'
     *
     * @return string
     */
    public function body();

    /**
     * Kiểu trả về của method quan hệ.
     *
     * Ví dụ:
     *  - "\Illuminate\Database\Eloquent\Relations\HasMany"
     *  - "\Illuminate\Database\Eloquent\Relations\BelongsTo"
     *
     * @return string
     */
    public function returnType();

    /**
     * Nội dung Docblock cho method quan hệ.
     *
     * Ví dụ:
     *  - "@return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\Post>"
     *
     * @return string|null
     */
    public function docblock();
}
