<?php

namespace Connecttech\AutoRenderModels\Model\Relations;

use Illuminate\Support\Str;
use Illuminate\Support\Fluent;
use Connecttech\AutoRenderModels\Model\Model;
use Connecttech\AutoRenderModels\Model\Relation;
use Connecttech\AutoRenderModels\Support\Dumper;

/**
 * Class BelongsTo
 *
 * Đại diện cho quan hệ belongsTo được sinh ra trên Eloquent Model.
 *
 * Nhiệm vụ:
 * - Xác định tên method quan hệ (name())
 * - Xây dựng thân method belongsTo(...) (body())
 * - Trả về type hint (@property) và return type của method quan hệ
 *
 * Logic xử lý:
 * - Tự đoán tên relation theo strategy:
 *    + 'foreign_key' => dựa trên tên cột foreign key
 *    + 'related' (default) => dựa trên tên class liên quan
 * - Tự suy đoán foreignKey / otherKey default. Chỉ truyền vào belongsTo()
 *   nếu khác mặc định, hoặc nếu cần other key.
 * - Hỗ trợ composite keys bằng cách nối thêm where(...) trong chain.
 */
class BelongsTo implements Relation
{
    /**
     * Thông tin constraint (foreign key) dạng Fluent:
     * - columns: cột foreign key ở bảng hiện tại
     * - references: cột được tham chiếu ở bảng related
     * - on: [database, table] được tham chiếu
     *
     * @var \Illuminate\Support\Fluent
     */
    protected $command;

    /**
     * Model cha (bảng hiện tại, nơi chứa foreign key).
     *
     * @var \Connecttech\AutoRenderModels\Model\Model
     */
    protected $parent;

    /**
     * Model liên quan (bảng được tham chiếu).
     *
     * @var \Connecttech\AutoRenderModels\Model\Model
     */
    protected $related;

    /**
     * BelongsToWriter constructor.
     *
     * @param \Illuminate\Support\Fluent                    $command Thông tin quan hệ (columns, references, on...).
     * @param \Connecttech\AutoRenderModels\Model\Model     $parent  Model hiện tại (chứa foreign key).
     * @param \Connecttech\AutoRenderModels\Model\Model     $related Model liên quan (bảng target).
     */
    public function __construct(Fluent $command, Model $parent, Model $related)
    {
        $this->command = $command;
        $this->parent = $parent;
        $this->related = $related;
    }

    /**
     * Tên method quan hệ trên model.
     *
     * Tuỳ theo relation_name_strategy:
     * - 'foreign_key' => sinh tên từ foreign key (strip _id, convert snake/camel)
     * - 'related' (mặc định) => sinh tên từ tên class của related model
     *
     * Cuối cùng:
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
                    $this->otherKey(),
                    $this->foreignKey()
                );
                break;
            default:
            case 'related':
                $relationName = $this->related->getClassName();
                break;
        }

        if ($this->parent->usesSnakeAttributes()) {
            return Str::snake($relationName);
        }

        return Str::camel($relationName);
    }

    /**
     * Thân method quan hệ:
     *
     * - Cơ bản: return $this->belongsTo(Related::class);
     * - Nếu foreignKey khác mặc định hoặc cần otherKey:
     *     return $this->belongsTo(Related::class, 'foreign_key', 'other_key');
     * - Trường hợp composite key:
     *     chain thêm ->where('related.col', '=', 'parent.col') cho từng cặp.
     *
     * @return string
     */
    public function body()
    {
        $body = 'return $this->belongsTo(';

        // Tham số đầu: class liên quan
        $body .= $this->related->getQualifiedUserClassName() . '::class';

        // Tham số thứ 2: foreign key (nếu cần custom hoặc cần other key)
        if ($this->needsForeignKey()) {
            $foreignKey = $this->parent->usesPropertyConstants()
                ? $this->parent->getQualifiedUserClassName() . '::' . strtoupper($this->foreignKey())
                : $this->foreignKey();
            $body .= ', ' . Dumper::export($foreignKey);
        }

        // Tham số thứ 3: other key (primary key bên bảng related)
        if ($this->needsOtherKey()) {
            $otherKey = $this->related->usesPropertyConstants()
                ? $this->related->getQualifiedUserClassName() . '::' . strtoupper($this->otherKey())
                : $this->otherKey();
            $body .= ', ' . Dumper::export($otherKey);
        }

        $body .= ')';

        // Composite key: xây thêm where(...) cho từng cặp cột
        if ($this->hasCompositeOtherKey()) {
            // Giả định: các cột references tạo thành composite primary/unique key.
            // Nếu không, thực ra nên là has-many (chưa support).
            foreach ($this->command->references as $index => $column) {
                $body .= "\n\t\t\t\t\t->where(" .
                    Dumper::export($this->qualifiedOtherKey($index)) .
                    ", '=', " .
                    Dumper::export($this->qualifiedForeignKey($index)) .
                    ')';
            }
        }

        $body .= ';';

        return $body;
    }

