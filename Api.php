<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

require_once dirname(__FILE__) . '/compat.php';

/**
 * 轻言 - 接口层
 *
 * 提供 /qingyan/api/{动作} 一组 JSON 接口，供前台脚本、主题或第三方调用。
 *
 * @package Qingyan
 * @author  Yuanbao
 * @version 1.0.0
 */
class Qingyan_Api extends Typecho_Widget
{
    /** 单条内容最大长度 */
    const MAX_CONTENT = 2000;

    /** 单条评论最大长度 */
    const MAX_COMMENT = 500;

    /**
     * Widget 初始化
     *
     * @return void
     */
    public function execute()
    {
    }

    /**
     * 兜底动作：当路由未指定 action 时由框架调用
     *
     * @return void
     */
    public function action()
    {
        $this->dispatch();
    }

    /**
     * 路由入口：/qingyan/api/[do:alpha]
     *
     * @return void
     */
    public function dispatch()
    {
        $do = $this->param('do', 'ping');
        $do = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', (string) $do));

        // 写操作只接受 POST：input() 会回落 $_GET，不限制方法的话
        // 一个 <img src> 或同源 GET 就能触发点赞/评论/删除。
        $postOnly = array('post', 'like', 'comment', 'delete');

        if (in_array($do, $postOnly, true) && 'POST' !== $this->method()) {
            if (!headers_sent()) {
                header('Allow: POST');
            }

            return $this->json(array(), 405, '请求方法不被允许');
        }

        switch ($do) {
            case 'ping':
                return $this->json(array('version' => Qingyan_Plugin::VERSION));

            case 'list':
                return $this->actionList();

            case 'post':
                return $this->actionPost();

            case 'like':
                return $this->actionLike();

            case 'comment':
                return $this->actionComment();

            case 'comments':
                return $this->actionComments();

            case 'fragment':
                return $this->actionFragment();

            case 'delete':
                return $this->actionDelete();

            default:
                return $this->json(array(), 404, '接口不存在：' . $do);
        }
    }

    /* ------------------------------------------------------------------ */
    /* 接口实现                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * 列表：GET /qingyan/api/list?page=1&limit=10
     *
     * @return void
     */
    protected function actionList()
    {
        $page  = max(1, intval($this->input('page', 1)));
        $limit = max(1, min(50, intval($this->input('limit', Qingyan_Plugin::intOpt('pageSize', 10)))));
        $total = Qingyan_Model::count('publish');
        $list  = Qingyan_Model::page($page, $limit, 'publish');

        $items = array();

        foreach ($list as $row) {
            $items[] = array(
                'id'       => intval($row['id']),
                'author'   => $row['author'],
                'content'  => $row['content'],
                'html'     => Qingyan_Model::format($row['content']),
                'likes'    => intval($row['likes']),
                'comments' => intval($row['commentsNum']),
                'created'  => intval($row['created']),
                'ago'      => Qingyan_Model::timeAgo($row['created']),
                'url'      => Qingyan_Plugin::homeUrl(1) . '#qy-' . intval($row['id']),
            );
        }

        return $this->json(array(
            'list'  => $items,
            'total' => $total,
            'page'  => $page,
            'pages' => max(1, intval(ceil($total / $limit))),
        ));
    }

    /**
     * 片段：GET /qingyan/api/fragment?page=2
     *
     * 给 ajax 翻页用。返回渲染好的条目 HTML 与分页 HTML，
     * 前端直接替换 #qy-timeline 与 .qy-pager 即可，无需刷新页面。
     *
     * 结构与 timeline() 渲染的完全一致（共用 Renderer::buildContext），
     * 所以翻页后样式、交互都不会变。
     *
     * @return void
     */
    protected function actionFragment()
    {
        $page    = max(1, intval($this->input('page', 1)));
        $size    = intval($this->input('limit', 0));
        $base    = trim((string) $this->input('base', ''));
        $keyword = trim((string) $this->input('keyword', ''));

        $opts = array();

        if ($size > 0) {
            $opts['size'] = $size;
        }

        if ('' !== $base) {
            $opts['baseUrl'] = $base;
        }

        // 搜索模式下的 ajax 翻页（见 Renderer::fetchPage）
        if ('' !== $keyword) {
            $opts['keyword'] = $keyword;
        }

        try {
            $fragment = Qingyan_Renderer::fragment($page, $opts);
        } catch (Throwable $e) {
            return $this->json(array(), 500, '渲染失败：' . qy_throwable_message($e));
        }

        return $this->json($fragment);
    }

