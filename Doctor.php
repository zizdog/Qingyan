<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

require_once dirname(__FILE__) . '/compat.php';
require_once dirname(__FILE__) . '/Plugin.php';

/**
 * 轻言 - 环境自检
 *
 * 【为什么不放站点根目录】
 * 早期版本是独立的 qingyan-doctor.php，要手动上传到站点根目录。两个问题：
 *   1. Typecho 的前台路由会接管该请求，直接 404；
 *   2. 要手动传文件、用完还要记得删，容易遗留暴露环境信息的文件。
 *
 * 现在改成后台面板的一个页签：
 *   后台 → 管理 → 轻言 → 点「运行环境自检」
 * 路径是 admin/extending.php?panel=Qingyan/panel.php&action=doctor，
 * 完全在后台框架内，不受前台路由影响，也不需要额外传文件。
 *
 * @package Qingyan
 * @author  Yuanbao
 * @version 1.0.0
 */
class Qingyan_Doctor
{
    /**
     * 运行全部检查
     *
     * @return array 每项 {name, ok, value, fix}
     */
    public static function checks()
    {
        $rows = array();

        self::checkDir($rows);
        self::checkPhp($rows);
        self::checkClasses($rows);
        self::checkMethods($rows);
        self::checkTable($rows);
        self::checkConfig($rows);
        self::checkMenu($rows);
        self::checkApiUrl($rows);
        self::checkRoute($rows);
        self::checkActivated($rows);
        self::checkStats($rows);
        self::checkVersion($rows);

        return $rows;
    }

    /**
     * 记录一条结果
     *
     * @param array  $rows
     * @param string $name
     * @param bool   $ok
     * @param string $value
     * @param string $fix
     * @return void
     */
    protected static function row(&$rows, $name, $ok, $value, $fix = '')
    {
        $rows[] = array(
            'name'  => $name,
            'ok'    => (bool) $ok,
            'value' => (string) $value,
            'fix'   => (string) $fix,
        );
    }

    /**
     * 取可用的插件类名（1.2 与 1.3 不同）
     *
     * @return string
     */
    protected static function pluginClass()
    {
        if (class_exists('Qingyan_Plugin_Base')) {
            return 'Qingyan_Plugin_Base';
        }

        return qy_is_modern() ? 'TypechoPlugin\Qingyan\Plugin' : 'Qingyan_Plugin';
    }

    /* ---------------------------------------------------------- 检查项 */

    /**
     * 插件目录名
     *
     * Typecho 按「目录名_Plugin」找类，目录名不对就会报
     * 「插件不存在」或「配置信息没有找到」。
     */
    protected static function checkDir(&$rows)
    {
        $base = basename(dirname(__FILE__));

        self::row(
            $rows,
            '插件目录名',
            'Qingyan' === $base,
            'usr/plugins/' . $base,
            'Qingyan' === $base ? '' : '目录名必须是 Qingyan，请去掉 -master 后缀或多余的一层嵌套'
        );
    }

    /**
     * PHP 版本
     */
    protected static function checkPhp(&$rows)
    {
        $ok = version_compare(PHP_VERSION, '7.0.0', '>=');

        self::row($rows, 'PHP 版本', $ok, PHP_VERSION, $ok ? '' : '需要 PHP 7.0 以上，推荐 7.4 ~ 8.3');
    }

    /**
     * 类是否加载（1.2 / 1.3 两套入口）
     */
    protected static function checkClasses(&$rows)
    {
        $modern = qy_is_modern();
        $need   = $modern ? 'TypechoPlugin\Qingyan\Plugin' : 'Qingyan_Plugin';

        self::row(
            $rows,
            'Typecho 分支',
            true,
            $modern ? '1.3+（命名空间版）' : '1.2.x（下划线类名版）',
            ''
        );

        self::row(
            $rows,
            '入口类 ' . $need,
            class_exists($need),
            class_exists($need) ? '存在' : '不存在 —— 这是「配置信息没有找到」的常见根因',
            class_exists($need) ? '' : '确认插件目录名为 Qingyan，并重新上传全部文件'
        );

        self::row(
            $rows,
            '主题调用类 Qingyan_Plugin',
            class_exists('Qingyan_Plugin'),
            class_exists('Qingyan_Plugin') ? '存在（主题里 Qingyan_Plugin::xxx() 可用）' : '不存在',
            class_exists('Qingyan_Plugin') ? '' : '重新上传 Plugin.php'
        );
    }

