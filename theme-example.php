<?php
/**
 * qingyan
 *
 * @package custom
 *
 * 轻言 - 主题模板示例（嵌入模式）
 *
 */

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

$this->need('header.php');
?>

<article class="post qy-archive" itemscope itemtype="http://schema.org/BlogPosting">
    <h1 class="post-title" itemprop="name headline"><?php $this->title(); ?></h1>
    <?php if (class_exists('Qingyan_Plugin')): ?>
        <?php Qingyan_Plugin::render(array('size' => 10)); ?>
    <?php endif; ?>
</article>

<?php $this->need('comments.php'); ?>
<?php $this->need('sidebar.php'); ?>
<?php $this->need('footer.php'); ?>
