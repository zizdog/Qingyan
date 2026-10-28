<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

require_once dirname(__FILE__) . '/compat.php';

/**
 * 轻言 - 渲染器
 *
 * 把时间线渲染成一段 HTML 片段，供主题模板里的 Qingyan_Plugin::render() 调用。
 * 插件不提供独立的前台页面，页面外壳完全由主题负责。
 *
 * 若需要自定义 markup，请用 Qingyan_Plugin::renderList() 取数据后自行循环。
 *
 * @package Qingyan
 * @author  Yuanbao
 * @version 2.8.7
 */
class Qingyan_Renderer
{
    /**
     * 输出完整时间线片段（含样式、脚本、灯箱 DOM）
     *
     * @param int   $page 页码
     * @param array $opts 可选参数：
     *                    size       每页条数，默认取插件配置
     *                    editor     是否显示发布框，默认自动（管理员或开放游客投稿时显示）
     *                    baseUrl    分页与刷新用的基地址，默认为当前页面地址
     *                    css        是否引入自带样式，默认 true
     * @return void
     */
    /**
     * 取某一页的数据（列表 + 分页信息），支持「搜索模式」
     *
     * timeline()（整页）与 fragment()（ajax 翻页）都要这段逻辑，
     * 抽出来免得两处各写一遍、以后改一边忘一边。
     *
     * @param int    $page
     * @param int    $size
     * @param string $keyword 搜索词，空串 = 正常时间线
     * @return array array($page, $pages, $total, $list, $searching)
     */
    protected static function fetchPage($page, $size, $keyword = '')
    {
        $keyword   = trim((string) $keyword);
        $searching = ('' !== $keyword);

        $total = $searching ? Qingyan_Model::searchCount($keyword) : Qingyan_Model::count('publish');
        $pages = max(1, intval(ceil($total / $size)));
        $page  = min(max(1, intval($page)), $pages);

        if (0 === $total) {
            $list = array();
        } elseif ($searching) {
            $list = Qingyan_Model::search($keyword, $size, ($page - 1) * $size);
        } else {
            $list = Qingyan_Model::page($page, $size, 'publish');
        }

        return array($page, $pages, $total, $list, $searching);
    }

    public static function timeline($page = 1, $opts = array())
    {
        $size    = isset($opts['size']) ? max(1, min(50, intval($opts['size']))) : max(1, min(50, Qingyan_Plugin::intOpt('pageSize', 10)));

        /*
         * 搜索模式（只列命中的那几条）：
         *   · 独立页 /qingyan?q= 与 ajax 翻页由 Front / Api 显式传 keyword；
         *   · **主题里的嵌入页**（例如 says.html 调 render()）没传 keyword，
         *     这时也认一下 URL 上的 ?q= —— 搜索结果页那句「在轻言里看全部」
         *     就是跳到嵌入页的，不认的话点过去会看到未过滤的时间线。
         */
        if (array_key_exists('keyword', $opts)) {
            $keyword = trim((string) $opts['keyword']);
        } else {
            $keyword = '';

            try {
                $keyword = trim((string) Typecho_Request::getInstance()->get('q', ''));
            } catch (Throwable $e) {
                $keyword = '';
            }
        }

        list($page, $pages, $total, $list, $searching) = self::fetchPage($page, $size, $keyword);

        $ids     = self::idsOf($list);

        $identity = self::identity();
        $liked    = self::likedMap($ids, Qingyan_Model::visitorHash());
        $comments = Qingyan_Plugin::boolOpt('commentOn', true) ? Qingyan_Model::commentsMap($ids) : array();

        // 仅管理员可发布，不开放游客投稿
        $canPost = $identity['isAdmin'];

        if (array_key_exists('editor', $opts)) {
            $showEditor = (bool) $opts['editor'];
        } else {
            $showEditor = $canPost;
        }

        $ctx = array(
            'list'      => $list,
            'comments'  => $comments,
            'liked'     => $liked,
            'identity'  => $identity,
            'page'      => $page,
            'pages'     => $pages,
            'total'     => $total,
            'keyword'   => $keyword,
            'searching' => $searching,
            'isAdmin'   => $identity['isAdmin'],
            // 删除入口 = 管理员 **且** 设置里开了前台删除（默认关）
            'canDelete' => $identity['isAdmin'] && Qingyan_Plugin::frontDeleteOn(),
            'canPost'   => $canPost,
            'showEditor' => $showEditor,
            'likeOn'    => Qingyan_Plugin::boolOpt('likeOn', true),
            'commentOn' => Qingyan_Plugin::boolOpt('commentOn', true),
            'avatarOn'  => Qingyan_Plugin::boolOpt('avatar', true),
            'token'     => self::token(),
            'baseUrl'   => isset($opts['baseUrl']) ? (string) $opts['baseUrl'] : self::currentUrl(),
        );

        // 样式：嵌入模式默认引入，主题想完全自定义样式时传 css => false
        // 与自定义模板共用 outputCss / outputJs，保证两边输出一致
        self::outputCss(!array_key_exists('css', $opts) || $opts['css']);

        echo '<div class="qy-embed" id="qy-embed">' . "\n";

        // 搜索模式下不显示发布框：用户此刻要的是结果，不是发新内容
        if ($showEditor && !$searching) {
            self::editor($ctx);
        }

        echo '<div class="qy-timeline" id="qy-timeline">' . "\n";

        if ($searching) {
            self::searchHead($ctx);
        }

        if (empty($list)) {
            self::emptyState($keyword);
        } else {
            foreach ($list as $item) {
                self::item($item, $ctx);
            }
        }

        echo '</div>' . "\n";

        self::pager($ctx);

        echo '</div>' . "\n";

        self::outputJs($ctx);
    }

