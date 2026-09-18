<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

require_once dirname(__FILE__) . '/compat.php';
require_once dirname(__FILE__) . '/Plugin.php';
require_once dirname(__FILE__) . '/Renderer.php';

/**
 * 轻言 - 独立前台页面
 *
 * 路由 /qingyan（可配置），输出一个完整 HTML 页面。
 * 不依赖主题，启用后直接访问即可看到时间线。
 *
 * 与嵌入模式（Qingyan_Plugin::render()）共用 Renderer，
 * 所以两边的 DOM 结构完全一致，区别只在外壳：
 *   独立页：本文件输出 DOCTYPE / head / body
 *   嵌入页：由主题输出外壳，插件只输出 #qy-embed 那一段
 *
 * @package Qingyan
 * @author  Yuanbao
 * @version 1.0.0
 */
class Qingyan_Front extends Typecho_Widget
{
    /**
     * Widget 初始化
     *
     * @return void
     */
    public function execute()
    {
    }

    /**
     * 兜底动作
     *
     * @return void
     */
    public function action()
    {
        $this->dispatch();
    }

    /**
     * 路由入口
     *
     * @return void
     */
    public function dispatch()
    {
        $this->render();
    }

    /**
     * 输出完整页面
     *
     * @return void
     */
    public function render()
    {
        $page = max(1, intval($this->param('page', 1)));

        $options = Helper::options();
        $esc     = array('Qingyan_Renderer', 'esc');

        // 独立页面基址（不带页码），分页与 ajax 都基于它
        $baseUrl = Qingyan_Plugin::frontPageUrl();

        /*
         * 站内搜索页里那段「轻言里也有 N 条」的「看全部」就指到这里
         * （`{前台}/?q=关键词`）。带上 q 之后分页链接也会自动带上它
         * （见 Renderer::pager()：base 里已经有 ? 时会用 & 拼）。
         */
        $keyword = trim((string) $this->param('q', ''));
        $keyword = trim(preg_replace('/\s+/u', ' ', strip_tags($keyword)));
        $keyword = is_string($keyword) ? $keyword : '';

        if ('' !== $keyword) {
            $baseUrl .= (false === strpos($baseUrl, '?') ? '?' : '&') . 'q=' . urlencode($keyword);
        }

        $title = Qingyan_Plugin::opt('frontTitle', '');

        if ('' === trim((string) $title)) {
            $title = '轻言';
        }

        $siteName = (string) $options->title;

        header('Content-Type: text/html; charset=UTF-8');

        ?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo $esc($title); ?><?php echo '' === $siteName ? '' : ' - ' . $esc($siteName); ?></title>
<meta name="description" content="<?php echo $esc($title); ?>">
<link rel="stylesheet" href="<?php echo $esc(Qingyan_Plugin::assetUrl('front.css')); ?>">
</head>
<?php
/*
 * 【外壳类名必须与组件样式隔离】
 *
 * 外壳以前用的是 qy-page / qy-page-head / …，
 * 而 qingyan.css 里的 `.qy-page` 是**分页页码**：
 *     .qy-page { display:inline-flex; min-width:32px; height:32px; border:1px solid …; }
 * 两个「page」撞在一起，整页就成了一个 32px 高的 inline-flex 横排 ——
 * 标题 / 列表 / 页脚被并排挤在一起，看起来「样式全乱」。
 *
 * 现在外壳统一用 `qy-standalone*` 前缀，组件那套 `qy-*` 一个都不占。
 */
?>
<body class="qy-standalone-body">

<div class="qy-standalone">

    <header class="qy-standalone__head">
        <h1 class="qy-standalone__title"><?php echo $esc($title); ?></h1>
        <p class="qy-standalone__desc">记录在路上的只言片语</p>
        <nav class="qy-standalone__nav">
            <a href="<?php echo $esc($options->siteUrl); ?>">&larr; 返回<?php echo $esc($siteName); ?></a>
        </nav>

        <?php /*
         * 搜「轻言」的入口（?q=）。想搜全站文章就直接用站点的搜索框，
         * 或者点结果页/话题里的 `?s=` 链接 —— 那边会同时列出轻言的命中。
         */ ?>
        <form class="qy-standalone__search" method="get" action="<?php echo $esc(Qingyan_Plugin::frontPageUrl()); ?>" role="search">
            <input type="search" name="q" value="<?php echo $esc($keyword); ?>" placeholder="搜索轻言…" aria-label="搜索轻言">
            <button type="submit">搜索</button>
        </form>
    </header>

    <main class="qy-standalone__main">
        <?php Qingyan_Renderer::timeline($page, array('baseUrl' => $baseUrl, 'keyword' => $keyword)); ?>
    </main>

    <footer class="qy-standalone__foot">
        <p>Powered by <a href="https://typecho.org" target="_blank" rel="noopener">Typecho</a> · 轻言 Qingyan v<?php echo $esc(Qingyan_Plugin::VERSION); ?></p>
    </footer>

</div>

</body>
</html>
        <?php
    }

    /**
     * 读路由 / GET 参数
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    protected function param($key, $default = NULL)
    {
        try {
            $value = $this->request->get($key, NULL);

            if (NULL !== $value && '' !== $value) {
                return $value;
            }
        } catch (Throwable $e) {
            // 忽略
        }

        $fromRoute = isset($this->parameter->{$key}) ? $this->parameter->{$key} : NULL;

        return NULL === $fromRoute || '' === $fromRoute ? $default : $fromRoute;
    }
}