    /**
     * 发布：POST /qingyan/api/post
     *
     * @return void
     */
    protected function actionPost()
    {
        if (!$this->guard()) {
            return $this->json(array(), 403, '请求校验失败，请刷新页面后重试');
        }

        $user = $this->identity();

        // 仅管理员可发布
        if (!$user['isAdmin']) {
            return $this->json(array(), 403, '仅管理员可发布内容');
        }

        $content = trim((string) $this->input('content', ''));

        if ('' === $content) {
            return $this->json(array(), 400, '内容不能为空');
        }

        if (function_exists('mb_substr')) {
            $content = mb_substr($content, 0, self::MAX_CONTENT, 'UTF-8');
        } else {
            $content = substr($content, 0, self::MAX_CONTENT);
        }

        $id = Qingyan_Model::add(array(
            'authorId'   => intval($user['uid']),
            'author'     => $user['name'],
            'authorMail' => $user['mail'],
            'content'    => $content,
            'status'     => 'publish',
            // 心情徽章：非法值由 normalizeMood 落回 default
            'mood'       => Qingyan_Model::normalizeMood($this->input('mood', 'default')),
            'source'     => 'api',
            'agent'      => $this->agent(),
        ));

        if ($id <= 0) {
            return $this->json(array(), 500, '保存失败，请稍后重试');
        }

        // 整条内容的渲染结果，供前端无刷新插入列表
        // 渲染失败不能影响发布结果本身，所以单独兜住
        $itemHtml = '';

        try {
            $itemHtml = Qingyan_Renderer::itemHtml($id, array(
                'baseUrl' => trim((string) $this->input('base', '')),
            ));
        } catch (Throwable $e) {
            $itemHtml = '';
        }

        return $this->json(array(
            'id'       => $id,
            'status'   => 'publish',
            'html'     => Qingyan_Model::format($content),
            'itemHtml' => $itemHtml,
            'ago'      => Qingyan_Model::timeAgo(Qingyan_Model::time()),
        ), 0, '已发布');
    }

    /**
     * 点赞：POST /qingyan/api/like
     *
     * @return void
     */
    protected function actionLike()
    {
        if (!Qingyan_Plugin::boolOpt('likeOn', true)) {
            return $this->json(array(), 403, '点赞功能已关闭');
        }

        if (!$this->guard()) {
            return $this->json(array(), 403, '请求校验失败，请刷新页面后重试');
        }

        $id = intval($this->input('id', 0));

        if ($id <= 0) {
            return $this->json(array(), 400, '参数错误');
        }

        $post = Qingyan_Model::get($id);

        if (!$post || 'publish' !== $post['status']) {
            return $this->json(array(), 404, '内容不存在');
        }

        $result = Qingyan_Model::toggleLike($id, Qingyan_Model::visitorHash());

        return $this->json(array(
            'id'    => $id,
            'liked' => $result['liked'] ? true : false,
            'likes' => intval($result['likes']),
        ));
    }

