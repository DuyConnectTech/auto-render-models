<?php

namespace Connecttech\AutoRenderModels\Model;

use ArrayIterator;
use IteratorAggregate;
use Illuminate\Support\Arr;

/**
 * Class ModelManager
 *
 * Quản lý cache các instance Model đã được tạo ra từ Factory.
 *
 * Nhiệm vụ chính:
 * - Tạo Model mới từ schema + table nếu chưa tồn tại.
 * - Cache Model theo schema/table để tái sử dụng (tránh build lại nhiều lần).
 * - Cung cấp iterator để duyệt qua tất cả các model đã được cache.
 */
class ModelManager implements IteratorAggregate
{
    /**
     * Factory dùng để tạo Model và Schema.
     *
     * @var \Connecttech\AutoRenderModels\Model\Factory
     */
    protected $factory;

    /**
     * Cache các Model đã tạo, được index theo [schema][table] => Model.
     *
     * @var \Connecttech\AutoRenderModels\Model\Model[][]
     */
    protected $models = [];

    /**
     * ModelManager constructor.
     *
     * @param \Connecttech\AutoRenderModels\Model\Factory $factory
     */
    public function __construct(Factory $factory)
    {
        $this->factory = $factory;
    }

    /**
     * Tạo hoặc lấy Model tương ứng với schema + table.
     *
     * Cơ chế:
     *  - Dùng Factory để lấy Schema (SchemaManager::make)
     *  - Lấy Blueprint cho table tương ứng
     *  - Nếu Model đã tồn tại trong cache ($models) và được build kèm relations:
     *      + trả về Model từ cache
     *  - Nếu chưa:
     *      + tạo Model mới từ Blueprint
     *      + nếu $withRelations = true, cache lại để dùng cho lần sau
     *
     * @param string                                            $schema        Tên schema (database / namespace logic).
     * @param string                                            $table         Tên bảng.
     * @param \Connecttech\AutoRenderModels\Model\Mutator[]     $mutators      Danh sách mutator áp dụng cho Model.
     * @param bool                                              $withRelations Có load relations trong Model hay không.
     *
     * @return \Connecttech\AutoRenderModels\Model\Model
     */
    public function make($schema, $table, $mutators = [], $withRelations = true)
    {
        // Lấy Schema mapper cho schema tương ứng
        $mapper = $this->factory->makeSchema($schema);

        // Blueprint cho table hiện tại
        $blueprint = $mapper->table($table);

        // Nếu đã từng build Model này và nó đang nằm trong cache thì dùng lại
        if (Arr::has($this->models, $blueprint->qualifiedTable())) {
            // Lưu trong cấu trúc $this->models[$schema][$table]
            return $this->models[$schema][$table];
        }

        // Chưa có trong cache -> tạo Model mới
        $model = new Model($blueprint, $this->factory, $mutators, $withRelations);

        // Chỉ cache khi build full (có relations).
        if ($withRelations) {
            $this->models[$schema][$table] = $model;
        }

        return $model;
    }

    /**
     * Trả về iterator cho tất cả các Model đã được cache.
     *
     * Cho phép foreach($modelManager as $schema => $tables) {...}
     *
     * @return \ArrayIterator
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->models);
    }
}