    /**
     * Type hint dùng cho @property.
     *
     * - Mặc định: \Full\Namespace\UserModel
     * - Nếu cột foreign key nullable: thêm '|null'
     *
     * @return string
     */
    public function hint()
    {
        $base =  $this->related->getQualifiedUserClassName();

        if ($this->isNullable()) {
            $base .= '|null';
        }

        return $base;
    }

    /**
     * Return type của method quan hệ.
     *
     * Mặc định là Eloquent\Relations\BelongsTo::class.
     *
     * @return string
     */
    public function returnType()
    {
        return \Illuminate\Database\Eloquent\Relations\BelongsTo::class;
    }

    /**
     * Có cần truyền foreignKey vào belongsTo() không.
     *
     * Cần khi:
     * - foreign key khác với default (record_name + '_id')
     *   HOẶC
     * - cần truyền other key (có composite hoặc custom).
     *
     * @return bool
     */
    protected function needsForeignKey()
    {
        $defaultForeignKey = $this->related->getRecordName() . '_id';

        return $defaultForeignKey != $this->foreignKey() || $this->needsOtherKey();
    }

    /**
     * Lấy tên foreign key (ở bảng parent) theo index.
     *
     * @param int $index
     *
     * @return string
     */
    protected function foreignKey($index = 0)
    {
        return $this->command->columns[$index];
    }

    /**
     * Lấy tên foreign key ở dạng qualified: parent_table.column.
     *
     * @param int $index
     *
     * @return string
     */
    protected function qualifiedForeignKey($index = 0)
    {
        return $this->parent->getTable() . '.' . $this->foreignKey($index);
    }

    /**
     * Có cần truyền otherKey vào belongsTo() không.
     *
     * Cần khi:
     * - otherKey khác với primary key mặc định của related model.
     *
     * @return bool
     */
    protected function needsOtherKey()
    {
        $defaultOtherKey = $this->related->getPrimaryKey();

        return $defaultOtherKey != $this->otherKey();
    }

    /**
     * Lấy tên cột bên bảng related (references).
     *
     * @param int $index
     *
     * @return string
     */
    protected function otherKey($index = 0)
    {
        return $this->command->references[$index];
    }

    /**
     * Lấy tên cột bên related ở dạng qualified: related_table.column.
     *
     * @param int $index
     *
     * @return string
     */
    protected function qualifiedOtherKey($index = 0)
    {
        return $this->related->getTable() . '.' . $this->otherKey($index);
    }

    /**
     * Kiểm tra "other key" có phải composite key không.
     *
     * @return bool
     */
    protected function hasCompositeOtherKey()
    {
        return count($this->command->references) > 1;
    }

    /**
     * Kiểm tra foreign key có nullable không (để quyết định hint '|null').
     *
     * @return bool
     */
    private function isNullable()
    {
        return (bool) $this->parent->getBlueprint()->column($this->foreignKey())->get('nullable');
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
