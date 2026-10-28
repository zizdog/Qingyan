<?php
/**
 * 轻言 - 后台管理面板
 *
 * 路径：后台 → 管理 → 轻言（admin/extending.php?panel=Qingyan/panel.php）
 *
 * 与 Typecho-Plugin-Says 的做法一致：直接 include 后台的 header.php / menu.php /
 * footer.php，页面就长在 Typecho 后台里（侧栏、提示条、按钮样式全部复用），
 * 而不是另起一套自绘 UI。
 *
 * @package Qingyan
 * @author  Yuanbao
 * @version 2.8.6
 */

if (!defined('__TYPECHO_ADMIN__')) {
    exit;
}

require_once dirname(__FILE__) . '/compat.php';
require_once dirname(__FILE__) . '/Plugin.php';

/**
 * 定位 Typecho 后台的公共文件（header / menu / footer）
 *
 * 【注意】这里只返回路径，真正的 include 必须写在文件顶层作用域。
 * 如果包在函数里 include，函数作用域拿不到外层变量，
 * header.php 里的 $options 会变成 null，直接报
 * "Call to a member function adminStaticUrl() on null"。
 *
 * @param string $file
 * @return string 文件路径；找不到返回相对路径兜底
 */
function qy_admin_path($file)
{
    $candidates = array();

    // 1. 相对路径：extending.php 在本文件外层 include 时，
    //    PHP 按「调用脚本所在目录」解析，即 admin/
    $candidates[] = $file;

    // 2. 从当前执行脚本推导（admin/extending.php）
    if (!empty($_SERVER['SCRIPT_FILENAME'])) {
        $candidates[] = dirname($_SERVER['SCRIPT_FILENAME']) . '/' . $file;
    }

    // 3. 从 Typecho 根目录推导
    if (defined('__TYPECHO_ROOT_DIR__')) {
        $candidates[] = rtrim(__TYPECHO_ROOT_DIR__, '/') . '/admin/' . $file;
    }

    // 4. 从插件目录往上推：usr/plugins/Qingyan → 站点根
    $candidates[] = dirname(__FILE__) . '/../../../admin/' . $file;

    // 5. admin 目录被改名时用后台 URL 反推
    if (!empty($_SERVER['SCRIPT_NAME'])) {
        $candidates[] = dirname(
            rtrim((string) $_SERVER['DOCUMENT_ROOT'], '/') . '/' . ltrim((string) $_SERVER['SCRIPT_NAME'], '/')
        ) . '/' . $file;
    }

    foreach ($candidates as $path) {
        if (file_exists($path) && is_readable($path)) {
            return $path;
        }
    }

    return $file;
}

/**
 * 统一转义
 */