    /**
     * 评论：POST /qingyan/api/comment
     *
     * @return void
     */
    protected function actionComment()
    {
        if (!Qingyan_Plugin::boolOpt('commentOn', true)) {
            return $this->json(array(), 403, '评论功能已关闭');
        }

        if (!$this->guard()) {
            return $this->json(array(), 403, '请求校验失败，请刷新页面后重试');
        }

        $id = intval($this->input('id', 0));

        if ($id <= 0) {
            return $this->json(array(), 400, '参数错误');
        }

        $post = Qingyan_Model::get($id);

        if (!$post || 'publish' !== $post['status']) {
            return $this->json(array(), 404, '内容不存在或不可评论');
        }

        $user    = $this->identity();
        $needLog = Qingyan_Plugin::boolOpt('commentLogin', false);

        if ($needLog && !$user['isLogin']) {
            return $this->json(array(), 403, '请先登录后再评论');
        }

        $text = trim((string) $this->input('text', ''));

        if ('' === $text) {
            return $this->json(array(), 400, '评论内容不能为空');
        }

        if (function_exists('mb_substr')) {
            $text = mb_substr($text, 0, self::MAX_COMMENT, 'UTF-8');
        } else {
            $text = substr($text, 0, self::MAX_COMMENT);
        }

        $author = trim((string) $this->input('author', ''));
        $mail   = trim((string) $this->input('mail', ''));
        $url    = trim((string) $this->input('url', ''));

        if ($user['isLogin']) {
            $author = $user['name'];
            $mail   = $user['mail'];
        } else {
            if ('' === $author) {
                return $this->json(array(), 400, '请填写昵称');
            }

            if ('' !== $mail && !$this->isMail($mail)) {
                return $this->json(array(), 400, '邮箱格式不正确');
            }

            // 频率限制
            $interval = max(0, Qingyan_Plugin::intOpt('commentInterval', 30));

            if ($interval > 0) {
                $last = Qingyan_Model::lastCommentTime(Qingyan_Model::ip());

                if ($last > 0 && Qingyan_Model::time() - $last < $interval) {
                    return $this->json(array(), 429, '评论太频繁了，请稍后再试');
                }
            }

            Typecho_Cookie::set('__typecho_remember_author', $author, Qingyan_Model::time() + 2592000);
            Typecho_Cookie::set('__typecho_remember_mail', $mail, Qingyan_Model::time() + 2592000);
        }

        if ('' !== $url && !preg_match('/^https?:\/\/[^\s]+$/i', $url)) {
            $url = '';
        }

        $needReview = Qingyan_Plugin::boolOpt('commentReview', true);
        $status     = ($user['isAdmin'] || !$needReview) ? 'approved' : 'pending';

        $commentId = Qingyan_Model::addComment($id, array(
            'parent' => 0,
            'author' => $author,
            'mail'   => $mail,
            'url'    => $url,
            'ip'     => Qingyan_Model::ip(),
            'agent'  => $this->agent(),
            'status' => $status,
            'text'   => $text,
        ));

        $count = intval(Qingyan_Model::recountComments($id));
        $row   = array(
            'id'     => $commentId,
            'qid'    => $id,
            'author' => $author,
            'mail'   => $mail,
            'text'   => $text,
            'created' => Qingyan_Model::time(),
        );

        return $this->json(array(
            'id'       => $commentId,
            'count'    => $count,
            'status'   => $status,
            'html'     => $this->commentHtml($row, $user['isAdmin']),
        ), 0, 'approved' === $status ? '评论成功' : '评论已提交，等待审核');
    }

    /**
     * 评论列表：GET /qingyan/api/comments?id=1
     *
     * @return void
     */
    protected function actionComments()
    {
        $id = intval($this->input('id', 0));

        if ($id <= 0) {
            return $this->json(array(), 400, '参数错误');
        }

        // 与 like / comment 保持一致：不存在或非 publish 的内容不返回评论
        $post = Qingyan_Model::get($id);

        if (!$post || 'publish' !== $post['status']) {
            return $this->json(array('id' => $id, 'count' => 0, 'html' => ''), 404, '内容不存在');
        }

        $user     = $this->identity();
        $comments = Qingyan_Model::comments($id, true);
        $html     = '';

        foreach ($comments as $row) {
            $html .= $this->commentHtml($row, $user['isAdmin']);
        }

        return $this->json(array(
            'id'    => $id,
            'count' => count($comments),
            'html'  => $html,
        ));
    }

    /**
     * 删除：POST /qingyan/api/delete（仅管理员）
     *
     * @return void
     */
    protected function actionDelete()
    {
        if (!$this->guard()) {
            return $this->json(array(), 403, '请求校验失败，请刷新页面后重试');
        }

        $user = $this->identity();

        if (!$user['isAdmin']) {
            return $this->json(array(), 403, '没有权限');
        }

        /*
         * 【服务端也要拦】设置里关掉了前台删除，就算有人手工构造请求也不许删。
         * 只藏按钮不拦接口 = 半吊子安全。
         */
        if (!Qingyan_Plugin::frontDeleteOn()) {
            return $this->json(array(), 403, '前台删除功能已关闭，请到后台「管理 → 轻言」操作');
        }

        $id     = intval($this->input('id', 0));
        $target = $this->input('type', 'post');

        if ($id <= 0) {
            return $this->json(array(), 400, '参数错误');
        }

        if ('comment' === $target) {
            Qingyan_Model::deleteComment($id);

            return $this->json(array('id' => $id), 0, '评论已删除');
        }

        Qingyan_Model::delete($id);

        return $this->json(array('id' => $id), 0, '已删除');
    }

