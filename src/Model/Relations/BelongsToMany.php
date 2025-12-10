<?php

namespace Connecttech\AutoRenderModels\Model\Relations;

use Illuminate\Support\Str;
use Connecttech\AutoRenderModels\Support\Dumper;
use Illuminate\Support\Fluent;
use Connecttech\AutoRenderModels\Model\Model;
use Connecttech\AutoRenderModels\Model\Relation;
use Illuminate\Database\Eloquent\Collection;

/**
 * Class BelongsToMany
 *
 * Đại diện cho quan hệ many-to-many (belongsToMany) được generate trên Eloquent Model.
 *
 * Cấu trúc:
 * - parent      : model hiện tại (nơi quan hệ được khai báo)
 * - pivot       : model của bảng trung gian (pivot table)
 * - reference   : model bên kia quan hệ
 * - parentCommand    : thông tin foreign key từ parent -> pivot
 * - referenceCommand : thông tin foreign key từ pivot -> reference
 *
 * Nhiệm vụ:
 * - Sinh:
 *   - Tên method quan hệ (name())
 *   - Thân method: belongsToMany(...)->withPivot(...)->withTimestamps()
 *   - Hint cho @property
 *   - Return type cho method
 */
class BelongsToMany implements Relation
{
    /**
     * Thông tin foreign key từ parent -> pivot (Fluent command).
     *
     * @var \Illuminate\Support\Fluent
     */
    protected $parentCommand;

    /**
     * Thông tin foreign key từ pivot -> reference (Fluent command).
     *
     * @var \Illuminate\Support\Fluent
     */
    protected $referenceCommand;

    /**
     * Model cha (nơi quan hệ belongsToMany được khai báo).
     *
     * @var \Connecttech\AutoRenderModels\Model\Model
     */
    protected $parent;

    /**
     * Model đại diện cho bảng pivot (bảng trung gian).
     *
     * @var \Connecttech\AutoRenderModels\Model\Model
     */
    protected $pivot;

    /**
     * Model ở đầu bên kia của quan hệ many-to-many.
     *
     * @var \Connecttech\AutoRenderModels\Model\Model
     */
    protected $reference;

    /**
     * BelongsToMany constructor.
     *
     * @param \Illuminate\Support\Fluent                    $parentCommand    Thông tin FK parent -> pivot.
     * @param \Illuminate\Support\Fluent                    $referenceCommand Thông tin FK pivot -> reference.
     * @param \Connecttech\AutoRenderModels\Model\Model     $parent           Model cha.
     * @param \Connecttech\AutoRenderModels\Model\Model     $pivot            Model pivot.
     * @param \Connecttech\AutoRenderModels\Model\Model     $reference        Model reference (bên kia quan hệ).
     */
    public function __construct(
        Fluent $parentCommand,
        Fluent $referenceCommand,
        Model $parent,
        Model $pivot,
        Model $reference
    ) {
        $this->parentCommand = $parentCommand;
        $this->referenceCommand = $referenceCommand;
        $this->parent = $parent;
        $this->pivot = $pivot;
        $this->reference = $reference;
    }

    /**
     * Hint dùng cho PHPDoc @property trên model.
     *
     * Ví dụ trả về:
     *  - "\Illuminate\Database\Eloquent\Collection|\App\Models\Tag[]"
     *
     * @return string
     */
    public function hint()
    {
        return '\\' . Collection::class . '|' . $this->reference->getQualifiedUserClassName() . '[]';
    }

    /**
     * Tên method quan hệ belongsToMany trên model.
     *
     * Logic:
     * - Lấy table của reference (bỏ prefix nếu có)
     * - Optional: lowerCase table name nếu config
     * - Optional: đảm bảo dạng plural nếu config
     * - Cuối cùng:
     *   + Nếu usesSnakeAttributes() => snake_case
     *   + Ngược lại => camelCase
     *
     * @return string
     */
    public function name()
    {
        $tableName = $this->reference->getTable(true);

        if ($this->parent->shouldLowerCaseTableName()) {
            $tableName = strtolower($tableName);
        }
        if ($this->parent->shouldPluralizeTableName()) {
            $tableName = Str::plural(Str::singular($tableName));
        }
        if ($this->parent->usesSnakeAttributes()) {
            return Str::snake($tableName);
        }

        return Str::camel($tableName);
    }

