<?php

namespace Connecttech\AutoRenderModels\Database\Eloquent;

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model as Eloquent;

/**
 * Class WhoDidIt
 *
 * Observer dùng để tự động ghi nhận "ai là người thực hiện" thao tác trên model,
 * thông qua các field:
 *  - created_by : khi tạo mới (creating)
 *  - updated_by : khi cập nhật (updating)
 *
 * Cách sử dụng:
 * - Thường được gắn thông qua trait BlamableBehavior:
 *      trait BlamableBehavior {
 *          public static function bootBlamableBehavior()
 *          {
 *              static::observe(WhoDidIt::class);
 *          }
 *      }
 *
 * - Model dùng trait BlamableBehavior:
 *      class Post extends \Illuminate\Database\Eloquent\Model {
 *          use BlamableBehavior;
 *      }
 *
 * Hành vi:
 * - Nếu đang chạy trong console (artisan, job, seeder...):
 *      => gán 'CLI' vào created_by / updated_by
 * - Nếu có user đang đăng nhập:
 *      => gán id của user đó
 * - Nếu không xác định được:
 *      => gán '????'
 */
class WhoDidIt
{
    /**
     * Current HTTP request, dùng để lấy thông tin user đang đăng nhập.
     *
     * @var \Illuminate\Http\Request
     */
    protected $request;

    /**
     * WhoDidIt constructor.
     *
     * @param \Illuminate\Http\Request $request Request hiện tại, được container inject.
     */
    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /**
     * Event "creating" của Eloquent.
     *
     * Được gọi tự động trước khi model được tạo.
     * Tại đây, ta gán trường created_by bằng "doer" hiện tại.
     *
     * @param \Illuminate\Database\Eloquent\Model $model Model đang được tạo.
     *
     * @return void
     */
    public function creating(Eloquent $model)
    {
        $model->created_by = $this->doer();
    }

    /**
     * Event "updating" của Eloquent.
     *
     * Được gọi tự động trước khi model được update.
     * Tại đây, ta gán trường updated_by bằng "doer" hiện tại.
     *
     * @param \Illuminate\Database\Eloquent\Model $model Model đang được cập nhật.
     *
     * @return void
     */
    public function updating(Eloquent $model)
    {
        $model->updated_by = $this->doer();
    }

    /**
     * Xác định "ai" là người thực hiện hành động hiện tại.
     *
     * Ưu tiên:
     *  - Nếu chạy trong console (php artisan, queue worker, seeder...):
     *      => trả về chuỗi 'CLI'
     *  - Nếu có user đang đăng nhập trong request:
     *      => trả về id user
     *  - Nếu không:
     *      => trả về chuỗi '????'
     *
     * @return mixed|string ID user hiện tại, 'CLI' hoặc '????'
     */
    protected function doer()
    {
        if (app()->runningInConsole()) {
            return 'CLI';
        }

        return $this->authenticated() ? $this->userId() : '????';
    }

    /**
     * Kiểm tra và lấy user hiện tại nếu đã đăng nhập.
     *
     * @return mixed|null User hiện tại hoặc null nếu chưa đăng nhập.
     */
    protected function authenticated()
    {
        return $this->request->user();
    }

    /**
     * Lấy ID của user hiện tại.
     *
     * Lưu ý: method này giả định đã có user (authenticated() không null).
     *
     * @return mixed ID của user hiện tại.
     */
    protected function userId()
    {
        return $this->authenticated()->id;
    }
}