if (!function_exists('qy_admin_esc')) {
    function qy_admin_esc($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

// session 兜底
if (PHP_SESSION_ACTIVE !== session_status()) {
    if (class_exists('Typecho_Session')) {
        if (!Typecho_Session::isStarted()) {
            Typecho_Session::start();
        }
    } else {
        @session_start();
    }
}

$db      = qy_db();
$options = Helper::options();
$request = Typecho_Request::getInstance();
$user    = Typecho_Widget::widget('Widget_User');
$panel   = 'extending.php?panel=Qingyan/panel.php';

$user->pass('administrator');

if (empty($_SESSION['qingyan_admin_token'])) {
    $_SESSION['qingyan_admin_token'] = md5(uniqid(mt_rand(), true));
}

$token = $_SESSION['qingyan_admin_token'];

/**
 * 后台分页条
 *
 * 【为什么要自己拼 DOM】Typecho 后台的分页样式是写给
 * `<ul class="typecho-pager">` 的**直接子 `<li>`** 的（见 admin/css/style.css）：
 *
 *   .typecho-pager      { display:flex; justify-content:flex-end; list-style:none; margin:0; padding:0 }
 *   .typecho-pager li   { margin:0 3px; height:28px; line-height:28px }
 *   .typecho-pager li a { display:block; padding:0 10px; border-radius:2px }
 *   .typecho-pager li.current a { background:#E9E9E6; color:#444 }
 *
 * 所以必须照抄核心的写法（`admin/manage-posts.php` 就是
 * `<ul class="typecho-pager">` + `$posts->pageNav()`），不能用
 * 「外层 div + 中间 div + ul」那一套：
 *   1. 类名挂在 div 上的话，里面那个 `<ul>` 会吃浏览器默认的
 *      `margin:1em 0; padding-left:40px` —— 整条分页缩进、上下还多出空行；
 *   2. 当前页若渲染成 `<span>`，就命中不了 `.current a` 的底色，看不出在第几页。
 * 另外要放在 `.typecho-table-wrap` **外面**（与核心一致），别被表格容器的边框裹住。
 *
 * 页码按核心 Box 导航器的做法**开窗**：首页 + 当前页 ±2 + 末页，中间用省略号，
 * 免得页数一多就排出几十个链接把布局撑爆。
 *
 * 【为什么是「拼接参数」而不是 sprintf 模板】`$panel` 里带着
 * `panel=Qingyan%2Fpanel.php`，那个 `%2F` 会被 sprintf 当成格式符
 * （`%2F` = 宽度 2 的浮点），直接抛 ArgumentCountError —— 实测踩到过。
 * 所以这里只接收「基础地址 + 参数名」，页码自己拼。
 *
 * @param int    $current    当前页（1 起）
 * @param int    $totalPages 总页数
 * @param string $baseUrl    列表页地址（可已带查询串）
 * @param string $param      页码参数名，默认 `p`（评论列表用 `cp`）
 * @return string 不需要分页时返回空串
 */
function qy_pager($current, $totalPages, $baseUrl, $param = 'p')
{
    $current    = max(1, intval($current));
    $totalPages = intval($totalPages);
    $param      = preg_replace('/[^a-z0-9_]/i', '', (string) $param);

    if ($totalPages <= 1 || '' === $param) {
        return '';
    }

    $link = function ($page) use ($baseUrl, $param) {
        return '<a href="' . qy_admin_esc($baseUrl . '&' . $param . '=' . intval($page)) . '">';
    };

    $from = max(1, $current - 2);
    $to   = min($totalPages, $current + 2);
    $out  = '<ul class="typecho-pager">';

    // 上一页
    if ($current > 1) {
        $out .= '<li class="prev">' . $link($current - 1) . '&laquo;</a></li>';
    }

    // 首页（离得远时补出来）
    if ($from > 1) {
        $out .= '<li>' . $link(1) . '1</a></li>';

        if ($from > 2) {
            $out .= '<li><span>...</span></li>';
        }
    }

    // 中间窗口
    for ($i = $from; $i <= $to; $i++) {
        // 当前页在核心的 DOM 里也是 <a>（样式靠 .current a 上底色），不是 <span>
        $out .= ($i === $current ? '<li class="current">' : '<li>')
            . $link($i) . $i . '</a></li>';
    }

    // 末页
    if ($to < $totalPages) {
        if ($to < $totalPages - 1) {
            $out .= '<li><span>...</span></li>';
        }

        $out .= '<li>' . $link($totalPages) . $totalPages . '</a></li>';
    }

    // 下一页
    if ($current < $totalPages) {
        $out .= '<li class="next">' . $link($current + 1) . '&raquo;</a></li>';
    }

    return $out . '</ul>';
}

/**
 * 面板内的跳转地址（带分页参数）
 *
 * @param int $p
 * @param int $cp
 * @return string
 */
function qy_back_url($p = 1, $cp = 1)
{
    global $options, $panel;

    return $options->adminUrl . $panel . '&p=' . intval($p) . '&cp=' . intval($cp);
}

/* ------------------------------------------------------------------ */
/* 动作处理（必须在输出任何内容之前）                                  */
/* ------------------------------------------------------------------ */

/**
 * 状态的中文名与标签样式
 *
 * 列表里以前是直接把 status 原样打出来，pending / draft 就露出英文了。
 *
 * @param string $status
 * @return array array('key' => ..., 'label' => ..., 'class' => ...)
 */
function qy_status_meta($status)
{
    $map = array(
        'publish' => array('label' => '已发布', 'class' => 'is-ok'),
        'pending' => array('label' => '待审核', 'class' => 'is-wait'),
        'draft'   => array('label' => '草稿',   'class' => 'is-draft'),
    );

    $key = isset($map[$status]) ? $status : 'publish';

    return array_merge(array('key' => $key), $map[$key]);
}

/**
 * 处理表单提交
 *
 * @return array type / text
 */
$qyHandle = function () use ($db, $user) {
    if ('POST' !== strtoupper(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET')) {
        return array('type' => '', 'text' => '');
    }

    if (empty($_SESSION['qingyan_admin_token']) || empty($_POST['_qy_token'])
        || !hash_equals($_SESSION['qingyan_admin_token'], (string) $_POST['_qy_token'])) {
        return array('type' => 'error', 'text' => '表单已过期，请刷新页面后重试');
    }

    $action = isset($_POST['action']) ? trim((string) $_POST['action']) : '';

    switch ($action) {
        case 'save':
            $id      = intval(isset($_POST['id']) ? $_POST['id'] : 0);
            $content = trim((string) (isset($_POST['content']) ? $_POST['content'] : ''));

            if ('' === $content) {
                return array('type' => 'error', 'text' => '内容不能为空');
            }

            $status  = isset($_POST['status']) ? trim((string) $_POST['status']) : 'publish';
            $created = trim((string) (isset($_POST['created']) ? $_POST['created'] : ''));

            if (!in_array($status, array('publish', 'pending', 'draft'), true)) {
                $status = 'publish';
            }

            // 心情：非法值一律落回 default
            $mood = Qingyan_Model::normalizeMood(isset($_POST['mood']) ? $_POST['mood'] : '');

            $data = array(
                'content' => $content,
                'status'  => $status,
                'mood'    => $mood,
            );

            if ($id > 0) {
                Qingyan_Model::update($id, $data);

                if ('' !== $created && false !== strtotime($created)) {
                    $db->query(
                        $db->update('table.qingyan')
                            ->rows(array('created' => strtotime($created)))
                            ->where('id = ?', $id)
                    );
                }

                return array('type' => 'success', 'text' => '已保存修改');
            }

            $data['authorId']   = intval($user->uid);
            $data['author']     = $user->screenName ? $user->screenName : $user->name;
            $data['authorMail'] = isset($user->mail) ? $user->mail : '';
            $data['source']     = 'admin';
            $data['agent']      = substr(isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '', 0, 255);

            if ('' !== $created && false !== strtotime($created)) {
                $data['created'] = strtotime($created);
            }

            Qingyan_Model::add($data);

            return array('type' => 'success', 'text' => '已发布');

        case 'delete':
            $id = intval(isset($_POST['id']) ? $_POST['id'] : 0);

            if ($id > 0) {
                Qingyan_Model::delete($id);
            }

            return array('type' => 'success', 'text' => '已删除');

        case 'status':
            $id     = intval(isset($_POST['id']) ? $_POST['id'] : 0);
            $status = isset($_POST['status']) ? (string) $_POST['status'] : 'publish';

            if ($id > 0) {
                Qingyan_Model::setStatus($id, $status);
            }

            return array('type' => 'success', 'text' => '状态已更新');

        case 'bulk':
            /*
             * do 要同时看 $_GET：批量表单提交时 action 被换成下拉链接的 href，
             * 而 &do=delete / &do=publish 是在那个 href 的**查询串**里，
             * 表单又是 POST —— 所以它只会出现在 $_GET，不会进 $_POST。
             * （action 本身在表单里另有一个 hidden input，所以 $_POST 里拿得到。）
             */
            $do = '';
            if (isset($_POST['do'])) {
                $do = trim((string) $_POST['do']);
            } elseif (isset($_GET['do'])) {
                $do = trim((string) $_GET['do']);
            }

            $ids = isset($_POST['cid']) && is_array($_POST['cid']) ? $_POST['cid'] : array();

            // 只收有效的正整数 id，顺便去重
            $ids = array_values(array_unique(array_filter(
                array_map('intval', $ids),
                function ($v) {
                    return $v > 0;
                }
            )));

            if (empty($ids)) {
                return array('type' => 'error', 'text' => '请先勾选要操作的内容');
            }

            $count = count($ids);

            if ('delete' === $do) {
                foreach ($ids as $one) {
                    Qingyan_Model::delete($one);
                }

                return array('type' => 'success', 'text' => '已删除 ' . $count . ' 条');
            }

            $statusLabels = array(
                'publish' => '已发布',
                'pending' => '待审核',
                'draft'   => '草稿',
            );

            if (isset($statusLabels[$do])) {
                foreach ($ids as $one) {
                    Qingyan_Model::setStatus($one, $do);
                }

                return array(
                    'type' => 'success',
                    'text' => '已把 ' . $count . ' 条标记为「' . $statusLabels[$do] . '」',
                );
            }

            return array('type' => 'error', 'text' => '未知的批量操作');

        case 'comment-status':
            $id     = intval(isset($_POST['id']) ? $_POST['id'] : 0);
            $status = isset($_POST['status']) ? (string) $_POST['status'] : 'approved';

            if ($id > 0) {
                Qingyan_Model::setCommentStatus($id, $status);
            }

            return array('type' => 'success', 'text' => '评论已处理');

        case 'comment-delete':
            $id = intval(isset($_POST['id']) ? $_POST['id'] : 0);

            if ($id > 0) {
                Qingyan_Model::deleteComment($id);
            }

            return array('type' => 'success', 'text' => '评论已删除');

        default:
            return array('type' => '', 'text' => '');
    }
};

$notice = $qyHandle();

// PRG：POST 处理完立即跳回，避免刷新重复提交
if ('POST' === strtoupper(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET')) {
    $_SESSION['qingyan_notice'] = $notice;

    $back = isset($_POST['back']) ? (string) $_POST['back'] : '';

    // 只允许跳回本站后台，避免开放重定向
    if (0 !== strpos($back, (string) $options->adminUrl)) {
        $back = qy_back_url();
    }

    echo '<script>window.location.replace(' . json_encode($back, JSON_UNESCAPED_UNICODE) . ');</script>';
    echo '<noscript><meta http-equiv="refresh" content="0;url=' . qy_admin_esc($back) . '"></noscript>';

    return;
}

// GET 时取回上次操作提示
if (isset($_SESSION['qingyan_notice']) && is_array($_SESSION['qingyan_notice'])) {
    $notice = $_SESSION['qingyan_notice'];
    unset($_SESSION['qingyan_notice']);
}

/* ------------------------------------------------------------------ */
/* 数据准备                                                            */
/* ------------------------------------------------------------------ */

$page     = max(1, intval($request->get('p', 1)));
$size     = 15;
$total    = Qingyan_Model::count('all');
$pages    = max(1, intval(ceil($total / $size)));
$page     = min($page, $pages);
$rows     = $total > 0 ? Qingyan_Model::page($page, $size, 'all') : array();

$statAll     = Qingyan_Model::count('all');
$statPublish = Qingyan_Model::count('publish');
$statPending = Qingyan_Model::count('pending');

// 一次取回全部评论，统计与分页都从同一份数据算出，
// 避免 old 版本那样对整表解码两遍（allComments('') + allComments('pending')）。
$allComments     = Qingyan_Model::allComments('');
$statComment     = count($allComments);
$statPendingCmt  = 0;

foreach ($allComments as $qyComment) {
    if (isset($qyComment['status']) && 'pending' === $qyComment['status']) {
        $statPendingCmt++;
    }
}

$commentPage  = max(1, intval($request->get('cp', 1)));
$commentSize  = 15;
$commentPages = max(1, intval(ceil($statComment / $commentSize)));
$commentPage  = min($commentPage, $commentPages);
$comments     = array_slice($allComments, ($commentPage - 1) * $commentSize, $commentSize);

$editId = intval($request->get('edit', 0));
$editRow = 0 < $editId ? Qingyan_Model::get($editId) : NULL;

$back = qy_back_url($page, $commentPage);

require_once dirname(__FILE__) . '/Doctor.php';

// 页签：action=doctor 时渲染诊断页
$panelAction = isset($_GET['action']) ? trim((string) $_GET['action']) : '';

// 引入后台头部（侧栏、CSS、通知条都在这里输出）
// 必须在文件顶层 include，才能继承上面的 $options 等变量
include qy_admin_path('header.php');
include qy_admin_path('menu.php');

// 诊断页：渲染完直接收尾，不进入下面的主面板
if ('doctor' === $panelAction) {
    Qingyan_Doctor::render();
    include qy_admin_path('footer.php');

    return;
}
?>

<div class="main qya-scope">
    <div class="body container">
        <div class="typecho-page-title">
            <h2><?php _e('轻言'); ?></h2>
        </div>

        <div class="qya-head">
            <span class="qya-head-item">
                <?php _e('前台地址：'); ?>
                <a href="<?php echo qy_admin_esc(Qingyan_Plugin::frontUrl()); ?>" target="_blank"><?php echo qy_admin_esc(Qingyan_Plugin::frontUrl()); ?></a>
            </span>
            <a class="qya-head-btn" href="<?php echo qy_admin_esc($options->adminUrl . 'plugins.php?config=Qingyan'); ?>"><?php _e('插件设置'); ?></a>
            <a class="qya-head-btn" href="<?php echo qy_admin_esc($options->adminUrl . 'extending.php?panel=Qingyan/panel.php&action=doctor'); ?>"><?php _e('运行环境自检'); ?></a>
        </div>

        <div class="row typecho-page-main" role="main">
            <div class="col-mb-12">

                <?php if (!empty($notice['text'])) { ?>
                <div class="typecho-list-operate clearfix">
                    <p class="qya-notice <?php echo 'success' === $notice['type'] ? 'is-ok' : 'is-error'; ?>">
                        <?php echo qy_admin_esc($notice['text']); ?>
                    </p>
                </div>
                <?php } ?>

                <!-- 统计 -->
                <div class="typecho-list-operate clearfix">
                    <ul class="qya-stats">
                        <li class="qya-stat">
                            <b class="qya-stat-num"><?php echo $statAll; ?></b>
                            <span class="qya-stat-label"><?php _e('全部'); ?></span>
                        </li>
                        <li class="qya-stat">
                            <b class="qya-stat-num"><?php echo $statPublish; ?></b>
                            <span class="qya-stat-label"><?php _e('已发布'); ?></span>
                        </li>
                        <li class="qya-stat">
                            <b class="qya-stat-num"><?php echo $statPending; ?></b>
                            <span class="qya-stat-label"><?php _e('待审核'); ?></span>
                        </li>
                        <li class="qya-stat">
                            <b class="qya-stat-num"><?php echo $statComment; ?></b>
                            <span class="qya-stat-label"><?php _e('评论'); ?></span>
                        </li>
                        <li class="qya-stat">
                            <b class="qya-stat-num"><?php echo $statPendingCmt; ?></b>
                            <span class="qya-stat-label"><?php _e('评论待审'); ?></span>
                        </li>
                    </ul>
                </div>

                <!-- 发布 / 编辑 -->
                <div class="typecho-list-operate clearfix">
                    <h3 class="qya-title" style="margin-bottom:10px"><?php echo $editRow ? _t('编辑轻言 #' . $editId) : _t('发布新轻言'); ?></h3>
                </div>

                <div class="typecho-table-wrap">
                    <form method="post" action="<?php echo qy_admin_esc($options->adminUrl . $panel); ?>">
                        <input type="hidden" name="_qy_token" value="<?php echo qy_admin_esc($token); ?>">
                        <input type="hidden" name="back" value="<?php echo qy_admin_esc($back); ?>">
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="id" value="<?php echo $editRow ? intval($editRow['id']) : 0; ?>">

                        <div class="qya-pad">
                            <p>
                                <textarea name="content" id="qy-content" class="w-100 mono qya-textarea" placeholder="<?php _e('此刻的想法…… 支持 **粗体**、`代码`、#话题#，链接会自动识别'); ?>"><?php echo $editRow ? qy_admin_esc($editRow['content']) : ''; ?></textarea>
                            </p>

                            <!-- 卡片左侧显示什么：头像，或 assets/emoji/ 里的预置心情图 -->
                            <?php
                            $qyCurMood = Qingyan_Model::normalizeMood(
                                $editRow && isset($editRow['mood']) ? $editRow['mood'] : ''
                            );
                            // 「头像」那一档直接放当前用户的头像缩略图，一眼能看出选了会显示什么
                            $qySelfAvatar = Qingyan_Model::avatar(isset($user->mail) ? $user->mail : '', 64);
                            ?>
                            <div class="qya-moods">
                                <span class="qya-moods-title"><?php _e('心情'); ?></span>
                                <div class="qya-moods-list">
                                    <?php foreach (Qingyan_Model::moods() as $qyMoodKey => $qyMood) {
                                        $qyMoodSrc = '' === $qyMood['file']
                                            ? $qySelfAvatar
                                            : Qingyan_Model::moodImageUrl($qyMood['file']);
                                    ?>
                                    <label class="qya-mood" title="<?php echo qy_admin_esc($qyMood['label']); ?>">
                                        <input type="radio" name="mood" value="<?php echo qy_admin_esc($qyMoodKey); ?>"
                                               <?php echo $qyMoodKey === $qyCurMood ? 'checked' : ''; ?>>
                                        <img class="qya-mood-ico" src="<?php echo qy_admin_esc($qyMoodSrc); ?>"
                                             alt="<?php echo qy_admin_esc($qyMood['label']); ?>">
                                        <span class="qya-mood-name"><?php echo qy_admin_esc($qyMood['label']); ?></span>
                                    </label>
                                    <?php } ?>
                                </div>
                            </div>

                            <!-- 顺序：状态 → 时间 → 发布按钮 -->
                            <div class="qya-form-row">


                                <div class="qya-field">
                                    <select name="status" id="qy-status">
                                        <option value="publish" <?php echo $editRow && 'publish' === $editRow['status'] ? 'selected' : ''; ?>><?php _e('已发布'); ?></option>
                                        <option value="pending" <?php echo $editRow && 'pending' === $editRow['status'] ? 'selected' : ''; ?>><?php _e('待审核'); ?></option>
                                        <option value="draft" <?php echo $editRow && 'draft' === $editRow['status'] ? 'selected' : ''; ?>><?php _e('草稿'); ?></option>
                                    </select>
                                </div>

                                <div class="qya-field">
                                    <input type="text" name="created" id="qy-created" class="text-s typecho-date" style="width:200px" autocomplete="off"
                                           placeholder="<?php _e('发布时间：留空为当前'); ?>"
                                           value="<?php echo $editRow ? qy_admin_esc(date('Y-m-d H:i', intval($editRow['created']))) : ''; ?>">
                                </div>

                                <div class="qya-form-actions">
                                    <?php if ($editRow) { ?>
                                    <a class="btn" href="<?php echo qy_admin_esc($options->adminUrl . $panel . '&p=' . $page); ?>"><?php _e('取消'); ?></a>
                                    <?php } ?>
                                    <button type="submit" class="btn primary"><?php echo $editRow ? _t('保存修改') : _t('发布'); ?></button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- 内容列表 -->
                <?php /* 批量操作表单。勾选框在下面的表格里，用 HTML5 form 属性挂到这个表单上：
                        每行已经有自己的 <form>（删除 / 通过），整张表再套一层 form 会把它们冲掉。 */ ?>
                <form id="qy-bulk" method="post" action="<?php echo qy_admin_esc($options->adminUrl . $panel); ?>">
                    <input type="hidden" name="_qy_token" value="<?php echo qy_admin_esc($token); ?>">
                    <input type="hidden" name="back" value="<?php echo qy_admin_esc($back); ?>">
                    <input type="hidden" name="action" value="bulk">

                    <div class="typecho-list-operate clearfix">
                        <h3 class="qya-title"><?php _e('全部轻言'); ?>（<?php echo $total; ?>）</h3>

                        <?php if (!empty($rows)) { ?>
                        <div class="operate">
                            <label><i class="sr-only"><?php _e('全选'); ?></i><input type="checkbox"
                                    class="typecho-table-select-all"/></label>
                            <div class="btn-group btn-drop">
                                <button class="btn dropdown-toggle btn-s" type="button"><i
                                        class="sr-only"><?php _e('操作'); ?></i><?php _e('选中项'); ?> <i
                                        class="i-caret-down"></i></button>
                                <ul class="dropdown-menu">
                                    <li><a class="qy-bulk-action" lang="<?php _e('确定删除选中的内容？'); ?>"
                                           href="<?php echo qy_admin_esc($options->adminUrl . $panel . '&action=bulk&do=delete'); ?>"><?php _e('删除'); ?></a>
                                    </li>
                                    <li>
                                        <a class="qy-bulk-action"
                                           href="<?php echo qy_admin_esc($options->adminUrl . $panel . '&action=bulk&do=publish'); ?>"><?php _e('标记为<strong>已发布</strong>'); ?></a>
                                    </li>
                                    <li>
                                        <a class="qy-bulk-action"
                                           href="<?php echo qy_admin_esc($options->adminUrl . $panel . '&action=bulk&do=pending'); ?>"><?php _e('标记为<strong>待审核</strong>'); ?></a>
                                    </li>
                                    <li>
                                        <a class="qy-bulk-action"
                                           href="<?php echo qy_admin_esc($options->adminUrl . $panel . '&action=bulk&do=draft'); ?>"><?php _e('标记为<strong>草稿</strong>'); ?></a>
                                    </li>
                                </ul>
                            </div>
                        </div>
                        <?php } ?>
                    </div>
                </form>

                <div class="typecho-table-wrap">
                    <?php if (empty($rows)) { ?>
                        <div class="typecho-list">
                            <div class="typecho-list-table-row notice">
                                <p><?php _e('还没有内容，在上面写一条吧。'); ?></p>
                            </div>
                        </div>
                    <?php } else { ?>
                    <table class="typecho-list-table" id="qy-posts-table">
                        <colgroup>
                            <col width="4%">
                            <col width="46%">
                            <col width="10%">
                            <col width="13%">
                            <col width="8%">
                            <col width="9%">
                            <col width="10%">
                        </colgroup>
                        <thead>
                        <tr>
                            <th></th>
                            <th><?php _e('内容'); ?></th>
                            <th><?php _e('作者'); ?></th>
                            <th><?php _e('时间'); ?></th>
                            <th><?php _e('状态'); ?></th>
                            <th><?php _e('互动'); ?></th>
                            <th><?php _e('操作'); ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $row) { ?>
                        <tr id="qy-row-<?php echo intval($row['id']); ?>">
                            <td><input type="checkbox" name="cid[]" value="<?php echo intval($row['id']); ?>"
                                       form="qy-bulk"/></td>
                            <td>
                                <?php
                                $qyMoodMeta = Qingyan_Model::moodOf($row);
                                // 选了「头像」档就画头像，否则画预置心情图
                                $qyMoodSrc = $qyMoodMeta['avatar']
                                    ? Qingyan_Model::avatar($row['authorMail'], 40)
                                    : $qyMoodMeta['img'];
                                ?>
                                <div class="qya-content-cell"><img class="qya-mood-badge"
                                        src="<?php echo qy_admin_esc($qyMoodSrc); ?>"
                                        alt="<?php echo qy_admin_esc($qyMoodMeta['label']); ?>"
                                        title="<?php echo qy_admin_esc('心情：' . $qyMoodMeta['label']); ?>"><?php echo Qingyan_Model::format($row['content']); ?></div>
                                <div class="qya-note" style="margin-top:6px">
                                    <span>ID: <?php echo intval($row['id']); ?></span>
                                </div>
                            </td>
                            <td><?php echo empty($row['author']) ? '—' : qy_admin_esc($row['author']); ?></td>
                            <td><?php echo qy_admin_esc(date('Y-m-d H:i', intval($row['created']))); ?></td>
                            <td>
                                <?php $qyStatus = qy_status_meta($row['status']); ?>
                                <span class="qya-tag <?php echo qy_admin_esc($qyStatus['class']); ?>">
                                    <?php echo qy_admin_esc($qyStatus['label']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="qya-note"><?php _e('赞'); ?> <?php echo intval($row['likes']); ?></span>
                                ｜<span class="qya-note"><?php _e('评'); ?> <?php echo intval($row['commentsNum']); ?></span>
                            </td>
                            <td class="qya-ops">
                                <a href="<?php echo qy_admin_esc($options->adminUrl . $panel . '&edit=' . intval($row['id']) . '&p=' . $page); ?>"><?php _e('编辑'); ?></a>
                                ｜
                                <form method="post" action="<?php echo qy_admin_esc($options->adminUrl . $panel); ?>" class="qya-inline" onsubmit="return confirm('<?php _e('确定删除？'); ?>');">
                                    <input type="hidden" name="_qy_token" value="<?php echo qy_admin_esc($token); ?>">
                                    <input type="hidden" name="back" value="<?php echo qy_admin_esc($back); ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo intval($row['id']); ?>">
                                    <button type="submit" class="qy-linkbtn qya-danger-text"><?php _e('删除'); ?></button>
                                </form>
                                <?php if ('publish' !== $row['status']) { ?>
                                ｜
                                <form method="post" action="<?php echo qy_admin_esc($options->adminUrl . $panel); ?>" class="qya-inline">
                                    <input type="hidden" name="_qy_token" value="<?php echo qy_admin_esc($token); ?>">
                                    <input type="hidden" name="back" value="<?php echo qy_admin_esc($back); ?>">
                                    <input type="hidden" name="action" value="status">
                                    <input type="hidden" name="id" value="<?php echo intval($row['id']); ?>">
                                    <input type="hidden" name="status" value="publish">
                                    <button type="submit" class="qy-linkbtn"><?php _e('通过'); ?></button>
                                </form>
                                <?php } ?>
                            </td>
                        </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                    <?php } ?>

                </div>

                <?php /* 与核心一致：分页放在 .typecho-table-wrap 外面，用 ul.typecho-pager */ ?>
                <?php echo qy_pager($page, $pages, $options->adminUrl . $panel); ?>

                <!-- 评论管理 -->
                <div class="typecho-list-operate clearfix">
                    <h3 class="qya-title"><?php _e('评论管理'); ?>（<?php echo $statComment; ?><?php echo $statPendingCmt > 0 ? '，' . $statPendingCmt . ' ' . _t('待审') : ''; ?>）</h3>
                </div>

                <div class="typecho-table-wrap">
                    <?php if (empty($comments)) { ?>
                    <div class="typecho-list">
                        <div class="typecho-list-table-row notice">
                            <p><?php _e('还没有评论。'); ?></p>
                        </div>
                    </div>
                    <?php } else { ?>
                    <table class="typecho-list-table">
                        <colgroup>
                            <col width="18%">
                            <col width="42%">
                            <col width="12%">
                            <col width="14%">
                            <col width="14%">
                        </colgroup>
                        <thead>
                        <tr>
                            <th><?php _e('作者'); ?></th>
                            <th><?php _e('内容'); ?></th>
                            <th><?php _e('所属'); ?></th>
                            <th><?php _e('时间'); ?></th>
                            <th><?php _e('操作'); ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($comments as $comment) { ?>
                        <tr id="qy-comment-<?php echo intval($comment['id']); ?>">
                            <td>
                                <?php echo qy_admin_esc($comment['author']); ?>
                                <?php if (!empty($comment['mail'])) { ?>
                                <br><span class="qya-stat-label"><?php echo qy_admin_esc($comment['mail']); ?></span>
                                <?php } ?>
                                <?php if (!empty($comment['ip'])) { ?>
                                <br><span class="qya-stat-label"><?php echo qy_admin_esc($comment['ip']); ?></span>
                                <?php } ?>
                            </td>
                            <td class="qya-comment-text"><?php echo Qingyan_Model::format($comment['text']); ?></td>
                            <td>
                                <a href="<?php echo qy_admin_esc(Qingyan_Plugin::frontUrl(1) . '#qy-' . intval($comment['qid'])); ?>" target="_blank">#<?php echo intval($comment['qid']); ?></a>
                            </td>
                            <td>
                                <?php echo qy_admin_esc(date('Y-m-d H:i', intval($comment['created']))); ?>
                                <?php if ('approved' !== $comment['status']) { ?>
                                <br><span class="qya-status-pending"><?php echo qy_admin_esc($comment['status']); ?></span>
                                <?php } ?>
                            </td>
                            <td>
                                <?php if ('approved' !== $comment['status']) { ?>
                                <form method="post" action="<?php echo qy_admin_esc($options->adminUrl . $panel); ?>" class="qya-inline">
                                    <input type="hidden" name="_qy_token" value="<?php echo qy_admin_esc($token); ?>">
                                    <input type="hidden" name="back" value="<?php echo qy_admin_esc($back); ?>">
                                    <input type="hidden" name="action" value="comment-status">
                                    <input type="hidden" name="id" value="<?php echo intval($comment['id']); ?>">
                                    <input type="hidden" name="status" value="approved">
                                    <button type="submit" class="qy-linkbtn"><?php _e('通过'); ?></button>
                                </form>
                                ｜
                                <?php } ?>
                                <form method="post" action="<?php echo qy_admin_esc($options->adminUrl . $panel); ?>" class="qya-inline" onsubmit="return confirm('<?php _e('确定删除这条评论？'); ?>');">
                                    <input type="hidden" name="_qy_token" value="<?php echo qy_admin_esc($token); ?>">
                                    <input type="hidden" name="back" value="<?php echo qy_admin_esc($back); ?>">
                                    <input type="hidden" name="action" value="comment-delete">
                                    <input type="hidden" name="id" value="<?php echo intval($comment['id']); ?>">
                                    <button type="submit" class="qy-linkbtn qya-danger-text"><?php _e('删除'); ?></button>
                                </form>
                            </td>
                        </tr>
                        <?php } ?>
                        </tbody>
                    </table>

                    <?php } ?>
                </div>

                <?php echo qy_pager($commentPage, $commentPages, $options->adminUrl . $panel . '&p=' . $page, 'cp'); ?>

            </div>
        </div>
    </div>
</div>

<link rel="stylesheet" href="<?php echo qy_admin_esc(Qingyan_Plugin::assetUrl('admin.css')); ?>">

<?php
/*
 * 后台通用 JS：jquery.js + jquery-ui.js + typecho.js（typecho.js 里带了 $.fn.mask）。
 *
 * 轻言面板原来一个 JS 都没引，所以日期选择器要用到的依赖得自己补上。
 * 用 Typecho 自己的 common-js.php 而不是手写 <script>，这样依赖顺序和后台其它页面一致，
 * 顺带也让原生的顶部提示（.message.popup，见 admin/common-js.php 开头）在本页可用。
 */
include qy_admin_path('common-js.php');
?>
<script src="<?php $options->adminStaticUrl('js', 'timepicker.js'); ?>"></script>
<script>
$(document).ready(function () {
    /*
     * 发布时间选择器 —— 与后台写作页 admin/write-js.php 里的 #date 是同一套控件、同一份中文语言包。
     * 两处刻意的差异：
     *   1) 不传 timezone。Typecho 写作页会传 timezone，并在提交时附带一个隐藏的 timezone 字段
     *      让服务端换算；轻言这边是直接 strtotime() 解析这个字符串，没有换算环节，
     *      传了反而会让「显示的时间」和「存进去的时间」错位。
     *   2) 不传 amNames / pmNames。默认 timeFormat 就是 24 小时的 HH:mm，用不到上下午。
     */
    $('#qy-created').mask('9999-99-99 99:99').datetimepicker({
        currentText   : '<?php _e('现在'); ?>',
        prevText      : '<?php _e('上一月'); ?>',
        nextText      : '<?php _e('下一月'); ?>',
        monthNames    : ['<?php _e('一月'); ?>', '<?php _e('二月'); ?>', '<?php _e('三月'); ?>', '<?php _e('四月'); ?>',
            '<?php _e('五月'); ?>', '<?php _e('六月'); ?>', '<?php _e('七月'); ?>', '<?php _e('八月'); ?>',
            '<?php _e('九月'); ?>', '<?php _e('十月'); ?>', '<?php _e('十一月'); ?>', '<?php _e('十二月'); ?>'],
        dayNames      : ['<?php _e('星期日'); ?>', '<?php _e('星期一'); ?>', '<?php _e('星期二'); ?>',
            '<?php _e('星期三'); ?>', '<?php _e('星期四'); ?>', '<?php _e('星期五'); ?>', '<?php _e('星期六'); ?>'],
        dayNamesShort : ['<?php _e('周日'); ?>', '<?php _e('周一'); ?>', '<?php _e('周二'); ?>', '<?php _e('周三'); ?>',
            '<?php _e('周四'); ?>', '<?php _e('周五'); ?>', '<?php _e('周六'); ?>'],
        dayNamesMin   : ['<?php _e('日'); ?>', '<?php _e('一'); ?>', '<?php _e('二'); ?>', '<?php _e('三'); ?>',
            '<?php _e('四'); ?>', '<?php _e('五'); ?>', '<?php _e('六'); ?>'],
        closeText     : '<?php _e('完成'); ?>',
        timeOnlyTitle : '<?php _e('选择时间'); ?>',
        timeText      : '<?php _e('时间'); ?>',
        hourText      : '<?php _e('时'); ?>',
        minuteText    : '<?php _e('分'); ?>',
        secondText    : '<?php _e('秒'); ?>',
        dateFormat    : 'yy-mm-dd',
        hour          : (new Date()).getHours(),
        minute        : (new Date()).getMinutes()
    });
});
</script>
<script>
$(document).ready(function () {
    /*
     * 批量操作。复用 Typecho 自己的两个 jQuery 插件（都在 admin/js/typecho.js 里，
     * 由上面的 common-js.php 引入），所以行为和后台文章列表完全一致：
     *   tableSelectable —— 点「全选」全勾、点整行也能勾上
     *   dropdownMenu    —— 「选中项」下拉
     *
     * 提交方式照搬 Typecho：点下拉里的操作时，把表单 action 换成该链接的 href 再 submit，
     * 勾中的 cid[] 随表单一起 POST。这里没用 tableSelectable 自带的 actionEl，
     * 因为它要求表格被 form 包住，而本表每行已经有自己的 <form>。
     */
    $('#qy-posts-table').tableSelectable({
        checkEl     : 'input[type=checkbox]',
        rowEl       : 'tr',
        selectAllEl : '.typecho-table-select-all'
    });

    $('.btn-drop').dropdownMenu({
        btnEl  : '.dropdown-toggle',
        menuEl : '.dropdown-menu'
    });

    $('.qy-bulk-action').on('click', function () {
        var link = $(this),
            lang = link.attr('lang');

        if (lang && !confirm(lang)) {
            return false;
        }

        var form = document.getElementById('qy-bulk');

        if (form) {
            /*
             * 必须用 setAttribute，不能写 form.action = ... 。
             * 本表单里有个 <input name="action" value="bulk">，而 HTMLFormElement 是
             * [LegacyOverrideBuiltIns]，命名控件会遮蔽内置的 action 属性 ——
             * 直接赋值只会写到一个无效的地方，表单仍提交到原来的 action，
             * &do=delete / &do=publish 就丢了。（实测踩到过）
             */
            form.setAttribute('action', link.attr('href'));
            form.submit();
        }

        return false;
    });
});
</script>

<?php
include qy_admin_path('footer.php');