    /**
     * Thân method quan hệ belongsToMany.
     *
     * Generate code dạng:
     *
     * return $this->belongsToMany(
     *     Related::class,
     *     'pivot_table',
     *     'parent_fk',
     *     'other_fk'
     * )->withPivot('extra_field_1', 'extra_field_2')
     *  ->withTimestamps();
     *
     * Tự động:
     * - Suy đoán pivot table name, foreignKey, otherKey theo chuẩn Eloquent
     *   và chỉ truyền khi khác default.
     * - Lấy danh sách pivot fields (trừ FK + timestamps) để withPivot().
     * - Nếu pivot dùng timestamps => thêm withTimestamps().
     *
     * @return string
     */
    public function body()
    {
        $body = 'return $this->belongsToMany(';

        // Tham số 1: class reference
        $body .= $this->reference->getQualifiedUserClassName() . '::class';

        // Tham số 2: pivot table (nếu khác default hoặc cần foreignKey)
        if ($this->needsPivotTable()) {
            $body .= ', ' . Dumper::export($this->pivotTable());
        }

        // Tham số 3: foreign key (parent -> pivot) nếu cần
        if ($this->needsForeignKey()) {
            $foreignKey = $this->parent->usesPropertyConstants()
                ? $this->reference->getQualifiedUserClassName() . '::' . strtoupper($this->foreignKey())
                : $this->foreignKey();
            $body .= ', ' . Dumper::export($foreignKey);
        }

        // Tham số 4: other key (pivot -> reference) nếu cần
        if ($this->needsOtherKey()) {
            $otherKey = $this->reference->usesPropertyConstants()
                ? $this->reference->getQualifiedUserClassName() . '::' . strtoupper($this->otherKey())
                : $this->otherKey();
            $body .= ', ' . Dumper::export($otherKey);
        }

        $body .= ')';

        // Các field bổ sung ở pivot (ngoài FK + timestamps)
        $fields = $this->getPivotFields();

        if (! empty($fields)) {
            $body .= "\n\t\t\t\t\t->withPivot(" . $this->parametrize($fields) . ')';
        }

        // Pivot có dùng timestamps thì chain withTimestamps()
        if ($this->pivot->usesTimestamps()) {
            $body .= "\n\t\t\t\t\t->withTimestamps()";
        }

        $body .= ';';

        return $body;
    }

    /**
     * Return type của method quan hệ.
     *
     * @return string
     */
    public function returnType()
    {
        return \Illuminate\Database\Eloquent\Relations\BelongsToMany::class;
    }

    /**
     * Có cần truyền pivot table vào belongsToMany() không.
     *
     * Eloquent default pivot table name:
     * - Ghép tên 2 model (record name) dạng snake_case, sort alphabet, nối bằng "_".
     *
     * Truyền pivot table khi:
     * - Tên pivot khác default
     *   HOẶC
     * - Cần truyền foreignKey (và kéo theo pivot name được truyền rõ).
     *
     * @return bool
     */
    protected function needsPivotTable()
    {
        $models = [$this->referenceRecordName(), $this->parentRecordName()];
        sort($models);
        $defaultPivotTable = strtolower(implode('_', $models));

        return $this->pivotTable() != $defaultPivotTable || $this->needsForeignKey();
    }

    /**
     * Lấy tên pivot table.
     *
     * - Nếu schema của parent khác schema của pivot:
     *     => dùng qualified table (schema.table)
     * - Ngược lại:
     *     => chỉ dùng table name.
     *
     * @return string
     */
    protected function pivotTable()
    {
        if ($this->parent->getSchema() != $this->pivot->getSchema()) {
            return $this->pivot->getQualifiedTable();
        }

        return $this->pivot->getTable();
    }

    /**
     * Có cần truyền foreign key (parent -> pivot) không.
     *
     * Eloquent default:
     *  - parentRecordName() . '_id'
     *
     * @return bool
     */
    protected function needsForeignKey()
    {
        $defaultForeignKey = $this->parentRecordName() . '_id';

        return $this->foreignKey() != $defaultForeignKey || $this->needsOtherKey();
    }

    /**
     * Lấy tên foreign key từ parentCommand.
     *
     * @return string
     */
    protected function foreignKey()
    {
        return $this->parentCommand->columns[0];
    }

    /**
     * Có cần truyền other key (pivot -> reference) không.
     *
     * Eloquent default:
     *  - referenceRecordName() . '_id'
     *
     * @return bool
     */
    protected function needsOtherKey()
    {
        $defaultOtherKey = $this->referenceRecordName() . '_id';

        return $this->otherKey() != $defaultOtherKey;
    }

    /**
     * Lấy tên other key từ referenceCommand.
     *
     * @return string
     */
    protected function otherKey()
    {
        return $this->referenceCommand->columns[0];
    }

    /**
     * Lấy danh sách các field ở pivot để withPivot():
     * - Loại bỏ:
     *     + foreignKey
     *     + otherKey
     *     + created_at
     *     + updated_at
     *
     * @return array
     */
    private function getPivotFields()
    {
        return array_diff(array_keys($this->pivot->getProperties()), [
            $this->foreignKey(),
            $this->otherKey(),
            $this->pivot->getCreatedAtField(),
            $this->pivot->getUpdatedAtField(),
        ]);
    }

    /**
     * Lấy record name của parent (snake_case).
     * Eloquent assume tên FK dựa trên snake_case.
     *
     * @return string
     */
    protected function parentRecordName()
    {
        // We make sure it is snake case because Eloquent assumes it is.
        return Str::snake($this->parent->getRecordName());
    }

    /**
     * Lấy record name của reference (snake_case).
     *
     * @return string
     */
    protected function referenceRecordName()
    {
        // We make sure it is snake case because Eloquent assumes it is.
        return Str::snake($this->reference->getRecordName());
    }

    /**
     * Convert danh sách field thành danh sách tham số cho withPivot().
     *
     * - Nếu reference dùng property constants:
     *     => convert từng field thành "PivotClass::FIELD_NAME"
     * - Ngược lại:
     *     => giữ nguyên tên field
     *
     * Sau đó Dumper::export() sẽ lo phần export ra literal PHP.
     *
     * @param array $fields
     *
     * @return string
     */
    private function parametrize($fields = [])
    {
        return (string) implode(', ', array_map(function ($field) {
            $field = $this->reference->usesPropertyConstants()
                ? $this->pivot->getQualifiedUserClassName() . '::' . strtoupper($field)
                : $field;

            return Dumper::export($field);
        }, $fields));
    }
}
