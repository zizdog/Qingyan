# 轻言 Qingyan for Typecho

给 Typecho 加一条会说话的时间线：像发朋友圈一样，随手记录一句话、几张图。

- **形态**：说说 / 微语时间线，支持 Markdown（列表、表格、代码块、引用）
- **接入**：独立路由页面（`/qingyan`），也可以整段嵌进主题，不依赖任何主题
- **数据**：独立数据表，不污染文章与评论

> 这份文档就是插件的使用说明。后台 → 插件 → 轻言 → 设置页顶部也指向这里。

---

## 安装

1. 下载插件，**解压后文件夹必须命名为 `Qingyan`**
2. 上传到 `usr/plugins/`，最终结构为 `usr/plugins/Qingyan/Plugin.php`
3. 后台 → 控制台 → 插件 → 启用「轻言」
4. 后台「管理 → 轻言」出现管理页，前台访问 `https://你的域名/qingyan`

> **文件夹名必须叫 `Qingyan`**（区分大小写）。后台菜单的注册路径写死了这个目录名，
> 改名会导致菜单 404，插件也认不出来。

---

## 发布与正文语法

后台 → 管理 → 轻言 → 填内容 → 发布。正文走 **Markdown**，解析用的是
**Typecho 自带的组件**（`Utils\HyperDown`，与文章、评论区同一套引擎），
所以文章里能用的写法这里基本都能用。

| 写法 | 效果 |
|---|---|
| `**粗体**` / `*斜体*` / `~~删除线~~` | 行内强调 |
| `` `代码` `` | 行内代码 |
| `[文字](https://…)`、直接写 `https://…` | 链接（自动补 `target` / `rel`） |
| `# 标题` | 一到六级标题（行首 `#`，**后面必须跟空格**） |
| `- 项目` / `1. 项目` | 无序 / 有序列表（缩进两格即嵌套） |
| `> 一行` | 引用块，可嵌套（`> > 一层`） |
| 三个反引号包起来 | 围栏代码块（开头可标语言，如 php） |
| 表头行 + 分隔行 | 表格（分隔行用 `---`、`---:` 右对齐、`:---:` 居中） |
| `---` | 水平线 |
| `#话题#` | 轻言扩展：高亮话题，**点一下跳到站内搜索** |
| `:smile:` | 轻言扩展：表情短代码 |

块级元素可以互相嵌套：引用里放列表、列表里放表格、引用里再引用都支持。
编辑器工具栏的「**引用**」按钮负责加/去 `> `：选中多行整段切换，没选中则切换当前行。

### 注意事项

- 图片不支持：`![图](url)` 与 `<img>` 都会被删掉（会顺手清掉留下的空行）。
- 任务列表（`- [ ]`）不支持，会原样显示。
- **裸 HTML 不生效**，会转义成文字显示；内嵌 HTML 块同理。
- 链接只允许 `http://` 与 `https://`，其它协议（`javascript:`、`data:`…）退化成纯文字。
- `#话题#` 的 `#` 后面**不能有空格** —— 有空格就是标题语法（`# 标题`）。
  行首的 `#话题#` 也能正常识别，不会被当成标题；超长（>24 字）不算话题。
- 代码块与行内代码里的 `#话题#` 保持字面量，不会被解析。
- 话题渲染成指向**站内搜索**的链接（`#旅行#` → `网站/?s=旅行`），与主题 header 里
  搜索框的目标一致。**不处理链接内部的话题**（`[#标签#](url)`）——
  `<a>` 里嵌 `<a>` 是非法 HTML。

**安全**：内容一律按不可信输入处理（评论允许游客提交）。解析时以「禁止裸 HTML」
模式跑 HyperDown，输出再按标签白名单过滤（`Model::allowedTags()`）：事件属性
（`onclick` 等）、`style`、`src`、`script` / `iframe` / `img` / `svg` 一律剥掉，
链接由插件自己重建。

---

## 前台展示：三种方式

| 方式 | 做法 | 适合 |
|---|---|---|
| **独立前台** | 启用后直接访问 `/qingyan` | 不想动主题 |
| **嵌入主题** | 主题模板里调 `render()` / `query()` | 想让时间线与博客风格统一 |
| **只要数据** | `renderList()` 或 `latest()` | 侧边栏挂件等简单场景 |