    /**
     * 四个必需方法
     */
    protected static function checkMethods(&$rows)
    {
        $class = self::pluginClass();

        if (!class_exists($class)) {
            self::row($rows, '四个必需方法', false, '无法检查（类未加载）', '');

            return;
        }

        $required = array('activate', 'deactivate', 'config', 'personalConfig');
        $missing  = array();

        foreach ($required as $method) {
            if (!method_exists($class, $method)) {
                $missing[] = $method;
            }
        }

        self::row(
            $rows,
            '四个必需方法',
            empty($missing),
            empty($missing) ? '齐全（activate / deactivate / config / personalConfig）' : '缺少：' . implode(', ', $missing),
            empty($missing) ? '' : '重新上传 Plugin.php'
        );
    }

    /**
     * 数据表（单表设计）
     */
    protected static function checkTable(&$rows)
    {
        $ok    = false;
        $value = '';

        try {
            $db     = qy_db();
            $prefix = $db->getPrefix();
            $db->fetchRow($db->select()->from('table.qingyan')->limit(1));
            $ok    = true;
            $value = $prefix . 'qingyan 可访问';
        } catch (Throwable $e) {
            $value = '查询失败：' . $e->getMessage();
        }

        self::row($rows, '数据表 qingyan', $ok, $value, $ok ? '' : '表不存在，说明未成功激活：禁用插件后重新启用');
    }

    /**
     * 插件配置行
     */
    protected static function checkConfig(&$rows)
    {
        $ok    = false;
        $value = '';

        try {
            $db  = qy_db();
            $row = $db->fetchRow(
                $db->select()->from('table.options')->where('name = ?', 'plugin:Qingyan')->limit(1)
            );

            $ok    = is_array($row) && isset($row['value']) && '' !== (string) $row['value'];
            $value = $ok ? 'plugin:Qingyan 已写入' : '缺失或为空（刷新任意页面会自动补写）';
        } catch (Throwable $e) {
            $value = '查询失败：' . $e->getMessage();
        }

        self::row($rows, '插件配置行', $ok, $value, $ok ? '' : '若反复缺失，禁用后重新启用插件');
    }

    /**
     * 后台菜单数量（重点查重复堆积）
     *
     * 历史上这里踩过大坑：addMenu() 每次启用都往 panelTable['parent']
     * 追加新索引，导致菜单越来越多。现在改用 addPanel 挂在「管理」下。
     */
    protected static function checkMenu(&$rows)
    {
        $class = self::pluginClass();

        if (!class_exists($class) || !method_exists($class, 'countPanels')) {
            self::row($rows, '后台菜单数量', false, '无法检查（类未加载）', '');

            return;
        }

        $panels = (int) $class::countPanels();
        $old    = method_exists($class, 'findMenuIndexes') ? count($class::findMenuIndexes()) : 0;

        $ok = ($panels <= 1 && 0 === $old);

        $value = '「管理 → 轻言」面板 ' . $panels . ' 个';

        if ($old > 0) {
            $value .= '，旧版遗留一级菜单 ' . $old . ' 个';
        }

        self::row(
            $rows,
            '后台菜单数量',
            $ok,
            $value,
            $ok ? '' : '菜单重复了。禁用插件再重新启用即可清理（新版 deactivate() 会清空全部「轻言」菜单）'
        );
    }

    /**
     * API 地址（重点查 index.php 重复）
     */
    protected static function checkApiUrl(&$rows)
    {
        $class = self::pluginClass();

        if (!class_exists($class)) {
            self::row($rows, 'API 地址', false, '无法检查（类未加载）', '');

            return;
        }

        $url = $class::apiUrl('like');
        $dup = false !== strpos($url, 'index.php/index.php');

        self::row(
            $rows,
            'API 地址',
            !$dup,
            $url,
            $dup ? '出现两段 index.php —— 运行的是旧版 Plugin.php，请重新覆盖插件文件' : ''
        );
    }

    /**
     * 路由注册
     */
    protected static function checkRoute(&$rows)
    {
        $class = self::pluginClass();

        if (!class_exists($class) || !method_exists($class, 'slug')) {
            self::row($rows, '路由注册', false, '无法检查（类未加载）', '');

            return;
        }

        $slug  = $class::slug();
        $table = (array) Helper::options()->routingTable;
        $found = false;

        foreach ($table as $key => $item) {
            if (false !== strpos((string) $key, 'qingyan')) {
                $found = true;
                break;
            }

            if (isset($item['url']) && false !== strpos((string) $item['url'], $slug)) {
                $found = true;
                break;
            }
        }

        self::row(
            $rows,
            '路由注册',
            $found,
            $found ? '已注册（/qingyan/api/...）' : '未找到路由',
            $found ? '' : '到插件设置页点一次保存，或禁用后重新启用'
        );
    }

