<?php

namespace Connecttech\AutoRenderModels\Database\Eloquent;

/**
 * Trait BlamableBehavior
 *
 * Thêm "blameable" behavior cho Eloquent Model – tức là tự động gán
 * thông tin "ai tạo / ai cập nhật" (created_by, updated_by, ...) thông qua observer.
 *
 * Cách hoạt động:
 * - Khi trait này được use trong một Model:
 *      class Post extends Model {
 *          use BlamableBehavior;
 *      }
 *
 * - Laravel sẽ tự động gọi method bootBlamableBehavior() khi boot model,
 *   và observer WhoDidIt sẽ được gắn vào model đó.
 *
 * - Lớp WhoDidIt (observer) sẽ xử lý các event như creating, updating...
 *   để set các field tương ứng (ví dụ: created_by, updated_by).
 */
trait BlamableBehavior
{
    /**
     * Boot BlamableBehavior trait cho model.
     *
     * Hàm boot{TraitName} là convention của Laravel:
     * - Được gọi tự động khi Model boot.
     * - Ở đây ta đăng ký observer WhoDidIt cho tất cả các event của Model.
     *
     * @return void
     */
    public static function bootBlamableBehavior()
    {
        // Gắn observer WhoDidIt cho model sử dụng trait này.
        static::observe(WhoDidIt::class);
    }
}
