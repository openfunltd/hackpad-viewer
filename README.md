# hackpad-viewer

hackpad.tw 唯讀備份瀏覽器，以 PHP 重新實作，直接讀取原始 hackpad MySQL 資料庫。

## 簡介

hackpad.tw 是 2015–2018 年間台灣公民社會廣泛使用的共筆平台，承載了 g0v、各社群與個人工作區大量的協作文件。本專案以 PHP 實作唯讀瀏覽器，讓大家可以繼續查閱這批歷史文件，取代原本已停止維護的 Node.js / Java 服務。

## 功能

- **文章瀏覽**：完整渲染 Easysync2 格式的 pad 內容（含標題、段落、列表、超連結、圖片）
- **作者標注**：每行段落左側顯示原作者縮寫，comments 顯示發言者姓名與顏色
- **歷史紀錄**：查看每篇文章的編修歷史，以 diff 呈現各 session 的增刪內容
- **Collections / Members 側邊欄**：右側顯示工作區成員與文件分類
- **分頁**：文章列表支援分頁瀏覽
- **Google OAuth 登入**：以原 hackpad 帳號的 Google 帳號登入
- **工作區權限控管**：依 `publicDomain` 設定，私有工作區需登入且須為成員才可瀏覽
- **公開 pad 例外**：即便工作區為私有，`guestPolicy=allow/link` 的文章仍可不登入瀏覽
- **Admin 後台**：站台管理員可查看全站工作區清單與使用者列表
- **多工作區支援**：subdomain routing（`ronnywang.hackpad.tw` 或測試環境的 `ronnywang-hackpad.example.com`）

## 技術架構

| 層級 | 說明 |
|------|------|
| Web framework | [mini-engine](mini-engine.php)（輕量 PHP MVC） |
| 資料庫 | MySQL（原始 hackpad DB，直接讀取） |
| 認證 | Google OAuth2 + session cookie |
| 內容渲染 | 自製 Easysync2 parser（`libraries/Easysync.php`） |
| Pad 載入 | `libraries/PadContentLoader.php` |

## 安裝與設定

### 需求

- PHP 8.1+
- MySQL（含原始 hackpad 資料庫）
- Web server（Apache / Nginx）

### 設定

複製設定檔並填入環境變數：

```bash
cp config.sample.inc.php config.inc.php
```

`config.inc.php` 必要設定：

```php
putenv('DATABASE_URL=mysql://user:pass@host/dbname');
putenv('SESSION_SECRET=your-random-secret');
putenv('SESSION_DOMAIN=.hackpad.tw');          // cookie 有效的 domain
putenv('HACKPAD_PRIMARY_DOMAIN=.hackpad.tw');  // subdomain 分隔符 + 主網域
putenv('GOOGLE_CLIENT_ID=...');
putenv('GOOGLE_CLIENT_SECRET=...');
```

`HACKPAD_PRIMARY_DOMAIN` 格式說明：
- 正式環境（dot subdomain）：`.hackpad.tw`
- 測試環境（dash subdomain）：`-hackpad.example.com`

### Google OAuth 設定

在 [Google Cloud Console](https://console.cloud.google.com/) 建立 OAuth 2.0 用戶端，並將以下網址加入「已授權的重新導向 URI」：

```
https://hackpad.tw/ep/account/openid
```

### Web server 設定

將所有請求導向 `index.php`。Nginx 範例：

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

## 目錄結構

```
hackpad-viewer/
├── controllers/        # MVC controllers
├── libraries/          # 核心函式庫（HackpadHelper, PadContentLoader, Easysync, GoogleOAuth）
├── models/
├── views/              # PHP 模板
│   ├── layout/
│   ├── pad/
│   ├── index/
│   ├── admin/
│   ├── collection/
│   └── profile/
├── static/             # CSS / 靜態檔
├── cache/              # 渲染快取（自動建立）
├── mini-engine.php     # Web framework
├── index.php           # Router
├── init.inc.php        # Bootstrap
└── config.inc.php      # 本地設定（不納入版控）
```

## 維運

本站目前由 [歐噴有限公司 openfun.tw](https://openfun.tw) 維運。

## License

BSD 3-Clause License — Copyright (c) 2026, openfun.tw 歐噴有限公司。詳見 [LICENSE](LICENSE)。
