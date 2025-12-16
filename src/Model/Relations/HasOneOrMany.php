<?php

namespace Connecttech\AutoRenderModels\Model\Relations;

use Connecttech\AutoRenderModels\Model\Model;
use Connecttech\AutoRenderModels\Model\Relation;
use Connecttech\AutoRenderModels\Support\Dumper;
use Illuminate\Support\Fluent;

/**
 * Class HasOneOrMany
 *
 * Lớp abstract dùng chung logic cho các quan hệ:
 *  - HasOne
 *  - HasMany
 *
 * Dùng chung:
 *  - $parent   : model cha (nơi khai báo quan hệ)
 *  - $related  : model con (bên kia quan hệ)
 *  - $command  : thông tin constraint (columns, references...)
 *
 * Nhiệm vụ lớp này:
 *  - Cài đặt chung body() để sinh code:
 *      return $this->hasOne/hasMany(Related::class, 'foreign_key', 'local_key');
 *  - Xử lý việc quyết định có cần truyền foreignKey/localKey hay dùng mặc định Eloquent.
 *
 * Các class con cần implement:
 *  - hint()
 *  - name()
 *  - method()  (trả về 'hasOne' hoặc 'hasMany')
 */
abstract class HasOneOrMany implements Relation
{
    /**
     * Thông tin ràng buộc dạng Fluent:
     * - columns   : foreign key bên related hay parent (tuỳ thiết kế)
     * - references: local key bên bảng còn lại
     *
     * @var \Illuminate\Support\Fluent
     */
    protected $command;

    /**
     * Model cha (nơi method relation được generate).
     *
     * @var \Connecttech\AutoRenderModels\Model\Model
     */
    protected $parent;

    /**
     * Model liên quan (bên kia quan hệ).
     *
     * @var \Connecttech\AutoRenderModels\Model\Model
     */
    protected $related;

    /**
     * HasOneOrMany constructor.
     *
     * @param \Illuminate\Support\Fluent                    $command Thông tin foreign/local key.
     * @param \Connecttech\AutoRenderModels\Model\Model     $parent  Model cha.
     * @param \Connecttech\AutoRenderModels\Model\Model     $related Model liên quan.
     */
    public function __construct(Fluent $command, Model $parent, Model $related)
    {
        $this->command = $command;
        $this->parent = $parent;
        $this->related = $related;
    }

    /**
     * Hint dùng cho PHPDoc @property của quan hệ.
     *
     * Implement ở class con:
     *  - HasOne::hint()    => "Related|null"
     *  - HasMany::hint()   => "Collection|Related[]"
     *
     * @return string
     */
    abstract public function hint();

    /**
     * Tên method quan hệ.
     *
     * Implement ở class con:
     *  - HasOne::name()
     *  - HasMany::name()
     *
     * @return string
     */
    abstract public function name();

    /**
     * Sinh thân method quan hệ hasOne/hasMany.
     *
     * Format:
     *  return $this->{method}(
     *      Related::class,
     *      'foreign_key?',  // chỉ khi khác default hoặc cần localKey
     *      'local_key?'     // chỉ khi khác primary key
     *  );
     *
     * Logic:
     * - Luôn truyền Related::class.
     * - Chỉ truyền foreignKey khi:
     *     + Khác default (parentRecordName() . '_id')
     *     HOẶC
     *     + Cần localKey
     * - Chỉ truyền localKey khi:
     *     + Khác primary key mặc định của parent.
     *
     * @return string
     */
    public function body()
    {
        $body = 'return $this->' . $this->method() . '(';

        // Tham số 1: class model liên quan
        $body .= $this->related->getQualifiedUserClassName() . '::class';

        // Tham số 2: foreign key nếu cần
        if ($this->needsForeignKey()) {
            $foreignKey = $this->parent->usesPropertyConstants()
                ? $this->related->getQualifiedUserClassName() . '::' . strtoupper($this->foreignKey())
                : $this->foreignKey();
            $body .= ', ' . Dumper::export($foreignKey);
        }

        // Tham số 3: local key nếu cần
        if ($this->needsLocalKey()) {
            $localKey = $this->related->usesPropertyConstants()
                ? $this->related->getQualifiedUserClassName() . '::' . strtoupper($this->localKey())
                : $this->localKey();
            $body .= ', ' . Dumper::export($localKey);
        }

        $body .= ');';

        return $body;
    }

    /**
     * Tên method Eloquent sẽ được gọi.
     *
     * Class con phải implement:
     *  - HasOne::method()  => 'hasOne'
     *  - HasMany::method() => 'hasMany'
     *
     * @return string
     */
    abstract protected function method();

    /**
     * Có cần truyền foreign key vào hasOne/hasMany hay không.
     *
     * Default theo Eloquent:
     *  - {parent_record_name}_id
     *
     * Truyền foreignKey khi:
     *  - Khác default
     *  HOẶC
     *  - Cần truyền localKey (để đủ tham số).
     *
     * @return bool
     */
    protected function needsForeignKey()
    {
        $defaultForeignKey = $this->parent->getRecordName() . '_id';

        return $defaultForeignKey != $this->foreignKey() || $this->needsLocalKey();
    }

    /**
     * Lấy tên foreign key từ command.
     *
     * @return string
     */
    protected function foreignKey()
    {
        return $this->command->columns[0];
    }

    /**
     * Có cần truyền local key vào hasOne/hasMany hay không.
     *
     * Default:
     *  - primary key của parent model.
     *
     * Khi localKey khác primary => phải truyền vào.
     *
     * @return bool
     */
    protected function needsLocalKey()
    {
        return $this->parent->getPrimaryKey() != $this->localKey();
    }

    /**
     * Lấy local key từ command (cột được tham chiếu).
     *
     * @return string
     */
    protected function localKey()
    {
        return $this->command->references[0];
    }

    /**
     * Nội dung Docblock cho method quan hệ.
     *
     * @return string
     */
    public function docblock()
    {
        return '@return ' . $this->returnType() . '<' . $this->related->getQualifiedUserClassName() . '>';
    }
}