独立前台与嵌入模式**共用同一份样式和脚本**，发布框、点赞、评论、灯箱功能完全一致。

### 方式一：独立前台（零配置）

后台 → 管理 → 轻言 里的「前台展示页地址」留空，就使用插件自带的 `/qingyan`
（未开启伪静态时是 `/index.php/qingyan`）。分页地址为 `/qingyan/page/2`。

### 方式二：嵌入主题（推荐）

**① 复制模板**：把插件包里的 `theme-example.php` 复制到**当前主题目录**，
改名 `page-qingyan.php`（`usr/themes/你的主题/page-qingyan.php`）。

**② 建独立页面**：后台 → 管理 → 独立页面 → 新建，标题随意，
**别名填 `qingyan`**（决定 URL 与模板文件名匹配），内容留空，发布。

**③ 访问**：`https://你的域名/qingyan.html`。主题的 header / sidebar / footer
照常渲染，时间线出现在正文位置。

> 模板里的 `need('header.php')` 是通用写法，不同主题文件名可能不同（有的叫
> `head.php`），照着你主题 `page.php` 的结构改即可。
> **最省事的办法**：复制主题的 `page.php`，把输出正文那一行换成
> `<?php Qingyan_Plugin::render(); ?>`。

#### 在主题任意位置挂最新轻言

```php
<?php $list = Qingyan_Plugin::latest(5); ?>
<?php if (!empty($list)): ?>
<ul class="my-qingyan">
    <?php foreach ($list as $item): ?>
    <li>
        <a href="<?php echo Qingyan_Plugin::homeUrl(1) . '#qy-' . $item['id']; ?>">
            <?php echo Qingyan_Model::format($item['content']); ?>
        </a>
        <time><?php echo Qingyan_Model::timeAgo($item['created']); ?></time>
    </li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>

<!-- 入口链接 -->
<a href="<?php Qingyan_Plugin::homeUrl(); ?>">轻言</a>
```

### render() 参数

```php
<?php Qingyan_Plugin::render(array(
    'size'    => 10,     // 每页条数，默认取插件配置的「每页条数」
    'page'    => 1,      // 页码，默认取 URL 的 ?page=
    'editor'  => true,   // 是否显示发布框，默认自动（管理员可见）
    'css'     => true,   // 是否引入自带样式，想完全自己写样式传 false
    'baseUrl' => '',     // 分页基地址，默认当前页面
    'keyword' => '',     // 只显示匹配的内容（轻言搜索页用）
)); ?>
```

### query()：自己控制 HTML

想自己排版就用这个。**一个方法拿到全部东西**，输出结构与 `render()` **完全一致**
（两者共用同一段渲染代码，只是 `render()` 直接 echo）。

```php
<?php $qy = Qingyan_Plugin::query(array('size' => 10)); ?>

<?php echo $qy['css']; ?>
<div class="qy-embed" id="qy-embed">
    <?php echo $qy['editor']; ?>
    <div class="qy-timeline" id="qy-timeline">
        <?php if ($qy['empty']): ?>
            <?php echo $qy['emptyHtml']; ?>
        <?php else: ?>
            <?php foreach ($qy['list'] as $s): ?>
                <?php echo $s['itemHtml']; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php echo $qy['pager']; ?>
</div>
<?php echo $qy['js']; ?>
```

上面的 `itemHtml` 就是单条内容的完整 HTML。想改版式就把它拆开自己拼
（字段见下表），只要保留几个 `data-act` 属性即可，`class` 名随便换。

#### query() 返回的键

| 键 | 类型 | 说明 |
|---|---|---|
| `list` | array | 条目数组，见下表 |
| `css` / `js` | string | 样式 / 脚本片段，`js` **必须输出** |
| `assets` | string | `css` + `js` 合体，图省事用这个 |
| `editor` | string | 发布框 HTML；非管理员为空串 |
| `pager` | string | 分页 HTML |
| `emptyHtml` | string | 空状态 HTML |
| `pagerData` | array | 分页参数，自己写分页时用 |
| `canPost` / `isAdmin` | bool | 权限状态 |
| `page` / `pages` / `total` / `size` | int | 分页信息 |
| `empty` | bool | 是否没有内容 |