    /**
     * 空状态
     *
     * @return void
     */
    protected static function emptyState($keyword = '')
    {
        if ('' !== $keyword) {
            echo '<div class="qy-empty">'
                . '<p>轻言里没有找到包含「' . self::esc($keyword) . '」的内容。</p>'
                . '<p class="qy-empty-hint"><a href="'
                . self::esc(Qingyan_Model::siteSearchUrl($keyword)) . '">在文章里搜「'
                . self::esc($keyword) . '」 &rarr;</a></p>'
                . '</div>' . "\n";

            return;
        }

        echo '<div class="qy-empty"><p>这里还很安静，写下第一条吧。</p></div>' . "\n";
    }

    /**
     * 搜索模式下，列表顶部那条说明（关键词 / 命中数 / 清除 / 去文章里搜）
     *
     * @param array $ctx
     * @return void
     */
    protected static function searchHead($ctx)
    {
        ?>
        <div class="qy-search-head" id="qy-search-head">
            <span class="qy-search-head-label">在轻言里搜：<b><?php echo self::esc($ctx['keyword']); ?></b></span>
            <span class="qy-search-head-count"><?php echo intval($ctx['total']); ?> 条</span>
            <a class="qy-search-head-clear" href="<?php echo self::esc(Qingyan_Plugin::frontUrl()); ?>">清除</a>
            <a class="qy-search-head-site" href="<?php echo self::esc(Qingyan_Model::siteSearchUrl($ctx['keyword'])); ?>">也在文章里搜 &rarr;</a>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------ */
    /* 片段：发布框                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * 发布框
     *
     * @param array $ctx
     * @return void
     */
    protected static function editor($ctx)
    {
        $identity = $ctx['identity'];
        ?>
        <section class="qy-editor" id="qy-editor">
            <div class="qy-editor-head">
                <span class="qy-badge">管理员</span>
                <span class="qy-hint">支持 <code>**粗体**</code>、<code>`代码`</code>、<code>#话题#</code>，链接会自动识别</span>
            </div>
            <textarea class="qy-textarea" id="qy-content" rows="3" placeholder="此刻的想法……"></textarea>
            <div class="qy-moods" id="qy-moods">
                <?php
                // 「头像」那一档直接放当前用户的头像缩略图，让人一眼看懂选了会是什么
                $qySelfAvatar = Qingyan_Model::avatar($identity['mail'], 64);

                foreach (Qingyan_Model::moods() as $qyMoodKey => $qyMood) {
                    $qyMoodSrc = '' === $qyMood['file']
                        ? $qySelfAvatar
                        : Qingyan_Model::moodImageUrl($qyMood['file']);
                ?>
                <label class="qy-mood-pick" title="<?php echo self::esc($qyMood['label']); ?>">
                    <input type="radio" name="qy-mood" value="<?php echo self::esc($qyMoodKey); ?>"<?php echo Qingyan_Model::MOOD_DEFAULT === $qyMoodKey ? ' checked' : ''; ?>>
                    <img class="qy-mood-ico" src="<?php echo self::esc($qyMoodSrc); ?>" alt="<?php echo self::esc($qyMood['label']); ?>">
                    <span class="qy-mood-name"><?php echo self::esc($qyMood['label']); ?></span>
                </label>
                <?php } ?>
            </div>
            <?php if (Qingyan_Plugin::boolOpt('emojiOn', true)) { ?>
            <div class="qy-emoji" id="qy-emoji" hidden>
                <?php foreach (Qingyan_Model::emojiPanel() as $code => $char) { ?>
                <button type="button" class="qy-emoji-item" data-emoji="<?php echo self::esc($char); ?>" title="<?php echo self::esc($code); ?>"><?php echo $char; ?></button>
                <?php } ?>
            </div>
            <?php } ?>
            <div class="qy-editor-bar">
                <?php if (Qingyan_Plugin::boolOpt('emojiOn', true)) { ?>
                <button type="button" class="qy-btn qy-btn-ghost" id="qy-toggle-emoji">表情</button>
                <?php } ?>
                <button type="button" class="qy-btn qy-btn-ghost" data-qy-quote="qy-content" title="把选中内容变成引用（也可以自己以 > 开头）">引用</button>
                <span class="qy-counter" id="qy-counter">0 / 2000</span>
                <button type="button" class="qy-btn qy-btn-primary" id="qy-submit">发布</button>
            </div>
        </section>
        <?php
    }

    /* ------------------------------------------------------------------ */
    /* 片段：单条内容                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * 单条内容
     *
     * @param array $item
     * @param array $ctx
     * @return void
     */
    protected static function item($item, $ctx)
    {
        $id        = intval($item['id']);
        $author    = '' === $item['author'] ? '轻言' : $item['author'];
        $isLiked   = !empty($ctx['liked'][$id]);
        $comments  = isset($ctx['comments'][$id]) ? $ctx['comments'][$id] : array();
        ?>
        <article class="qy-item" id="qy-<?php echo $id; ?>" data-id="<?php echo $id; ?>">
            <?php
            /*
             * 卡片左侧那个位置由这条内容的心情档位决定：
             *   avatar（默认）→ 显示发布者头像
             *   其余档位       → 显示 assets/emoji/ 下对应的预置心情图
             * 两种情况都是 40px 圆形 <img>，所以沿用 .qy-avatar 的布局和样式。
             */
            $qyMood    = Qingyan_Model::moodOf($item);
            $qyMoodSrc = $qyMood['avatar']
                ? Qingyan_Model::avatar($item['authorMail'], 96)
                : $qyMood['img'];
            ?>
            <div class="qy-avatar qy-mood" data-mood="<?php echo self::esc($qyMood['key']); ?>"
                 title="<?php echo self::esc('心情：' . $qyMood['label']); ?>">
                <img src="<?php echo self::esc($qyMoodSrc); ?>" alt="<?php echo self::esc($qyMood['label']); ?>" loading="lazy">
            </div>

            <div class="qy-main">
                <div class="qy-item-head">
                    <span class="qy-author"><?php echo self::esc($author); ?></span>
                    <time class="qy-time" datetime="<?php echo date('c', intval($item['created'])); ?>" title="<?php echo date('Y-m-d H:i', intval($item['created'])); ?>">
                        <?php echo self::esc(Qingyan_Model::timeAgo($item['created'])); ?>
                    </time>
                    <?php if (!empty($ctx['canDelete'])) { ?>
                    <button type="button" class="qy-mini qy-mini-danger" data-act="delete" data-id="<?php echo $id; ?>">删除</button>
                    <?php } ?>
                </div>

                <?php if ('' !== trim($item['content'])) { ?>
                <div class="qy-content"><?php echo Qingyan_Model::format($item['content']); ?></div>
                <?php } ?>

                <?php
                $qyImages = Qingyan_Model::imagesOf($item);

                if (!empty($qyImages)) {
                    ?>
                <div class="qy-images qy-images-<?php echo count($qyImages) > 1 ? 'multi' : 'single'; ?>">
                    <?php foreach ($qyImages as $qyRel) {
                        $qyImgUrl = Qingyan_Model::imageUrl($qyRel);

                        if ('' === $qyImgUrl) {
                            continue;
                        }
                        ?>
                    <a class="qy-image" href="<?php echo self::esc($qyImgUrl); ?>" target="_blank" rel="noopener nofollow">
                        <img src="<?php echo self::esc($qyImgUrl); ?>" alt="" loading="lazy" decoding="async">
                    </a>
                    <?php } ?>
                </div>
                    <?php
                }
                ?>

                <div class="qy-actions">
                    <?php if ($ctx['likeOn']) { ?>
                    <button type="button" class="qy-act qy-like<?php echo $isLiked ? ' is-liked' : ''; ?>" data-act="like" data-id="<?php echo $id; ?>">
                        <span class="qy-icon"><?php echo $isLiked ? '&#9829;' : '&#9825;'; ?></span>
                        <span class="qy-like-num"><?php echo intval($item['likes']); ?></span>
                    </button>
                    <?php } ?>
                    <?php if ($ctx['commentOn']) { ?>
                    <button type="button" class="qy-act qy-comment-toggle" data-act="toggle-comments" data-id="<?php echo $id; ?>">
                        <span class="qy-icon">&#128172;</span>
                        <span class="qy-comment-num"><?php echo intval($item['commentsNum']); ?></span>
                    </button>
                    <?php } ?>
                </div>

                <?php if ($ctx['commentOn']) { ?>
                <?php self::commentSection($comments, $id, $ctx); ?>
                <?php } ?>
            </div>
        </article>
        <?php
    }

    /**
     * 评论区（列表 + 表单）
     *
     * 抽成独立方法，这样自定义输出时可以直接复用这段 HTML，
     * 不必为了评论交互自己重写一套。
     *
     * @param array $comments
     * @param int   $id
     * @param array $ctx
     * @return void
     */
    protected static function commentSection($comments, $id, $ctx)
    {
        $comments = (array) $comments;
        ?>
                <div class="qy-comments" id="qy-comments-<?php echo $id; ?>" hidden>
                    <ul class="qy-comment-list">
                        <?php foreach ($comments as $comment) { ?>
                        <li class="qy-comment" data-id="<?php echo intval($comment['id']); ?>">
                            <?php if ($ctx['avatarOn']) { ?>
                            <img class="qy-comment-avatar" src="<?php echo self::esc(Qingyan_Model::avatar($comment['mail'], 64)); ?>" alt="" loading="lazy">
                            <?php } ?>
                            <div class="qy-comment-body">
                                <div class="qy-comment-meta">
                                    <span class="qy-comment-author"><?php echo self::esc($comment['author']); ?></span>
                                    <time class="qy-comment-time"><?php echo self::esc(Qingyan_Model::timeAgo($comment['created'])); ?></time>
                                    <?php if (!empty($ctx['canDelete'])) { ?>
                                    <button type="button" class="qy-mini" data-act="del-comment" data-id="<?php echo intval($comment['id']); ?>">删除</button>
                                    <?php } ?>
                                </div>
                                <div class="qy-comment-text"><?php echo Qingyan_Model::format($comment['text']); ?></div>
                            </div>
                        </li>
                        <?php } ?>
                        <?php if (empty($comments)) { ?>
                        <li class="qy-comment-empty">还没有评论</li>
                        <?php } ?>
                    </ul>

                    <form class="qy-comment-form" data-id="<?php echo $id; ?>">
                        <?php if (!$ctx['identity']['isLogin']) { ?>
                        <div class="qy-row">
                            <input class="qy-input qy-input-sm" type="text" name="author" placeholder="昵称" maxlength="32" value="<?php echo self::esc($ctx['identity']['name']); ?>">
                            <input class="qy-input qy-input-sm" type="email" name="mail" placeholder="邮箱（选填）" maxlength="128" value="<?php echo self::esc($ctx['identity']['mail']); ?>">
                        </div>
                        <?php } ?>
                        <textarea class="qy-textarea qy-textarea-sm" name="text" rows="2" placeholder="说点什么……" maxlength="500"></textarea>
                        <div class="qy-editor-bar">
                            <button type="button" class="qy-btn qy-btn-ghost qy-btn-sm" data-qy-quote="closest" title="把选中内容变成引用（也可以自己以 &gt; 开头）">引用</button>
                            <span class="qy-msg"></span>
                            <button type="submit" class="qy-btn qy-btn-primary qy-btn-sm">发送</button>
                        </div>
                    </form>
                </div>
        <?php
    }

    /* ------------------------------------------------------------------ */
    /* 片段：分页                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * 分页
     *
     * 嵌入模式下页码走 ?page=N，避免与主题的路由冲突。
     *
     * @param array $ctx
     * @return void
     */
    protected static function pager($ctx)
    {
        $page  = intval($ctx['page']);
        $pages = intval($ctx['pages']);

        if ($pages <= 1) {
            return;
        }

        $base  = $ctx['baseUrl'];
        $url   = function ($n) use ($base) {
            $sep = (false === strpos($base, '?')) ? '?' : '&';

            return $base . (1 === $n ? '' : $sep . 'page=' . $n);
        };

        $items = self::pagerItems($page, $pages, $url);
        ?>
        <nav class="qy-pager" id="qy-pager">
            <?php foreach ($items as $item) { ?>
                <?php if ('gap' === $item['type']) { ?>
                <span class="qy-gap">…</span>
                <?php } elseif ('prev' === $item['type'] || 'next' === $item['type']) { ?>
                    <?php if (!empty($item['disabled'])) { ?>
                    <span class="qy-page is-disabled"><?php echo self::esc($item['label']); ?></span>
                    <?php } else { ?>
                    <a class="qy-page" href="<?php echo self::esc($item['url']); ?>" data-page="<?php echo intval($item['page']); ?>"><?php echo self::esc($item['label']); ?></a>
                    <?php } ?>
                <?php } elseif (!empty($item['current'])) { ?>
                <span class="qy-page is-current" data-page="<?php echo intval($item['page']); ?>"><?php echo self::esc($item['label']); ?></span>
                <?php } else { ?>
                <a class="qy-page" href="<?php echo self::esc($item['url']); ?>" data-page="<?php echo intval($item['page']); ?>"><?php echo self::esc($item['label']); ?></a>
                <?php } ?>
            <?php } ?>
        </nav>
        <?php
    }

    /**
     * 生成分页条目
     *
     * 抽出来是为了让 Plugin::pagerData() 也能复用，
     * 方便主题完全自定义分页的 markup。
     *
     * @param int      $page
     * @param int      $pages
     * @param callable $url 页码 → URL
     * @return array
     */
    protected static function pagerItems($page, $pages, $url)
    {
        $page  = max(1, intval($page));
        $pages = max(1, intval($pages));
        $items = array();

        if ($pages <= 1) {
            return $items;
        }

        // 上一页
        $items[] = array(
            'type'     => 'prev',
            'label'    => '上一页',
            'url'      => $page > 1 ? $url($page - 1) : '',
            'current'  => false,
            'disabled' => $page <= 1,
            'page'     => max(1, $page - 1),
        );

        $start = max(1, $page - 2);
        $end   = min($pages, $start + 4);
        $start = max(1, $end - 4);

        if ($start > 1) {
            $items[] = array(
                'type' => 'page', 'label' => '1', 'url' => $url(1),
                'current' => false, 'disabled' => false, 'page' => 1,
            );

            if ($start > 2) {
                $items[] = array('type' => 'gap', 'label' => '…', 'url' => '', 'current' => false, 'disabled' => false, 'page' => 0);
            }
        }

        for ($i = $start; $i <= $end; $i++) {
            $items[] = array(
                'type' => 'page', 'label' => (string) $i, 'url' => $url($i),
                'current' => $i === $page, 'disabled' => false, 'page' => $i,
            );
        }

        if ($end < $pages) {
            if ($end < $pages - 1) {
                $items[] = array('type' => 'gap', 'label' => '…', 'url' => '', 'current' => false, 'disabled' => false, 'page' => 0);
            }

            $items[] = array(
                'type' => 'page', 'label' => (string) $pages, 'url' => $url($pages),
                'current' => false, 'disabled' => false, 'page' => $pages,
            );
        }

        // 下一页
        $items[] = array(
            'type'     => 'next',
            'label'    => '下一页',
            'url'      => $page < $pages ? $url($page + 1) : '',
            'current'  => false,
            'disabled' => $page >= $pages,
            'page'     => min($pages, $page + 1),
        );

        return $items;
    }

    /* ------------------------------------------------------------------ */
    /* 自定义输出：一次性打包数据 + 各部分 HTML                            */
    /* ------------------------------------------------------------------ */

    /**
     * 生成一份完整的「页面数据包」
     *
     * 这是给主题自定义输出用的核心方法：一次调用，返回列表数据、
     * 发布框 HTML、分页 HTML、资源 HTML 与分页参数。主题想放哪就放哪，
     * markup 与布局完全自主。
     *
     * @param array $opts size / page / css / baseUrl
     * @return array
     */
    /**
     * 渲染一页的「条目 + 分页」片段（ajax 翻页用）
     *
     * 与 snapshot() 的区别：
     *   - 不碰 outputCss / outputJs，那两处有 static 去重标记，
     *     在 ajax 请求里调用会把标记消耗掉，导致主页面后续拿不到资源。
     *   - 只返回列表相关的内容，体积更小。
     *
     * @param int   $page
     * @param array $opts size / baseUrl
     * @return array
     *   html   条目拼接后的 HTML（空列表时是空状态）
     *   pager  分页 HTML
     *   page / pages / total / empty
     */
    public static function fragment($page = 1, $opts = array())
    {
        $opts = is_array($opts) ? $opts : array();

        $size = isset($opts['size'])
            ? max(1, min(50, intval($opts['size'])))
            : max(1, min(50, Qingyan_Plugin::intOpt('pageSize', 10)));

        $keyword = isset($opts['keyword']) ? trim((string) $opts['keyword']) : '';

        list($page, $pages, $total, $list, $searching) = self::fetchPage($page, $size, $keyword);

        $ctx = self::buildContext($list, $page, $pages, $total, $size, $opts);

        $html = '';

        if (empty($list)) {
            $html = self::capture(function () use ($keyword) { self::emptyState($keyword); });
        } else {
            foreach ($list as $row) {
                $html .= self::capture(function () use ($row, $ctx) { self::item($row, $ctx); });
            }
        }

        return array(
            'html'  => $html,
            'pager' => self::capture(function () use ($ctx) { self::pager($ctx); }),
            'page'  => $page,
            'pages' => $pages,
            'total' => $total,
            'empty' => empty($list),
        );
    }

    /**
     * 渲染单条内容（ajax 发布后插入列表用）
     *
     * @param int   $id
     * @param array $opts baseUrl
     * @return string 找不到时返回空串
     */
    public static function itemHtml($id, $opts = array())
    {
        $id   = intval($id);
        $opts = is_array($opts) ? $opts : array();

        if ($id <= 0) {
            return '';
        }

        try {
            $row = Qingyan_Model::get($id);
        } catch (Throwable $e) {
            return '';
        }

        if (!$row || !is_array($row)) {
            return '';
        }

        // 待审核的内容不渲染给前台
        if (isset($row['status']) && 'publish' !== (string) $row['status']) {
            return '';
        }

        $list  = array($row);
        $ctx   = self::buildContext($list, 1, 1, 1, 10, $opts);

        return self::capture(function () use ($row, $ctx) { self::item($row, $ctx); });
    }

    /**
     * 构造渲染上下文
     *
     * timeline() / fragment() / itemHtml() 共用，
     * 保证不同入口渲染出的结构完全一致。
     *
     * @param array $list  本页数据
     * @param int   $page
     * @param int   $pages
     * @param int   $total
     * @param int   $size
     * @param array $opts  baseUrl / showEditor
     * @return array
     */
    protected static function buildContext($list, $page, $pages, $total, $size, $opts = array())
    {
        $opts = is_array($opts) ? $opts : array();

        $ids      = self::idsOf($list);
        $identity = self::identity();
        $liked    = self::likedMap($ids, Qingyan_Model::visitorHash());

        $comments = array();

        if (Qingyan_Plugin::boolOpt('commentOn', true) && !empty($ids)) {
            try {
                $comments = Qingyan_Model::commentsMap($ids);
            } catch (Throwable $e) {
                $comments = array();
            }
        }

        $canPost = $identity['isAdmin'];

        $showEditor = array_key_exists('editor', $opts) ? (bool) $opts['editor'] : $canPost;

        return array(
            'list'       => $list,
            'comments'   => $comments,
            'liked'      => $liked,
            'identity'   => $identity,
            'page'       => $page,
            'pages'      => $pages,
            'total'      => $total,
            'size'       => $size,
            'isAdmin'    => $identity['isAdmin'],
            'canPost'    => $canPost,
            'showEditor' => $showEditor,
            'likeOn'     => Qingyan_Plugin::boolOpt('likeOn', true),
            'commentOn'  => Qingyan_Plugin::boolOpt('commentOn', true),
            'avatarOn'   => Qingyan_Plugin::boolOpt('avatar', true),
            'token'      => self::token(),
            'baseUrl'    => isset($opts['baseUrl']) ? (string) $opts['baseUrl'] : self::currentUrl(),
            // 搜索模式：ajax 翻页要知道还在搜什么（见 qingyan.js 的 loadPage）
            'keyword'    => isset($opts['keyword']) ? trim((string) $opts['keyword']) : '',
        );
    }

    public static function snapshot($opts = array())
    {
        $opts = is_array($opts) ? $opts : array();

        $size = isset($opts['size'])
            ? max(1, min(50, intval($opts['size'])))
            : max(1, min(50, Qingyan_Plugin::intOpt('pageSize', 10)));

        $total = Qingyan_Model::count('publish');
        $pages = max(1, intval(ceil($total / $size)));

        $page = isset($opts['page'])
            ? intval($opts['page'])
            : (isset($_GET['page']) ? intval($_GET['page']) : 1);
        $page = min(max(1, $page), $pages);

        $identity = self::identity();
        $list     = $total > 0 ? Qingyan_Model::page($page, $size, 'publish') : array();
        $ids      = self::idsOf($list);

        $baseUrl = isset($opts['baseUrl']) ? (string) $opts['baseUrl'] : self::currentUrl();

        $ctx = array(
            'list'      => $list,
            'comments'  => Qingyan_Plugin::boolOpt('commentOn', true) ? Qingyan_Model::commentsMap($ids) : array(),
            'liked'     => self::likedMap($ids, Qingyan_Model::visitorHash()),
            'identity'  => $identity,
            'page'      => $page,
            'pages'     => $pages,
            'total'     => $total,
            'size'      => $size,
            'isAdmin'   => $identity['isAdmin'],
            'canPost'   => $identity['isAdmin'],
            'showEditor' => true,
            'likeOn'    => Qingyan_Plugin::boolOpt('likeOn', true),
            'commentOn' => Qingyan_Plugin::boolOpt('commentOn', true),
            'avatarOn'  => Qingyan_Plugin::boolOpt('avatar', true),
            'token'     => self::token(),
            'baseUrl'   => $baseUrl,
        );

        $items = array();

        foreach ($list as $row) {
            $data = self::itemData($row, $ctx);

            // 整条内容的现成 HTML（含评论区），与 render() 输出完全一致。
            // 想省事直接 echo；想改结构就用上面的字段自己拼。
            $data['itemHtml'] = self::capture(function () use ($row, $ctx) { self::item($row, $ctx); });

            $items[] = $data;
        }

        $css = !array_key_exists('css', $opts) || $opts['css'];

        // 注意：css / js 各只捕获一次，assets 由两者拼接而成。
        // 因为 outputCss / outputJs 内部有 static 去重标记，
        // 重复调用会拿到空串——对本插件是好事（避免脚本重复绑定事件），
        // 所以这里必须分别捕获再拼接，不能合并成一次调用。
        $cssHtml = self::capture(function () use ($css) { self::outputCss($css); });
        $jsHtml  = self::capture(function () use ($ctx) { self::outputJs($ctx); });

        return array(
            'list'      => $items,
            'editor'    => $ctx['canPost'] ? self::capture(function () use ($ctx) { self::editor($ctx); }) : '',
            'pager'     => self::capture(function () use ($ctx) { self::pager($ctx); }),
            'emptyHtml' => self::capture(function () { self::emptyState(); }),
            'css'       => $cssHtml,
            'js'        => $jsHtml,
            'assets'    => $cssHtml . $jsHtml,
            'pagerData' => self::pagerData($ctx),
            'canPost'   => $ctx['canPost'],
            'isAdmin'   => $ctx['isAdmin'],
            'page'      => $page,
            'pages'     => $pages,
            'total'     => $total,
            'size'      => $size,
            'empty'     => empty($items),
        );
    }

    /**
     * 单条内容 → 对外数据数组
     *
     * @param array $row
     * @param array $ctx
     * @return array
     */
    public static function itemData($row, $ctx)
    {
        $id     = intval($row['id']);
        $time   = intval($row['created']);
        $liked  = !empty($ctx['liked'][$id]);
        $author = '' === $row['author'] ? '轻言' : $row['author'];

        $comments = array();
        $rawComments = isset($ctx['comments'][$id]) ? $ctx['comments'][$id] : array();

        if (!empty($rawComments)) {
            foreach ($rawComments as $comment) {
                $ctime = intval($comment['created']);

                $comments[] = array(
                    'id'      => intval($comment['id']),
                    'author'  => $comment['author'],
                    'mail'    => isset($comment['mail']) ? $comment['mail'] : '',
                    'avatar'  => $ctx['avatarOn'] ? Qingyan_Model::avatar($comment['mail'], 64) : '',
                    'raw'     => $comment['text'],
                    'html'    => Qingyan_Model::format($comment['text']),
                    'created' => $ctime,
                    'ago'     => Qingyan_Model::timeAgo($ctime),
                    'time'    => date('Y-m-d H:i', $ctime),
                );
            }
        }

        return array(
            'id'          => $id,
            'author'      => $author,
            'mail'        => $row['authorMail'],
            'avatar'      => $ctx['avatarOn'] ? Qingyan_Model::avatar($row['authorMail'], 96) : '',
            'raw'         => $row['content'],
            'html'        => Qingyan_Model::format($row['content']),
            'created'     => $time,
            'ago'         => Qingyan_Model::timeAgo($time),
            'time'        => date('Y-m-d H:i', $time),
            'datetime'    => date('c', $time),
            'likes'       => intval($row['likes']),
            'commentsNum' => intval($row['commentsNum']),
            'liked'       => $liked,
            'status'      => $row['status'],
            'url'         => Qingyan_Plugin::frontUrl() . '#qy-' . $id,
            'images'      => self::imageData($row),
            'comments'    => $comments,
            // 现成的评论区 HTML（列表 + 表单，自带交互），
            // 不想自己渲染评论就直接输出它
            'commentsHtml' => $ctx['commentOn']
                ? self::capture(function () use ($rawComments, $id, $ctx) { self::commentSection($rawComments, $id, $ctx); })
                : '',
            'likeOn'      => $ctx['likeOn'],
            'commentOn'   => $ctx['commentOn'],
            'isAdmin'     => $ctx['isAdmin'],
        );
    }

    /**
     * 配图列表（rel + 可直接输出的 url）
     *
     * @param array $row
     * @return array
     */
    public static function imageData($row)
    {
        $result = array();

        foreach (Qingyan_Model::imagesOf($row) as $rel) {
            $url = Qingyan_Model::imageUrl($rel);

            if ('' !== $url) {
                $result[] = array('rel' => $rel, 'url' => $url);
            }
        }

        return $result;
    }

    /**
     * 分页参数（供主题自己写分页 markup）
     *
     * @param array $ctx
     * @return array
     */
    public static function pagerData($ctx)
    {
        $page  = intval($ctx['page']);
        $pages = intval($ctx['pages']);
        $base  = $ctx['baseUrl'];

        $url = function ($n) use ($base) {
            $sep = (false === strpos($base, '?')) ? '?' : '&';

            return $base . (1 === $n ? '' : $sep . 'page=' . $n);
        };

        return array(
            'page'  => $page,
            'pages' => $pages,
            'total' => intval($ctx['total']),
            'prev'  => $page > 1 ? $url($page - 1) : '',
            'next'  => $page < $pages ? $url($page + 1) : '',
            'items' => self::pagerItems($page, $pages, $url),
        );
    }

    /**
     * 输出样式（CSS link + 自定义 CSS）
     *
     * 与 render() 内部走同一段代码，保证自定义模板的输出与内置一致。
     *
     * @param bool $css 是否输出自带样式
     * @return void
     */
    public static function outputCss($css = true)
    {
        static $done = false;

        if ($done) {
            return;
        }

        $done = true;

        if ($css) {
            echo '<link rel="stylesheet" href="' . self::esc(Qingyan_Plugin::assetUrl('qingyan.css')) . '">' . "\n";
        }

        $customCss = trim((string) Qingyan_Plugin::opt('customCss', ''));

        if ('' !== $customCss) {
            echo '<style>' . strip_tags($customCss) . '</style>' . "\n";
        }
    }

    /**
     * 输出脚本（配置 + JS 文件）
     *
     * @param array $ctx
     * @return void
     */
    public static function outputJs($ctx)
    {
        static $done = false;

        if ($done) {
            return;
        }

        $done = true;

        echo '<script>window.Qingyan = ' . self::jsConfig($ctx) . ';</script>' . "\n";
        echo '<script src="' . self::esc(Qingyan_Plugin::assetUrl('qingyan.js')) . '"></script>' . "\n";
    }

    /**
     * 捕获一段输出的 HTML
     *
     * @param callable $callback
     * @return string
     */
    protected static function capture($callback)
    {
        ob_start();

        try {
            call_user_func($callback);
        } catch (Throwable $e) {
            // 出错也要收尾，不能把缓冲区漏出去
        }

        $html = ob_get_clean();

        return false === $html ? '' : $html;
    }

    /* ------------------------------------------------------------------ */
    /* 辅助                                                               */
    /* ------------------------------------------------------------------ */

    /**
     * ID 集合
     *
     * @param array $list
     * @return array
     */
    protected static function idsOf($list)
    {
        $ids = array();

        foreach ((array) $list as $item) {
            $ids[] = intval($item['id']);
        }

        return $ids;
    }

    /**
     * 当前访客点赞记录
     *
     * @param array  $ids
     * @param string $hash
     * @return array
     */
    protected static function likedMap($ids, $hash)
    {
        $result = array();
        $ids    = array_values(array_filter(array_map('intval', (array) $ids)));

        if (empty($ids) || '' === (string) $hash) {
            return $result;
        }

        try {
            $db   = qy_db();
            $mark = implode(',', $ids);
            $rows = $db->fetchAll(
                $db->select('id', 'liked')->from(Qingyan_Model::T_POST)
                    ->where("id IN ({$mark})")
            );

            foreach ((array) $rows as $row) {
                $list = Qingyan_Model::decodeJson(isset($row['liked']) ? $row['liked'] : '');

                if (in_array((string) $hash, $list, true)) {
                    $result[intval($row['id'])] = true;
                }
            }
        } catch (Throwable $e) {
            // 忽略
        }

        return $result;
    }

    /**
     * 当前访问者身份
     *
     * @return array
     */
    public static function identity()
    {
        $result = array(
            'isLogin' => false,
            'isAdmin' => false,
            'name'    => '',
            'mail'    => '',
        );

        try {
            $user = Typecho_Widget::widget('Widget_User');

            if ($user && $user->hasLogin()) {
                $result['isLogin'] = true;
                $result['isAdmin'] = $user->pass('administrator', true);
                $result['name']    = $user->screenName ? $user->screenName : $user->name;
                $result['mail']    = isset($user->mail) ? $user->mail : '';
            }
        } catch (Throwable $e) {
            // 按游客处理
        }

        if ('' === $result['name']) {
            try {
                $remembered = Typecho_Cookie::get('__typecho_remember_author');

                if ($remembered) {
                    $result['name'] = $remembered;
                }

                $rememberedMail = Typecho_Cookie::get('__typecho_remember_mail');

                if ($rememberedMail) {
                    $result['mail'] = $rememberedMail;
                }
            } catch (Throwable $e) {
                // 忽略
            }
        }

        return $result;
    }

    /**
     * 会话级 CSRF Token
     *
     * @return string
     */
    public static function token()
    {
        self::ensureSession();

        if (empty($_SESSION['qingyan_token'])) {
            $_SESSION['qingyan_token'] = md5(uniqid(mt_rand(), true) . Qingyan_Model::ip());
        }

        return $_SESSION['qingyan_token'];
    }

    /**
     * 确保 session 可用
     *
     * @return void
     */
    protected static function ensureSession()
    {
        if (PHP_SESSION_ACTIVE === session_status()) {
            return;
        }

        try {
            if (class_exists('Typecho_Session')) {
                if (!Typecho_Session::isStarted()) {
                    Typecho_Session::start();
                }

                return;
            }
        } catch (Throwable $e) {
            // 退回原生 session
        }

        @session_start();
    }

    /**
     * 当前页面地址（用于分页与刷新）
     *
     * @return string
     */
    public static function currentUrl()
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';

        if ('' === $uri) {
            return Qingyan_Plugin::frontUrl(1);
        }

        // 去掉已有的 page 参数，交给分页逻辑重新拼
        $parts = explode('?', $uri, 2);
        $path  = $parts[0];
        $query = isset($parts[1]) ? $parts[1] : '';

        if ('' !== $query) {
            $pairs = array();
            parse_str($query, $pairs);
            unset($pairs['page']);
            $query = http_build_query($pairs);
        }

        return $path . ('' === $query ? '' : '?' . $query);
    }

    /**
     * 前端脚本配置
     *
     * @param array $ctx
     * @return string JSON
     */
    protected static function jsConfig($ctx)
    {
        $config = array(
            'api'       => Qingyan_Plugin::apiUrl('json'),
            'base'      => $ctx['baseUrl'],
            'token'     => $ctx['token'],
            'isAdmin'   => $ctx['isAdmin'] ? true : false,
            'likeOn'    => $ctx['likeOn'] ? true : false,
            'commentOn' => $ctx['commentOn'] ? true : false,
            'page'      => intval($ctx['page']),
            'pages'     => intval($ctx['pages']),
            // 搜索状态下 ajax 翻页必须把关键词带上，否则会翻出「全部内容」
            'keyword'   => isset($ctx['keyword']) ? (string) $ctx['keyword'] : '',
            'emojiOn'   => Qingyan_Plugin::boolOpt('emojiOn', true) ? true : false,
            'emoji'     => Qingyan_Model::emojiPanel(),
        );

        $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (false === $json) {
            $json = '{}';
        }

        return str_replace('</', '<\\/', $json);
    }

    /**
     * 转义输出
     *
     * @param string $value
     * @return string
     */
    public static function esc($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
