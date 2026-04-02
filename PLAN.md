# Hackpad Viewer — 專案規格與開發計畫

## 專案背景

hackpad.tw 是一個基於 hackpad.com（2012 年開發的共筆工具，後被 Dropbox 收購停止營運）的自架服務。原始程式碼 fork 自 [middle2tw/whackpad](https://github.com/middle2tw/whackpad)，是一個 Java/Node.js 應用。

因為 SPAM 入侵，幾年前已改為**唯讀模式**。近期因 AI 爬蟲導致主機負載過高，決定用 PHP 重寫一個輕量級唯讀 viewer 來取代原本的 Node.js 服務。

---

## 需求規格

1. **Subdomain routing**：`*.hackpad.tw` 每個子網域對應不同的 workspace（`pro_domains` 表）
2. **Google 登入**：使用 Google OAuth2，維持原有的權限機制（登入後查 `pro_accounts` 表比對 email）
3. **唯讀**：不需要實作編輯功能
4. **框架**：使用 [openfunltd/mini-engine](https://github.com/openfunltd/mini-engine)（PHP 輕量 MVC）
5. **URL 規則**：盡量維持原有格式（見下方 URL 規則）
6. **資料庫**：直接讀取原有 hackpad 的 MySQL 資料庫

---

## 技術架構

### 框架
- [mini-engine](https://github.com/openfunltd/mini-engine)：單一 `mini-engine.php` 檔案的 PHP MVC 框架
- Session 以 HMAC-SHA256 簽名的 cookie 實作（`SESSION_SECRET` 環境變數）
- 資料庫透過 `DATABASE_URL` 環境變數設定（格式：`mysql://user:pass@host/dbname`）

### 環境設定（`config.inc.php`，不進 git）
```php
<?php
putenv('DATABASE_URL=mysql://user:pass@host/dbname');
putenv('SESSION_SECRET=random_secret_here');
putenv('SESSION_DOMAIN=.hackpad.tw');       // 跨子網域共享 session
putenv('GOOGLE_CLIENT_ID=...');
putenv('GOOGLE_CLIENT_SECRET=...');
putenv('HACKPAD_PRIMARY_DOMAIN=hackpad.tw');
putenv('ENV=production');
```

---

## MySQL 資料庫結構（重要表格）

原始 hackpad 使用 MySQL，以下是 viewer 需要用到的表：

### `pro_domains` — workspace 對應表
```
id          INT   主鍵，domainId
subDomain   VARCHAR(128)  子網域名稱（如 "g0v"）
orgName     VARCHAR(128)  顯示名稱
isDeleted   TINYINT
```

### `pro_accounts` — 使用者帳號
```
id          INT
domainId    INT
email       VARCHAR(128)
fullName    VARCHAR(128)
isAdmin     TINYINT
isDeleted   TINYINT
```

### `pro_padmeta` — Pad 元資料
```
id              INT
domainId        INT
localPadId      VARCHAR(128)  pad 的短 ID（如 "AbCdE12345f"）
title           VARCHAR(128)
creatorId       INT
createdDate     DATETIME
lastEditedDate  DATETIME
isDeleted       TINYINT
isArchived      TINYINT
proAttrsJson    MEDIUMTEXT    JSON，含 visibility、editors 等
```

### `PAD_SQLMETA` — Pad 存取控制
```
id            VARCHAR(128)  globalPadId（如 "5$AbCdE12345f"）
guestPolicy   VARCHAR(20)   'allow' | 'link' | 'domain' | 'deny'
headRev       INT           最新 revision 號碼
```

### `pad_access` — 明確授權記錄
```
globalPadId   VARCHAR(128)
userId        INT
groupId       INT
token         VARCHAR(20)
isRevoked     TINYINT
```

### Pad 內容儲存（Easysync2 格式）

Pad 的實際內容以 [Easysync2 changeset](https://github.com/ether/etherpad-lite/blob/develop/doc/easysync/easysync-full-description.pdf) 格式儲存：

- **`PAD_META`**：每個 pad 的 JSON 元資料，含 `head`（最新 rev 號）、`keyRevInterval`（= 100）
- **`PAD_REVMETA_META` / `PAD_REVMETA_TEXT`**：每個 revision 的元資料。每隔 `keyRevInterval`（100）個 revision，儲存一個完整的 `atext`（attributed text）快照
- **`PAD_REVS_META` / `PAD_REVS_TEXT`**：每個 revision 的 changeset 字串（以 offset 陣列 + 拼接字串格式儲存）
- **`PAD_APOOL`**：每個 pad 的 attribute pool（JSON），將屬性編號對應到 `[key, value]`

**全域 Pad ID 格式**：`{domainId}${localPadId}`，例如 `5$AbCdE12345f`

**取得最終內容的演算法**：
1. 從 `PAD_META` 取得 `headRev` 和 `keyRevInterval`
2. 計算最後一個 key revision：`lastKeyRev = floor(headRev / 100) * 100`
3. 從 `PAD_REVMETA_TEXT` 取得 `lastKeyRev` 的完整 atext（含 `text` 和 `attribs` 字串）
4. 從 `PAD_REVS_TEXT` 取得 `lastKeyRev+1` 到 `headRev` 的剩餘 changesets（最多 99 個）
5. 將每個 changeset 套用到 atext（PHP 版 Easysync2 實作）
6. 用 `PAD_APOOL` 的屬性定義將 atext 轉換為 HTML

**`PAD_REVS_TEXT` 的資料格式**：
- `PAGESTART`：該列起始的 revision 號（每列 20 個 revision）
- `OFFSETS`：逗號分隔的每個 changeset 的**位元組長度**
- `DATA`：所有 changeset 字串拼接

---

## URL 規則

| URL | 對應 |
|-----|------|
| `/` | 該 workspace 的 pad 列表 |
| `/{localPadId}` | 查看 pad（例如 `/AbCdE12345f`） |
| `/{Title-With-Dashes-localPadId}` | 查看 pad（pretty URL，例如 `/MapTile-筆記-AbCdE12345f`） |
| `/ep/account/sign-in` | 登入頁面 |
| `/ep/account/sign-out` | 登出 |
| `/ep/account/openid` | Google OAuth2 callback（固定在主網域 hackpad.tw） |
| `/ep/account/google-login` | 觸發 Google 登入流程 |
| `/robots.txt` | 封鎖爬蟲 |

**pad ID 萃取邏輯**：原始 hackpad 的 pad ID 為 11 碼英數字串。pretty URL 格式是 `{任意文字}-{11碼ID}`，取最後一個 `-` 後的 11 碼。

---

## 權限模型

| `guestPolicy` | 未登入 | 已登入（domain 成員） |
|---------------|--------|----------------------|
| `allow`       | ✅ 可讀 | ✅ 可讀 |
| `link`        | ✅ 可讀 | ✅ 可讀 |
| `domain`      | ❌ 需登入 | ✅ 可讀 |
| `deny`        | ❌ | ✅（需在 `pad_access` 有記錄） |

---

## Google OAuth2 流程

1. 使用者點「Google 登入」→ 導向 `/ep/account/google-login`
2. 生成 state（含 CSRF nonce + return_to URL），存入 session
3. 導向 Google 授權頁：`https://accounts.google.com/o/oauth2/auth`
4. Google callback 回到 **主網域** `https://hackpad.tw/ep/account/openid`（Google Console 只需設定一個 redirect URI）
5. 驗證 state，交換 code 取得 access token
6. 呼叫 `https://www.googleapis.com/oauth2/v3/userinfo` 取得 email
7. 在 `pro_accounts` 查詢該 email
8. 若找到：設定 session（`user_id`, `user_email`, `user_name`），導回 return_to
9. 若找不到：顯示錯誤（此 viewer 不允許新帳號註冊）

**Session 跨子網域**：`SESSION_DOMAIN=.hackpad.tw`，讓 cookie 在所有子網域共用

---

## 專案檔案結構

```
hackpad-viewer/
├── mini-engine.php              # MVC 框架核心（單一檔案）
├── init.inc.php                 # 初始化，載入設定、設 include_path
├── config.inc.php               # 環境設定（不進 git，含 DB 密碼等）
├── config.sample.inc.php        # 設定範本
├── index.php                    # 入口，自訂路由
├── .htaccess                    # Apache rewrite
├── PLAN.md                      # 本文件
│
├── controllers/
│   ├── IndexController.php      # 首頁（pad 列表）
│   ├── PadController.php        # Pad 閱讀頁
│   ├── EpController.php         # /ep/* 路由（登入/登出/OAuth callback）
│   └── ErrorController.php      # 錯誤處理
│
├── libraries/
│   ├── Easysync.php             # Easysync2 changeset 引擎（PHP 移植）
│   ├── PadContentLoader.php     # 從 MySQL 載入並渲染 pad 為 HTML
│   ├── HackpadHelper.php        # 工具函式（subdomain、權限、使用者查詢）
│   └── GoogleOAuth.php          # Google OAuth2 流程
│
├── models/                      # （目前為空，直接用 PDO 查詢）
│
├── views/
│   ├── layout/app.php           # 主版型（header/footer/nav）
│   ├── index/index.php          # Pad 列表頁
│   ├── pad/show.php             # Pad 閱讀頁（待完成）
│   ├── ep/
│   │   └── account_sign_in.php  # 登入頁（待完成）
│   └── common/                  # 共用 partial
│
└── static/
    └── style.css                # 樣式（待完成）
```

---

## 目前進度（完成 / 待做）

> 最後更新：2026-04-02（17:37）

### ✅ 已完成

#### 核心框架 & 資料存取
- `mini-engine.php` 框架整合
- `libraries/Easysync.php`：完整的 Easysync2 changeset 解析、套用、atext→HTML 轉換；含 per-line author gutter、comment author 顯示
- `libraries/PadContentLoader.php`：從 MySQL key revision 載入 atext，套用剩餘 changesets，渲染 HTML；含歷史紀錄 diff（LCS 演算法、context collapse、1 小時內同人合併）；含 `getPadTextPreviews()`（批次取預覽文字，OFFSETS 以字元數計，需 mb_substr）
- `libraries/HackpadHelper.php`：subdomain 解析、domain 查詢、pad 權限檢查（email-based 跨 domain）、使用者查詢、getUserDomains、getDomainUrl、isSiteAdmin
- `libraries/GoogleOAuth.php`：Google OAuth2 完整流程（auth URL、code exchange、state 驗證）
- `libraries/Elastic.php`：Elasticsearch client（使用者提供）

#### 頁面 & 路由
- `controllers/IndexController.php`：首頁 pad 列表；主網域登入後顯示「我的 Pads」，未登入顯示歡迎說明頁；每篇 pad 顯示第 2–6 行預覽文字及創作者姓名
- `controllers/CollectionController.php`：Collection 文章列表；顯示預覽文字及創作者姓名
- `controllers/PadController.php`：Pad 閱讀頁（含權限檢查）+ `/{padSlug}/history` 歷史紀錄頁
- `controllers/EpController.php`：登入/登出/Google OAuth callback；EMAIL_ALIASES 支援；open redirect 防護
- `controllers/AdminController.php`：Site admin 面板（/admin/domains, /admin/users），僅 isAdmin=1 可存取
- `controllers/SearchController.php`：Elasticsearch 搜尋（/search?q=...），依 domainId + guestPolicy 過濾，分頁
- `controllers/ErrorController.php`：統一錯誤頁（404/500）

#### 存取控制
- `init.inc.php`：全域 auth gate（isDomainPublic → pad-level guestPolicy bypass → isEmailDomainMember）；pre-load `_userDomains`、`_isSiteAdmin` 全域變數
- 主網域 / 子網域 / 私有 domain / 公開 pad 的存取矩陣已全部實作並驗證

#### UI
- `views/layout/app.php`：主版型；header 搜尋框；user dropdown（workspace 清單 + admin 連結）；footer 含 openfun.tw 維運說明 + GitHub 開源連結
- `views/index/index.php`：pad 列表（含欄位排序、最後編輯日期、創作者姓名、預覽文字）；未登入主網域顯示歡迎頁
- `views/collection/show.php`：Collection 文章列表（含日期、創作者姓名、預覽文字）
- `views/pad/show.php`：pad 閱讀頁；per-line author gutter（白框外左側）
- `views/pad/history.php`：歷史紀錄頁；每個 session 的 diff（新增綠色/刪除紅色，預設全展開）
- `views/admin/`：index.php、domains.php、users.php
- `views/search/index.php`：搜尋結果頁（高亮標題 + 摘要）
- `static/style.css`：全站樣式
- `README.md`、`LICENSE`（BSD 3-Clause，openfun.tw 歐噴有限公司，2026）

#### Git commits（本 session）
| Commit | 說明 |
|--------|------|
| `cf4a7d1` | 文章列表（首頁 + Collection）顯示創作者姓名 |
| `6fbd523` | Collection 頁加入預覽文字 |
| `3176dff` | 預覽修正：略過標題行、顯示第 2–6 行；headRev<100 偵測範本並套用 changesets |
| `6eaa1cb` | 文章列表顯示預覽文字（批次 DB 查詢，mb_substr 修正）|
| `53042c4` | Footer 增加 GitHub 開源連結 |
| `9bd2222` | Elasticsearch 搜尋功能 |
| `39d7152` | Footer/README/歡迎頁補充 openfun.tw 維運說明 |
| `cc16556` | README + BSD License |
| `e5f03d8` | 歷史 diff；預設展開；1 小時 session 合併 |
| `e526dd4` | Pad 歷史紀錄頁 |
| `0a6c83b` | 主網域未登入顯示歡迎頁 |
| `7358e1f` | 主網域登入後顯示我的 Pads |
| `bb9f6e4` | 私有 domain 中公開 pad 不需登入 |
| `d60104e` | canReadPad 改用 email 避免跨 domain user_id 問題 |
| `14d588a` | 私有 domain 需驗證 domain 成員資格 |
| `6b45c49` | Site admin 面板 |
| `90c1f22` | User workspace dropdown |

### 🔲 待完成 / 已知問題

- **Collection 頁**：`/collection/{groupId}` 路由存在但 CollectionController 尚未完整實作
- **Profile 頁**：`/ep/profile/{id}` 尚未實作
- **Collection URL 原始格式**：原始 hackpad 用 `/ep/group/{token}`，token 不在 DB 中，待研究 whackpad source
- **Sidebar**：Members / Collections sidebar 目前僅部分子頁面有實作，尚未統一

---

## 注意事項

- **PHP 版本需求**：PHP 8.0+（使用 union types `string|false`、named arguments 等）；原機器只有 PHP 7.3，需在 PHP 8.3 的新機器繼續開發
- **Easysync2 字元計數**：使用 `mb_strlen`/`mb_substr` (UTF-8)；counts 以 Unicode code points 為單位（對應 JS 的 BMP 字元）
- **Google OAuth redirect_uri**：必須固定為 `https://hackpad.tw/ep/account/openid`（主網域），需在 Google Cloud Console 設定
- **Session domain**：設定 `SESSION_DOMAIN=.hackpad.tw` 讓 cookie 跨子網域共用
- **`pro_domains` 中 id=1 的 domain**：subDomain 為 `<<private-network>>`，是特殊的主站 domain，viewer 以空字串（不帶子網域的 hackpad.tw）對應之