#### list 每项的字段

| 字段 | 说明 |
|---|---|
| `id` | 内容 ID |
| `author` / `mail` / `avatar` | 作者名 / 邮箱 / 头像地址（关闭头像时为空） |
| `raw` / `html` | 原始正文 / 格式化后的安全 HTML（可直接输出） |
| `created` / `ago` / `time` / `datetime` | 时间戳 / 相对时间 / `Y-m-d H:i` / ISO8601 |
| `likes` / `commentsNum` | 点赞数 / 评论数 |
| `liked` | 当前访客是否已赞 |
| `status` / `url` | 状态 / 锚点链接 |
| `comments` | 评论数组，每项含 `id` `author` `mail` `avatar` `html` `ago` `time` |
| `commentsHtml` | 现成的评论区 HTML（列表 + 表单，自带交互） |
| `itemHtml` | 整条内容的完整 HTML，与 `render()` 输出一致 |
| `likeOn` / `commentOn` / `isAdmin` | 开关与权限，便于模板判断 |

#### 改结构时不能删的东西

JS 靠这些属性做事件委托，删掉对应功能就失效：

| 属性 | 作用 |
|---|---|
| `data-act="like"` + `data-id` | 点赞 |
| `data-act="toggle-comments"` + `data-id` | 展开 / 收起评论 |
| `data-act="delete"` + `data-id` | 删除（管理员） |
| `id="qy-{id}"` | 删除后定位 DOM 节点 |

#### 自定义样式

1. **改 class 名** —— 模板里换成自己的命名，写对应 CSS
2. **覆盖样式**（推荐）—— 保留 `qy-` 前缀，在主题 CSS 里写同名选择器（放在插件 CSS 之后）
3. **彻底不用插件样式** —— `query(array('css' => false))`，完全自己写

```css
.qy-item { border-bottom-color: #eee; }
.qy-content { font-size: 16px; }
.qy-like.is-liked { color: #ff6b6b; }
```

### 方式三：只要数据

```php
<?php $list = Qingyan_Plugin::renderList(10); // 等价于 latest(10) ?>
```

返回**未格式化**的原始记录，字段：`id` `author` `authorMail` `content` `likes`
`commentsNum` `created` `status`。格式化用 `Qingyan_Model::format()`，
相对时间用 `Qingyan_Model::timeAgo()`。这种方式不含发布框、分页与交互。

---

## 配置项

后台 → 插件 → 轻言 → 设置。

| 配置 | 说明 |
|---|---|
| 接口路径 | 默认 `qingyan`。只能含字母、数字、中划线、下划线；不要与已有独立页面的别名重复 |
| 前台展示页地址 | 主题嵌入时填你的页面地址（如 `https://你的域名/says.html`）；留空则用自带独立前台 |
| 独立前台标题 | `/qingyan` 页面标题 |
| 表情短代码 / 每页条数 | 正文增强与列表条数 |
| 点赞 / 评论 / 评论权限 / 评论审核 | 互动开关与审核策略 |
| 评论间隔 | 同 IP 两次评论的最小间隔（秒），防刷 |
| 头像 / 头像镜像 | 默认使用 cravatar 国内镜像 |
| 自定义 CSS | 追加到页面底部，可覆盖默认样式 |
| 禁用插件时 | 保留数据 / 删除数据表 |

---

## 目录结构

```
usr/plugins/Qingyan/
├── Plugin.php              插件主文件：激活/卸载、配置面板、路由与菜单注册
├── Model.php               数据层：建表、CRUD、点赞、评论、上传、内容格式化
├── Front.php               独立前台页面（路由 /qingyan）
├── Api.php                 接口层：JSON API
├── panel.php               后台管理面板视图
├── compat.php              版本兼容层：1.2 / 1.3 类名与常量双向映射
├── Renderer.php            渲染器：整页模式与嵌入模式共用
├── theme-example.php       主题模板示例，复制到主题目录改名 page-qingyan.php
├── Doctor.php              环境自检（后台页签，无需传文件）
├── assets/
│   ├── qingyan.css         前台样式（qy- 前缀，CSS 变量 + 深色模式）
│   ├── qingyan.js          前台交互（原生 JS，无依赖）
│   ├── front.css           独立前台页面外壳样式
│   └── admin.css           后台面板样式（qya- 前缀）
└── README.md               本文档
```

