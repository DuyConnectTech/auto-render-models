<?php

namespace Connecttech\AutoRenderModels\Database\Eloquent;

/**
 * Class Model
 *
 * Custom Eloquent Model base class dùng cho các model được generate.
 *
 * Mục tiêu chính:
 * - Cho phép định nghĩa caster "tự custom" thông qua các method dạng:
 *      + from{CastType}($value)  => dùng khi GET attribute
 *      + to{CastType}($value)    => dùng khi SET attribute
 *
 * Ví dụ kết hợp với trait BitBooleans:
 *  - Trong $casts: ['is_active' => 'bool']
 *  - Định nghĩa trong model/trait:
 *      public function fromBool($value) { ... }
 *      public function toBool($value)   { ... }
 *
 * Lúc đó:
 *  - Khi đọc: castAttribute() sẽ gọi fromBool()
 *  - Khi ghi: setAttribute() sẽ gọi toBool()
 */
class Model extends \Illuminate\Database\Eloquent\Model
{
    /**
     * {@inheritdoc}
     *
     * Override logic cast attribute khi đọc ra từ model.
     *
     * Nếu tồn tại custom getter caster tương ứng với loại cast:
     *  - hasCustomGetCaster($key) => true
     *  - getCustomGetCaster($key) => 'from' . ucfirst(castType) (vd: fromBool, fromJson)
     *  => gọi method đó để xử lý giá trị.
     *
     * Nếu không có custom caster, fallback về behavior mặc định của Eloquent.
     *
     * @param string $key   Tên attribute.
     * @param mixed  $value Giá trị thô lấy từ DB.
     *
     * @return mixed
     */
    protected function castAttribute($key, $value)
    {
        if ($this->hasCustomGetCaster($key)) {
            return $this->{$this->getCustomGetCaster($key)}($value);
        }

        return parent::castAttribute($key, $value);
    }

    /**
     * Kiểm tra xem attribute này có custom getter caster hay không.
     *
     * Điều kiện:
     *  - Attribute có trong $casts (hasCast($key) == true)
     *  - Và tồn tại method trên model tên: "from" . ucfirst(castType)
     *      (vd: castType = 'bool' => method fromBool())
     *
     * @param string $key Tên attribute.
     *
     * @return bool
     */
    protected function hasCustomGetCaster($key)
    {
        return $this->hasCast($key) && method_exists($this, $this->getCustomGetCaster($key));
    }

    /**
     * Lấy tên method custom getter caster cho attribute.
     *
     * Công thức:
     *  - 'from' . ucfirst($this->getCastType($key))
     *
     * Ví dụ:
     *  - $casts['is_active'] = 'bool'  => fromBool
     *  - $casts['meta']      = 'array' => fromArray
     *
     * @param string $key Tên attribute.
     *
     * @return string
     */
    protected function getCustomGetCaster($key)
    {
        return 'from' . ucfirst($this->getCastType($key));
    }

    /**
     * {@inheritdoc}
     *
     * Override logic set attribute khi gán giá trị cho model.
     *
     * Nếu tồn tại custom setter caster tương ứng với loại cast:
     *  - hasCustomSetCaster($key) => true
     *  - getCustomSetCaster($key) => 'to' . ucfirst(castType) (vd: toBool, toJson)
     *  => dùng method đó để transform $value trước khi gọi parent::setAttribute().
     *
     * @param string $key   Tên attribute.
     * @param mixed  $value Giá trị được gán từ code.
     *
     * @return $this
     */
    public function setAttribute($key, $value)
    {
        if ($this->hasCustomSetCaster($key)) {
            $value = $this->{$this->getCustomSetCaster($key)}($value);
        }

        return parent::setAttribute($key, $value);
    }

    /**
     * Kiểm tra xem attribute này có custom setter caster hay không.
     *
     * Điều kiện:
     *  - Attribute có trong $casts
     *  - Và tồn tại method "to" . ucfirst(castType)
     *      (vd: castType = 'bool' => method toBool())
     *
     * @param string $key Tên attribute.
     *
     * @return bool
     */
    private function hasCustomSetCaster($key)
    {
        return $this->hasCast($key) && method_exists($this, $this->getCustomSetCaster($key));
    }

    /**
     * Lấy tên method custom setter caster cho attribute.
     *
     * Công thức:
     *  - 'to' . ucfirst($this->getCastType($key))
     *
     * Ví dụ:
     *  - $casts['is_active'] = 'bool'  => toBool
     *  - $casts['meta']      = 'array' => toArray
     *
     * @param string $key Tên attribute.
     *
     * @return string
     */
    private function getCustomSetCaster($key)
    {
        return 'to' . ucfirst($this->getCastType($key));
    }
}
