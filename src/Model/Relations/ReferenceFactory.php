<?php

namespace Connecttech\AutoRenderModels\Model\Relations;

use Illuminate\Support\Str;

/**
 * Class ReferenceFactory
 *
 * Nhiệm vụ:
 * - Từ một cấu trúc "related" (blueprint + model + reference) và parent model,
 *   factory này sẽ quyết định:
 *   - Sinh ra quan hệ BelongsToMany (nếu phát hiện bảng pivot phù hợp)
 *   - Hoặc sinh ra HasOne/HasMany (thông qua HasOneOrManyStrategy)
 *
 * Cách hoạt động:
 * - hasPivot():
 *     + Kiểm tra xem bảng của related blueprint có thể là pivot hay không
 *       (tên bảng chứa record name của parent + của model target khác)
 *     + Nếu đúng, build danh sách $this->references để tạo BelongsToMany
 * - make():
 *     + Nếu có pivot => trả về mảng các BelongsToMany
 *     + Nếu không => trả về mảng với 1 HasOneOrManyStrategy
 */
class ReferenceFactory
{
    /**
     * Thông tin liên quan đến bảng/quan hệ được tham chiếu.
     *
     * Cấu trúc thường gồm:
     * - 'reference'  => Fluent command (foreign key definition)
     * - 'model'      => Model liên quan
     * - 'blueprint'  => Blueprint của bảng liên quan
     *
     * @var array
     */
    protected $related;

    /**
     * Model cha (nơi sẽ gắn các quan hệ được generate).
     *
     * @var \Connecttech\AutoRenderModels\Model\Model
     */
    protected $parent;

    /**
     * Danh sách các reference đã xác định là quan hệ many-to-many qua pivot.
     *
     * Mỗi phần tử có dạng:
     *  [
     *      'command' => \Illuminate\Support\Fluent, // thông tin FK từ pivot -> target
     *      'model'   => \Connecttech\AutoRenderModels\Model\Model, // model target
     *  ]
     *
     * @var \Connecttech\AutoRenderModels\Model\Model[]|array
     */
    protected $references = [];

    /**
     * ReferenceFactory constructor.
     *
     * @param array                                             $related Cấu trúc thông tin liên quan (reference, model, blueprint).
     * @param \Connecttech\AutoRenderModels\Model\Model         $parent  Model cha (đang generate quan hệ).
     */
    public function __construct($related, $parent)
    {
        // Ép về array để chắc chắn có thể truy cập bằng key
        $this->related = (array) $related;
        $this->parent = $parent;
    }

    /**
     * Tạo danh sách quan hệ dựa trên thông tin đã truyền vào.
     *
     * - Nếu phát hiện có pivot (hasPivot() == true):
     *     => tạo nhiều BelongsToMany (mỗi reference là một quan hệ many-to-many)
     * - Nếu không có pivot:
     *     => chỉ trả về 1 HasOneOrManyStrategy (HasOne hoặc HasMany)
     *
     * @return \Connecttech\AutoRenderModels\Model\Relation[]
     */
    public function make()
    {
        if ($this->hasPivot()) {
            $relations = [];

            foreach ($this->references as $reference) {
                $relation = new BelongsToMany(
                    $this->getRelatedReference(),
                    $reference['command'],
                    $this->parent,
                    $this->getRelatedModel(),
                    $reference['model']
                );

                $relations[$relation->name()] = $relation;
            }

            return $relations;
        }

        // Không có pivot: quan hệ kiểu 1-1 hoặc 1-n
        return [
            new HasOneOrManyStrategy(
                $this->getRelatedReference(),
                $this->parent,
                $this->getRelatedModel()
            ),
        ];
    }

    /**
     * Kiểm tra xem bảng current related có thể được coi là pivot table hay không.
     *
     * Heuristic:
     * - Lấy tên bảng pivot = relatedBlueprint->table()
     * - Lấy record name của parent (snake_case)
     * - Nếu tên bảng pivot KHÔNG chứa record name của parent => không phải pivot
     * - Nếu có:
     *     + Remove phần tên parent khỏi pivot name một lần
     *     + Duyệt qua tất cả relations trên related blueprint
     *     + Với mỗi relation khác với current reference:
     *         * Tạo target model từ relation đó
     *         * Nếu phần còn lại của pivot name chứa record name của target
     *             => coi như pivot giữa parent và target, thêm vào $this->references
     *
     * @return bool
     */
    protected function hasPivot()
    {
        $pivot = $this->getRelatedBlueprint()->table();
        $firstRecord = $this->parent->getRecordName();

        // Xem tên bảng tiềm năng pivot có chứa tên record của parent không
        if (! Str::contains($pivot, $firstRecord)) {
            return false;
        }

        // Bỏ đi phần tên parent trong tên bảng pivot (1 lần)
        $pivot = preg_replace("!$firstRecord!", '', $pivot, 1);

        foreach ($this->getRelatedBlueprint()->relations() as $reference) {
            // Bỏ qua relation hiện tại (đã được truyền vào $related)
            if ($reference == $this->getRelatedReference()) {
                continue;
            }

            // Tạo model target từ relation còn lại
            $target = $this->getRelatedModel()->makeRelationModel($reference);

            // Nếu tên bảng pivot chứa record name của target
            // => rất có thể đây là pivot giữa parent và target
            if (Str::contains($pivot, $target->getRecordName())) {
                $this->references[] = [
                    'command' => $reference,
                    'model'   => $target,
                ];
            }
        }

        return count($this->references) > 0;
    }

    /**
     * Lấy Fluent reference chính (được truyền vào từ $related).
     *
     * @return \Illuminate\Support\Fluent
     */
    protected function getRelatedReference()
    {
        return $this->related['reference'];
    }

    /**
     * Lấy model liên quan chính từ $related.
     *
     * @return \Connecttech\AutoRenderModels\Model\Model
     */
    protected function getRelatedModel()
    {
        return $this->related['model'];
    }

    /**
     * Lấy blueprint của model liên quan.
     *
     * @return \Connecttech\AutoRenderModels\Meta\Blueprint
     */
    protected function getRelatedBlueprint()
    {
        return $this->related['blueprint'];
    }
}