---

## JSON 接口

基础地址：`https://你的域名/qingyan/api/{动作}`（未开启伪静态时为 `/index.php/qingyan/api/{动作}`）。

统一响应 `{ "code": 0, "msg": "提示语", "data": {} }`，`code` 为 `0` 表示成功。

| 动作 | 方法 | 参数 | 说明 |
|---|---|---|---|
| `list` | GET | `page` `limit`（≤50） | 返回 `data.list[]`，每项含 `id / author / content / html / images[] / likes / comments / created / ago / url` |
| `post` | POST | `content`（≤2000 字）`images`（≤9，数组或换行）`token` | 仅管理员，发布后直接公开 |
| `like` | POST | `id` | 同一访客重复请求即取消点赞，返回 `{ liked, likes }` |
| `comment` | POST | `id` `text` `author` `mail` | 返回 `{ id, count, status, html }`，`html` 可直接插入 |
| `comments` | GET | `id` | 返回评论 `html` 片段 |
| `delete` | POST | `id` `type`（`post` / `comment`） | 仅管理员 |

**Token**：页面的 `window.Qingyan.token` 会下发一个会话级 Token，插件自带脚本已自动携带。
自行调用写接口时，可通过 POST 字段 `token` 或请求头 `X-Qingyan-Token` 传入，并带上同源 Referer。

---

## 数据表

**只有一张表** `typecho_qingyan`（跟随 Typecho 表前缀），点赞与评论以 JSON 存在同一行：

| 字段 | 类型 | 说明 |
|---|---|---|
| `id` | int | 主键 |
| `authorId` / `author` / `authorMail` | int / varchar | 作者信息（发布者为管理员账号） |
| `content` | text | 正文 |
| `images` | text | 图片地址，JSON 数组 |
| `liked` | text | 点赞指纹，JSON 数组（IP + UA 哈希，不存明文 IP；单条上限 2000） |
| `comments` | text | 评论，JSON 数组（含 id / 作者 / 邮箱 / 评论 IP / 状态 / 时间；**IP 为明文**，用于限频与审核；单条上限 500） |
| `status` | varchar | `publish` / `pending` / `draft` |
| `likes` / `commentsNum` | int | 冗余计数，便于排序展示 |
| `source` / `agent` | varchar | 来源标识与 UA |
| `created` / `modified` | int | 时间戳 |

1.3.0 之前是三张表，合并成单表后：建表 SQL 只需 3 段（不用 3 表 × 3 数据库 = 9 段），
读取无需 JOIN，备份就是一张表。代价是评论/点赞没有独立索引（说说场景可忽略）。
禁用插件时默认保留数据，可在设置里改为删除。

---

## 常见问题

**后台菜单在哪？** 插件**不新建一级菜单**，挂在已有的「管理」下：后台 → 管理 → 轻言。
直连地址 `/admin/extending.php?panel=Qingyan/panel.php`。管理页直接 include 后台的
header / menu / footer，复用后台布局与按钮样式。用的都是 Typecho 标准 class
（`.typecho-page-title`、`.typecho-table-wrap`、`.typecho-pager`…），会跟随后台主题升级。
页面含：统计卡片、发布/编辑表单（正文、图片、本地上传、状态、自定义发布时间）、
内容列表（编辑/删除/通过，含分页）、评论管理（通过/删除，含分页）。

**后台出现多个「轻言」菜单？** 旧版用 `Helper::addMenu()`，每次启用都会堆一个。
1.4.0 起改用 `Helper::addPanel(3, …)` 并先 `removePanel` 再注册（幂等）。
清理方式：**覆盖新版文件后「禁用 → 重新启用」**（新版 `deactivate()` 会清空全部「轻言」菜单）。

