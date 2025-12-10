<?php

namespace Connecttech\AutoRenderModels\Model\Relations;

use Illuminate\Support\Str;

/**
 * Class RelationHelper
 *
 * General utility functions for dealing with relationship names.
 *
 * Hiện tại chủ yếu dùng để chuẩn hoá tên quan hệ dựa trên foreign key,
 * ví dụ:
 *  - "manager_id"  => "manager"
 *  - "line_manager_id" => "line_manager"
 *  - "lineManagerId"   => "lineManager"
 *
 * Từ đó, các strategy như HasOneOrManyStrategy / HasMany sẽ dùng kết quả này
 * để generate tên method quan hệ (user, posts, ordersWhereStatus, ...).
 */
class RelationHelper
{
    /**
     * Loại bỏ hậu tố liên quan đến primary key khỏi foreign key để lấy base name.
     *
     * Ví dụ:
     *  - usesSnakeAttributes = true
     *      + primaryKey  = "id"
     *      + foreignKey  = "manager_id"        => "manager"
     *      + foreignKey  = "line_manager_id"   => "line_manager"
     *
     *  - usesSnakeAttributes = false
     *      + primaryKey  = "id"
     *      + foreignKey  = "managerId"         => "manager"
     *      + primaryKey  = "userId"
     *      + foreignKey  = "lineManagerUserId" => "lineManager"
     *
     * Cơ chế:
     * - Nếu dùng snake attributes:
     *      + build regex với "_{primaryKey}" hoặc "_{lowerPrimaryKey}" ở cuối
     *      + ví dụ: /_(id|ID)$/
     *      + thay bằng '' để bỏ hậu tố
     * - Nếu không dùng snake:
     *      + build regex với "{primaryKey}" hoặc "{StudlyPrimaryKey}" ở cuối
     *      + ví dụ: /(id|Id|ID)$/ tuỳ cấu hình
     *
     * @param bool   $usesSnakeAttributes  Có dùng snake_case cho attribute không.
     * @param string $primaryKey           Tên primary key (vd: 'id', 'user_id', 'UserId'...).
     * @param string $foreignKey           Tên foreign key (vd: 'manager_id', 'userId',...).
     *
     * @return string                      Foreign key đã được loại bỏ hậu tố primary key.
     */
    public static function stripSuffixFromForeignKey($usesSnakeAttributes, $primaryKey, $foreignKey)
    {
        if ($usesSnakeAttributes) {
            // Ví dụ: primaryKey = "id" => pattern match "_id" hoặc "_ID" ở cuối
            $lowerPrimaryKey = strtolower($primaryKey);

            return preg_replace(
                '/(_)(' . $primaryKey . '|' . $lowerPrimaryKey . ')$/',
                '',
                $foreignKey
            );
        }

        // Không dùng snake => xử lý theo kiểu camel/studly
        // primaryKey = "id" => match "id" hoặc "Id" ở cuối
        $studlyPrimaryKey = Str::studly($primaryKey);

        return preg_replace(
            '/(' . $primaryKey . '|' . $studlyPrimaryKey . ')$/',
            '',
            $foreignKey
        );
    }
}