    /* ------------------------------------------------------------------ */
    /* 辅助方法                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * 读取路由参数或请求参数
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    protected function param($key, $default = NULL)
    {
        try {
            $value = $this->parameter->{$key};

            if (NULL !== $value && '' !== $value) {
                return $value;
            }
        } catch (Throwable $e) {
            // 忽略，走请求参数
        }

        return $this->request->get($key, $default);
    }

    /**
     * 当前 HTTP 方法（大写）
     *
     * @return string
     */
    protected function method()
    {
        return strtoupper(isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET');
    }

    /**
     * 读取请求体参数（同时支持 JSON 与表单）
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    protected function input($key, $default = NULL)
    {
        $body = $this->body();

        if (is_array($body) && array_key_exists($key, $body)) {
            return $body[$key];
        }

        return $this->request->get($key, $default);
    }

    /**
     * 解析请求体
     *
     * @return array
     */
    protected function body()
    {
        static $data = NULL;

        if (NULL !== $data) {
            return $data;
        }

        $data = array();
        $raw  = @file_get_contents('php://input');

        if (is_string($raw) && '' !== trim($raw)) {
            $json = json_decode($raw, true);

            if (is_array($json)) {
                $data = $json;
            } else {
                $tmp = array();
                parse_str($raw, $tmp);

                if (is_array($tmp)) {
                    $data = $tmp;
                }
            }
        }

        if (empty($data) && !empty($_POST)) {
            $data = $_POST;
        }

        return $data;
    }

    /**
     * 请求校验：会话 CSRF Token + 严格同源（Origin，缺失时看 Referer）
     *
     * 规则：
     *   1. session 里有 token 时，必须与请求携带的 token hash_equals 相等，
     *      并且请求必须同源；
     *   2. session 不可用（被缓存/CDN 剥离）时，不再退化为「只看 Referer
     *      的 host」，而是要求严格同源的 Origin（缺失时用 Referer），
     *      并且 scheme + host 都要与本站一致。
     *
     * 前端 assets/qingyan.js 走的是 POST + X-Qingyan-Token + token 字段，
     * 浏览器同源 XHR 会带上 Origin，所以正常流程不受影响。
     *
     * @return bool
     */
    protected function guard()
    {
        $this->ensureSession();

        $token        = (string) $this->input('token', '');
        $sessionToken = isset($_SESSION['qingyan_token']) ? (string) $_SESSION['qingyan_token'] : '';

        if ('' === $token && isset($_SERVER['HTTP_X_QINGYAN_TOKEN'])) {
            $token = (string) $_SERVER['HTTP_X_QINGYAN_TOKEN'];
        }

        $tokenOk    = ('' !== $sessionToken && '' !== $token && hash_equals($sessionToken, $token));
        $sameOrigin = $this->sameOrigin();

        if ('' !== $sessionToken) {
            return $tokenOk && $sameOrigin;
        }

        // session 不可用：要求严格同源
        return $sameOrigin;
    }

    /**
     * 请求是否来自本站
     *
     * 优先校验 Origin；没有 Origin 时退回 Referer。
     * 比较的是 scheme + host（含端口），而不是只比 host。
     *
     * @return bool
     */
    protected function sameOrigin()
    {
        $origin = isset($_SERVER['HTTP_ORIGIN']) ? trim((string) $_SERVER['HTTP_ORIGIN']) : '';

        if ('' === $origin) {
            $origin = isset($_SERVER['HTTP_REFERER']) ? trim((string) $_SERVER['HTTP_REFERER']) : '';
        }

        if ('' === $origin) {
            return false;
        }

        $expected = $this->requestOrigin();

        if ('' === $expected) {
            return false;
        }

        $parts = @parse_url($origin);

        if (empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }

        $actual = strtolower((string) $parts['scheme']) . '://' . strtolower((string) $parts['host'])
            . (isset($parts['port']) ? ':' . intval($parts['port']) : '');

        return hash_equals(strtolower($expected), $actual);
    }

    /**
     * 本站自身的 origin（scheme://host[:port]）
     *
     * @return string
     */
    protected function requestOrigin()
    {
        try {
            $prefix = (string) $this->request->getUrlPrefix();

            if ('' !== $prefix) {
                return rtrim($prefix, '/');
            }
        } catch (Throwable $e) {
            // 退回手工拼装
        }

        $host = isset($_SERVER['HTTP_HOST']) ? trim((string) $_SERVER['HTTP_HOST']) : '';

        if ('' === $host) {
            $host = isset($_SERVER['SERVER_NAME']) ? trim((string) $_SERVER['SERVER_NAME']) : '';
        }

        if ('' === $host) {
            return '';
        }

        $scheme = (!empty($_SERVER['HTTPS']) && 'off' !== strtolower((string) $_SERVER['HTTPS'])) ? 'https' : 'http';

        return $scheme . '://' . $host;
    }

    /**
     * 确保 session 可用
     *
     * @return void
     */
    protected function ensureSession()
    {
        if (PHP_SESSION_ACTIVE === session_status()) {
            return;
        }

        if (class_exists('Typecho_Session')) {
            if (!Typecho_Session::isStarted()) {
                Typecho_Session::start();
            }

            return;
        }

        @session_start();
    }

    /**
     * 当前访问者身份
     *
     * @return array
     */
    protected function identity()
    {
        $result = array(
            'isLogin' => false,
            'isAdmin' => false,
            'uid'     => 0,
            'name'    => '',
            'mail'    => '',
        );

        $this->ensureSession();

        try {
            $user = Typecho_Widget::widget('Widget_User');

            if ($user && $user->hasLogin()) {
                $result['isLogin'] = true;
                $result['isAdmin'] = $user->pass('administrator', true);
                $result['uid']     = intval($user->uid);
                $result['name']    = $user->screenName ? $user->screenName : $user->name;
                $result['mail']    = isset($user->mail) ? $user->mail : '';
            }
        } catch (Throwable $e) {
            // 按游客处理
        }

        return $result;
    }



    /**
     * 邮箱格式校验
     *
     * @param string $mail
     * @return bool
     */
    protected function isMail($mail)
    {
        return (bool) preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $mail);
    }