**启用或打开设置时报「插件Qingyan的配置信息没有找到」？**

1. Typecho 1.3 + 旧版插件：1.3 只识别 `TypechoPlugin\Qingyan\Plugin`，升级到 1.1.0+ 即可。
2. options 表里没有 `plugin:Qingyan` 行：1.3 渲染设置页时会直接取值，取不到就抛异常
   （发生在核心里，插件 catch 不到）。典型触发是**用旧版插件完成激活、之后才覆盖新版文件**。
   1.1.0 起 `Plugin.php` 加载时会自愈补齐，**覆盖文件后刷新一次设置页通常就好了**。
   若仍报错，手动补一行 SQL（注意替换表前缀）：

   ```sql
   INSERT INTO `typecho_options` (`name`, `user`, `value`) VALUES ('plugin:Qingyan', 0, '');
   ```

**访问 `/qingyan` 出现 404？** 确认插件已启用；到「插件 → 设置」点一次保存让路由重写；
未开启伪静态时访问 `/index.php/qingyan`；Nginx 用户确认已配置 Typecho 的 rewrite 规则。

**点赞没反应？** 多为 CSRF 校验失败。检查站点是否启用了会剥离 session 的缓存
（如全站 CDN 缓存 HTML），把 `/qingyan` 加入不缓存规则即可。

**上传图片失败？** 检查 `usr/uploads/` 是否可写、PHP 的 `upload_max_filesize` 是否大于 5MB、
是否安装了 `fileinfo` 扩展。

**想确认环境有没有问题？** 后台 → 管理 → 轻言 → **运行环境自检**。逐项检查 14 项：
插件目录名、PHP 版本、Typecho 分支、入口类、主题调用类、四个必需方法、数据表、
插件配置行、后台菜单数量、API 地址（是否重复 index.php）、路由注册、启用状态、
内容统计、插件版本。
自检页在后台框架内（`admin/extending.php?panel=Qingyan/panel.php&action=doctor`），
不受前台路由影响，也不需要往站点根目录传任何文件。

---

## 变更与维护

### 2.8

- **正文改用完整的 Markdown 解析**：不再用手写正则，交给 Typecho 的 `Utils\HyperDown`
  （`enableHtml(false)`，因为评论来自游客），输出再走标签白名单消毒；链接先摘出、
  由插件重建后再还原（所以白名单不需要放开 `href`）。**渲染时解析，不需要数据迁移。**
- **`#话题#` 变成站内搜索链接**：`<a class="qy-topic" href="网站/?s=标签">`，
  与主题搜索框目标一致。行首 `#` 的标题判定同步收紧到 CommonMark 标准
  （`#` 后必须跟空格），顺带修掉 `#今日心情 随便写写` 被吃成 `<h1>` 的问题。
- **后台分页条改用核心的 DOM**：`ul.typecho-pager` + 直接子 `<li>`（与
  `manage-posts.php` 一致），当前页用 `<a>`，页码按核心 Box 导航器开窗。
- **站内搜索里的轻言命中已移交给独立的「搜索增强」（SearchPlus）插件**：
  轻言只提供数据（`Model::search()/searchCount()/snippet()` 保留供其调用），
  自己保留 `/qingyan?q=关键词` 搜索页。

### 2.7 及更早

恢复独立前台页面（`Front.php`）；点赞与评论合并为单表（1.3.0）。

> 每次改动的完整背景、实测数据与踩坑记录在仓库的 `.ai-notes/` 目录
> （`04-plugin-qingyan.md`、`16-CURRENT-STATE.md`），这里只留结论。

---

## 安全说明

- 所有输出均经 `htmlspecialchars` 转义，正文只解析白名单内的 Markdown 标签，不执行 HTML
- 写接口仅接受 POST，需要会话 Token + 严格同源（scheme + host 的 Origin，缺失时看 Referer）双重校验
- 点赞按「IP + UA 哈希」去重，不存储明文 IP；评论为便于限频与后台审核会保存**明文 IP**
- 上传文件用 `fileinfo` 校验真实 MIME，随机文件名，限制 5MB
- 评论支持频率限制与审核队列

## 许可

MIT License。