    /**
     * 启用状态
     */
    protected static function checkActivated(&$rows)
    {
        $active = (array) Helper::options()->plugins;
        $on     = isset($active['activated']['Qingyan']);

        self::row(
            $rows,
            '启用状态',
            $on,
            $on ? '已启用' : '未启用',
            $on ? '' : '到后台插件列表重新启用一次'
        );
    }

    /**
     * 内容统计
     */
    protected static function checkStats(&$rows)
    {
        try {
            $db     = qy_db();
            $total  = 0;
            $status = array();

            $list = $db->fetchAll($db->select('status')->from('table.qingyan'));

            foreach ((array) $list as $item) {
                $total++;

                $key = isset($item['status']) ? (string) $item['status'] : 'unknown';

                if (!isset($status[$key])) {
                    $status[$key] = 0;
                }

                $status[$key]++;
            }

            $parts = array();

            foreach ($status as $key => $count) {
                $parts[] = $key . ' ' . $count;
            }

            self::row(
                $rows,
                '内容统计',
                true,
                '共 ' . $total . ' 条' . (empty($parts) ? '' : '：' . implode('，', $parts)),
                ''
            );
        } catch (Throwable $e) {
            self::row($rows, '内容统计', false, '查询失败：' . $e->getMessage(), '');
        }
    }

    /**
     * 版本号
     */
    protected static function checkVersion(&$rows)
    {
        $class = self::pluginClass();

        self::row($rows, '插件版本', true, class_exists($class) ? $class::VERSION : '未知', '');
    }

    /* ---------------------------------------------------------- 输出 */

    /**
     * 渲染诊断页（内容区，不含 header / footer）
     *
     * @return void
     */
    public static function render()
    {
        $rows = self::checks();
        $pass = 0;

        foreach ($rows as $row) {
            if ($row['ok']) {
                $pass++;
            }
        }

        $backUrl = Helper::options()->adminUrl . 'extending.php?panel=Qingyan/panel.php';
        $esc     = function ($value) {
            return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        };
        ?>
        <div class="main">
            <div class="body container">
                <div class="typecho-page-title">
                    <h2>运行环境自检</h2>
                    <p style="margin:4px 0 0;font-size:13px">
                        <a href="<?php echo $esc($backUrl); ?>">&larr; 返回轻言面板</a>
                    </p>
                </div>

                <div class="row typecho-page-main" role="main">
                    <div class="col-mb-12">

                        <div class="typecho-list-operate clearfix">
                            <h3 style="margin:0">
                                检查结果：通过 <b style="color:<?php echo $pass === count($rows) ? '#46a05a' : '#c0392b'; ?>"><?php echo $pass; ?></b> / <?php echo count($rows); ?> 项
                            </h3>
                            <p style="margin:6px 0 0;font-size:12px;color:#999">
                                本页只读取环境与配置，不会修改任何数据。
                            </p>
                        </div>

                        <div class="typecho-table-wrap">
                            <table class="typecho-list-table">
                                <colgroup>
                                    <col width="20%">
                                    <col width="8%">
                                    <col width="72%">
                                </colgroup>
                                <thead>
                                <tr>
                                    <th>检查项</th>
                                    <th>结果</th>
                                    <th>详情</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($rows as $row) { ?>
                                <tr>
                                    <td><?php echo $esc($row['name']); ?></td>
                                    <td style="color:<?php echo $row['ok'] ? '#46a05a' : '#c0392b'; ?>;font-weight:600">
                                        <?php echo $row['ok'] ? '通过' : '异常'; ?>
                                    </td>
                                    <td>
                                        <?php echo $esc($row['value']); ?>
                                        <?php if (!$row['ok'] && '' !== $row['fix']) { ?>
                                        <span style="display:block;margin-top:3px;font-size:12px;color:#c0392b">
                                            处理建议：<?php echo $esc($row['fix']); ?>
                                        </span>
                                        <?php } ?>
                                    </td>
                                </tr>
                                <?php } ?>
                                </tbody>
                            </table>
                        </div>

                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
