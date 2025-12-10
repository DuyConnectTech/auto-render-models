# Auto Render Models 🚀

> **Code chay làm gì cho mệt? Để tool nó gánh còng lưng!**

Chào anh em, đây là gói **Auto Render Models** - trợ thủ đắc lực cho các đồng đạo Laravel. Nó giúp anh em tự động hóa việc tạo Eloquent Model từ database schema. Thay vì ngồi gõ từng dòng `fillable`, `casts`, hay khai báo relationship mỏi tay, thì chạy lệnh một phát là xong. Xịn sò chưa? 😎

## Tại sao nên dùng? 🤔

*   **Tiết kiệm thời gian:** Quên chuyện copy-paste model cũ đi.
*   **Chuẩn chỉ:** Tự động detect các cột, kiểu dữ liệu, khóa ngoại (foreign keys) để tạo relationship (`belongsTo`, `hasMany`...) chuẩn không cần chỉnh.
*   **Dễ tùy biến:** Muốn model nằm ở đâu, namespace gì, base class nào... chỉnh trong config là được hết.
*   **Hỗ trợ tận răng:** Soft deletes, timestamps, casting JSON, Bit Booleans (cho mấy ông dùng MySQL bit)... cân tất.

## Yêu cầu 🛠️

*   PHP >= 8.2
*   Laravel 10.x, 11.x, 12.x
*   Đam mê sự lười biếng (thông minh) 🤣

## Cài đặt imstall 📦

Chạy lệnh này trong terminal của dự án Laravel nhé:

```bash
composer require connecttech/auto-render-models --dev
```

*(Nên để `--dev` vì thường mình chỉ render model lúc dev thôi, lên production thì code có sẵn rồi)*

## Cấu hình ⚙️

Sau khi cài xong, anh em cần publish file config ra để tùy chỉnh theo ý thích:

```bash
php artisan vendor:publish --provider="Connecttech\AutoRenderModels\Providers\AutoRenderModelsServiceProvider"
```

File config sẽ nằm ở `config/models.php`. Vào đó anh em có thể chỉnh:
*   `path`: Đường dẫn lưu model (mặc định `app/Models`).
*   `namespace`: Namespace của model.
*   `base_files`: Nếu bật `true`, nó sẽ tạo class Base để anh em thoải mái override mà không sợ mất code khi chạy lại lệnh.
*   `except`: Loại bỏ các bảng không muốn tạo model (như `migrations`, `failed_jobs`...).

## Sử dụng Run 🏃‍♂️

Dễ như ăn kẹo. Mở terminal lên và quất:

### 1. Render toàn bộ database (Mặc định)
```bash
php artisan auto-render:models
```
Lệnh này sẽ quét connection mặc định và tạo model cho tất cả các bảng.

### 2. Chỉ định connection hoặc schema cụ thể
```bash
php artisan auto-render:models --connection=mysql_custom
# Hoặc
php artisan auto-render:models --schema=shop_db
```

### 3. Render một bảng cụ thể
Chỉ muốn tạo lại model cho bảng `users` thôi thì làm thế này:
```bash
php artisan auto-render:models --table=users
```

## Tính năng nổi bật 🔥

*   ✅ **Auto-Detect Relationships:** Tự nhận diện khóa ngoại để build hàm quan hệ.
*   ✅ **Smart Casting:** Tự động cast các cột `*_json` sang array/json.
*   ✅ **Clean Code:** Code sinh ra sạch đẹp, chuẩn PSR.
*   ✅ **Base Model Pattern:** Hỗ trợ tách biệt code sinh tự động và code logic custom (nếu bật option `base_files`).

## Đóng góp (Contribution) 🤝

Anh em thấy lỗi hay muốn thêm tính năng gì thì cứ tự nhiên:
1.  Fork repo này về.
2.  Tạo branch mới (`git checkout -b feature/tinh-nang-xin`).
3.  Code và Commit (`git commit -m 'Thêm tính năng xịn'`).
4.  Push lên (`git push origin feature/tinh-nang-xin`).
5.  Tạo Pull Request.

Đừng ngại, mình rất welcome mọi đóng góp! 

## License ®️

Dự án này được phát hành dưới giấy phép [MIT](LICENCE.md). Dùng thoải mái đi nhé!

---
Made with ❤️ by [ConnectTech](https://connecttech.vn/) & [devcontainerDuy](mailto:khanhduytran1803@gmail.com).