    /**
     * 客户端 UA（截断存储）
     *
     * @return string
     */
    protected function agent()
    {
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';

        return substr($ua, 0, 255);
    }

    /**
     * 渲染一条评论的 HTML 片段
     *
     * @param array $row
     * @param bool  $isAdmin
     * @return string
     */
    protected function commentHtml($row, $isAdmin = false)
    {
        $avatar = Qingyan_Plugin::boolOpt('avatar', true)
            ? '<img class="qy-comment-avatar" src="' . Qingyan_Renderer::esc(Qingyan_Model::avatar($row['mail'], 64)) . '" alt="" loading="lazy">'
            : '';

        // 只有「管理员 + 设置里开了前台删除」才渲染按钮
        $del = ($isAdmin && Qingyan_Plugin::frontDeleteOn())
            ? '<button type="button" class="qy-mini qy-mini-danger" data-act="del-comment" data-id="' . intval($row['id']) . '">删除</button>'
            : '';

        $html  = '<li class="qy-comment" data-id="' . intval($row['id']) . '">';
        $html .= $avatar;
        $html .= '<div class="qy-comment-body">';
        $html .= '<div class="qy-comment-meta">';
        $html .= '<span class="qy-comment-author">' . Qingyan_Renderer::esc($row['author']) . '</span>';
        $html .= '<time class="qy-comment-time">' . Qingyan_Renderer::esc(Qingyan_Model::timeAgo($row['created'])) . '</time>';
        $html .= $del;
        $html .= '</div>';
        $html .= '<div class="qy-comment-text">' . Qingyan_Model::format($row['text']) . '</div>';
        $html .= '</div></li>';

        return $html;
    }

    /**
     * 输出 JSON 并结束请求
     *
     * @param array  $data
     * @param int    $code 0 表示成功
     * @param string $msg
     * @return void
     */
    protected function json($data = array(), $code = 0, $msg = '')
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
            header('X-Content-Type-Options: nosniff');
        }

        $json = json_encode(
            array('code' => intval($code), 'msg' => (string) $msg, 'data' => NULL === $data ? array() : $data),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if (false === $json) {
            $json = '{"code":500,"msg":"数据编码失败","data":[]}';
        }

        echo $json;
        exit;
    }
}
