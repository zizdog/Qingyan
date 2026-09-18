<?php
/**
 * 轻言：给 Typecho 加说说/微博功能。
 *
 *
 * @package Qingyan
 * @author  zizdog
 * @version 2.8.5
 * @link    https://zizdog.com
 */

namespace {

    if (!defined('__TYPECHO_ROOT_DIR__')) {
        exit;
    }

    require_once __DIR__ . '/compat.php';
    require_once __DIR__ . '/Model.php';
    require_once __DIR__ . '/Renderer.php';
    require_once __DIR__ . '/Api.php';
    require_once __DIR__ . '/Front.php';

    // 防止被重复包含时重复声明类（尾部两个子类已有各自的存在性判断）
    if (class_exists('Qingyan_Plugin_Base', false)) {
        return;
    }

    class Qingyan_Plugin_Base implements Typecho_Plugin_Interface
    {
        /** 插件版本 */
        const VERSION = '2.8.5';

        /** 路由名：API 接口 */
        const ROUTE_API = 'qingyan_api';

        /** 路由名：独立前台首页 /qingyan */
        const ROUTE_HOME = 'qingyan_home';

        /** 路由名：独立前台分页 /qingyan/page/2 */
        const ROUTE_PAGE = 'qingyan_page';

        /** 后台菜单标题 */
        const MENU_TITLE = '轻言';

        /**
         * 挂载到哪个一级菜单下
         *
         * Typecho 的 $parentNodes = [null, 控制台, 撰写, 管理, 设置]
         * 索引 3 = 「管理」，所以面板出现在：后台 → 管理 → 轻言
         *
         * 参考同类插件 Typecho-Plugin-Says 的做法：不新建一级菜单，
         * 直接挂到已有的「管理」下面。这样永远不会出现菜单堆积。
         */
        const MENU_INDEX = 3;

        /** 后台面板相对路径（相对于 usr/plugins） */
        const PANEL_FILE = 'Qingyan/panel.php';

        /** @var array 配置读取缓存（写库后必须清空，否则同一请求内读到旧值） */
        private static $optCache = array();

        /**
         * 激活插件
         *
         * @return string
         */
        public static function activate()
        {
            try {
                Qingyan_Model::install();
                self::writeDefaultConfig();
                // slug 可能改过，激活时强制同步一次路由
                self::installRoute(true);

                // 菜单注册失败不能拖垮整个激活流程
                try {
                    self::installMenu();
                } catch (Throwable $menuError) {
                    // 忽略
                }

            } catch (Throwable $e) {
                throw self::pluginException('轻言启用失败：' . qy_throwable_message($e));
            }

            return _t('轻言已启用。请到插件设置里点一次保存，再访问 /' . self::slug() . ' 查看。');
        }

        /**
         * 禁用插件
         *
         * 这里必须保证「一定能禁用掉」，所以每一步都单独兜住异常，
         * 包括 TypeError / Error 这类不走 Exception 的错误。
         *
         * @return string
         */
        public static function deactivate()
        {
            $dropped = false;

            // 1. 移除「管理 → 轻言」面板
            //    Helper::removePanel() 内部是遍历 child[$index] 按文件名匹配后
            //    逐个 unset，所以历史遗留的多个重复面板会被一次性清干净。
            try {
                Helper::removePanel(self::MENU_INDEX, self::PANEL_FILE);
            } catch (Throwable $e) {
                // 忽略
            }

            // 2. 清理早期版本误建的一级菜单（旧版用了 Helper::addMenu）
            try {
                self::purgeMenus(NULL);
            } catch (Throwable $e) {
                // 忽略
            }

            // 3. 移除路由
            foreach (array(self::ROUTE_API, self::ROUTE_HOME, self::ROUTE_PAGE) as $route) {
                try {
                    Helper::removeRoute($route);
                } catch (Throwable $e) {
                    // 忽略
                }
            }

            // 4. 按需清表
            try {
                $drop = !self::boolOpt('keepData', true);

                if ($drop) {
                    Qingyan_Model::uninstall();
                    $dropped = true;
                }
            } catch (Throwable $e) {
                // 忽略
            }

            return $dropped
                ? _t('轻言已停用，路由已移除，数据表与数据已清除。')
                : _t('轻言已停用，路由已移除，数据保留在数据表中。');
        }

        /**
         * 插件配置面板
         *
         * @param Typecho_Widget_Helper_Form $form
         * @return void
         */
        /**
         * 解析表单元素类名（兼容 1.2 下划线与 1.3 命名空间）
         *
         * 直接 new 一个不存在的类会 fatal，这里统一挑一个真实存在的类名。
         *
         * @param string $type Text|Radio|Textarea|Select
         * @return string
         */
        protected static function elementClass($type)
        {
            $candidates = array(
                'Typecho\\Widget\\Helper\\Form\\Element\\' . $type,
                'Typecho_Widget_Helper_Form_Element_' . $type,
            );

            foreach ($candidates as $class) {
                if (class_exists($class)) {
                    return $class;
                }
            }

            // 都拿不到就退回 Text（最常见的元素，通常一定存在）
            return class_exists('Typecho_Widget_Helper_Form_Element_Text')
                ? 'Typecho_Widget_Helper_Form_Element_Text'
                : 'Typecho\\Widget\\Helper\\Form\\Element\\Text';
        }

        /**
         * 解析自定义布局块类名（1.2 下划线 / 1.3 命名空间）
         *
         * 拿不到就返回空串，调用方跳过，绝不让设置页挂掉。
         *
         * @return string
         */
        protected static function layoutClass()
        {
            foreach (array('Typecho\\Widget\\Helper\\Layout', 'Typecho_Widget_Helper_Layout') as $class) {
                if (class_exists($class)) {
                    return $class;
                }
            }

            return '';
        }

        /**
         * 设置页最顶部的「先看 README」提示块
         *
         * 只讲三件事：说明文档在哪、里面有什么、目录里还有哪些可复用文件。
         * 样式变量与后台主题（AdminDark）保持一致，深浅色都可读。
         *
         * @return string
         */
        public static function readmeNoticeHtml()
        {
            $readmePath = 'usr/plugins/Qingyan/README.md';

            return '<div id="qy-notice" class="qy-notice">'
                . '<style>'
                . '#qy-notice{--qy-bd:var(--ad-line,#e3e6ea);--qy-bg:var(--ad-surface-alt,rgba(128,128,128,.06));'
                . '--qy-fg:var(--ad-fg,#444);--qy-muted:var(--ad-fg-faint,#999);--qy-ac:var(--ad-accent,#467b96);'
                . 'border:1px solid var(--qy-bd);border-left:3px solid var(--qy-ac);border-radius:6px;'
                . 'padding:12px 16px;margin:0 0 18px;background:var(--qy-bg);color:var(--qy-fg);'
                . 'line-height:1.8;font-size:13px}'
                . '#qy-notice p{margin:4px 0}'
                . '#qy-notice ul{margin:6px 0 4px 1.2em;padding:0}'
                . '#qy-notice li{margin:2px 0}'
                . '#qy-notice code{font-family:Menlo,Monaco,Consolas,monospace;font-size:12px}'
                . '#qy-notice .qy-notice-muted{color:var(--qy-muted)}'
                . '</style>'
                . '<p><b>使用前请先读插件目录里的 <code>' . $readmePath . '</code></b>'
                . '<span class="qy-notice-muted">（本页只放配置项，说明与示例都在那份文档里）</span></p>'
                . '<ul>'
                . '<li><b>接入主题</b>：<code>Qingyan_Plugin::render()</code> / <code>query()</code> 的完整写法，'
                . '以及复制 <code>theme-example.php</code> 做独立页面的三步流程</li>'
                . '<li><b>正文语法</b>：Markdown 支持范围（列表、表格、代码块、引用…）、'
                . '<code>#话题#</code> 与表情短代码的规则与限制</li>'
                . '<li><b>接口与数据</b>：JSON API 参数、数据表字段、只取数据的 <code>renderList()</code></li>'
                . '<li><b>排错</b>：路由 404、菜单重复、点赞失败、上传失败等常见问题的处理办法</li>'
                . '</ul>'
                . '<p class="qy-notice-muted">装的是压缩包的话，<code>README.md</code> 就在插件目录里，'
                . '与本设置页同级；后台 → 管理 → 轻言 → 运行环境自检也能直接看出环境问题。</p>'
                . '</div>';
        }

        public static function config($form)
        {
            // 【重要】这里必须保持"纯表单构建"，不能有任何副作用。
            // 保存设置时 Typecho 会重新执行 config() 做校验，
            // 早期版本在这里调 installRoute() 导致保存时 Server Error。
            // 路由同步改回在 activate() / 手动按钮里做。

            // 最上面先提示去看插件目录里的 README.md（用户装完最需要的是文档入口）
            $noticeLayout = self::layoutClass();

            if ('' !== $noticeLayout) {
                try {
                    $notice = new $noticeLayout();
                    $notice->html(self::readmeNoticeHtml());
                    $form->addItem($notice);
                } catch (Throwable $e) {
                    // 布局类不可用时忽略，不影响表单本身
                }
            }

            $clsText = self::elementClass('Text');
            $slug = new $clsText(
                'slug',
                NULL,
                'qingyan',
                _t('接口路径'),
                _t('点赞、评论、发布等接口的地址前缀，形如：你的域名/qingyan/api/xxx。只能包含字母、数字、中划线和下划线。')
            );
            $form->addInput($slug->addRule('required', _t('请填写接口路径')));

            $clsText = self::elementClass('Text');
            $frontUrl = new $clsText(
                'frontUrl',
                NULL,
                '',
                _t('前台展示页地址'),
                _t('你在主题中放置时间线的页面地址，用于后台跳转与评论定位。<br><b>留空</b>则使用插件自带的独立前台页（/' . self::slug() . '）。<br>例如：https://你的域名/says.html')
            );
            $form->addInput($frontUrl);

            $clsText = self::elementClass('Text');
            $frontTitle = new $clsText(
                'frontTitle',
                NULL,
                '轻言',
                _t('独立前台标题'),
                _t('仅当使用插件自带的独立前台页时生效（上面的地址留空）。')
            );
            $form->addInput($frontTitle);

            $clsRadio = self::elementClass('Radio');
            $emojiOn = new $clsRadio(
                'emojiOn',
                array('1' => _t('开启'), '0' => _t('关闭')),
                '1',
                _t('表情功能'),
                _t('开启后发布框会显示表情面板。也支持手动输入 :smile: 这样的短代码。')
            );
            $form->addInput($emojiOn);

            $clsText = self::elementClass('Text');
            $pageSize = new $clsText(
                'pageSize',
                NULL,
                '10',
                _t('每页条数'),
                _t('前台时间线每页显示的短内容数量，建议 5 - 20。')
            );
            $form->addInput($pageSize->addRule('isInteger', _t('请填写整数')));

            $clsRadio = self::elementClass('Radio');
            $likeOn = new $clsRadio(
                'likeOn',
                array('1' => _t('开启'), '0' => _t('关闭')),
                '1',
                _t('点赞功能'),
                _t('开启后访客可以点赞，按 IP + 浏览器指纹去重，不存储明文 IP。')
            );
            $form->addInput($likeOn);

            $clsRadio = self::elementClass('Radio');
            $commentOn = new $clsRadio(
                'commentOn',
                array('1' => _t('开启'), '0' => _t('关闭')),
                '1',
                _t('评论功能'),
                _t('轻言使用独立的评论表，不影响文章评论。')
            );
            $form->addInput($commentOn);

            $clsRadio = self::elementClass('Radio');
            $adminDelete = new $clsRadio(
                'adminDelete',
                array('0' => _t('不显示（默认）'), '1' => _t('显示')),
                '0',
                _t('前台删除按钮'),
                _t('登录管理员在前台每条内容/评论旁看到的<b>删除</b>按钮。<br>'
                    . '<b>默认关闭</b>：前台不渲染任何删除入口，接口也会拒绝删除请求 —— '
                    . '要删内容请到后台「管理 → 轻言」。<br>'
                    . '开启后按钮才出现，且点击时仍会弹<b>二次确认</b>（删除不可撤销）。'
                    . '<br>无论开关如何，删除都只对<b>已登录管理员</b>有效，游客拿到按钮也没用。')
            );
            $form->addInput($adminDelete);

            $clsRadio = self::elementClass('Radio');
            $commentLogin = new $clsRadio(
                'commentLogin',
                array('0' => _t('游客可评论'), '1' => _t('仅登录用户')),
                '0',
                _t('评论权限'),
                _t('开启评论功能后生效。游客评论建议同时开启审核。')
            );
            $form->addInput($commentLogin);

            $clsRadio = self::elementClass('Radio');
            $commentReview = new $clsRadio(
                'commentReview',
                array('1' => _t('需审核'), '0' => _t('直接显示')),
                '1',
                _t('评论审核'),
                _t('游客评论默认先进入待审核队列，可在后台「管理 - 轻言」处理。')
            );
            $form->addInput($commentReview);

            $clsText = self::elementClass('Text');
            $commentInterval = new $clsText(
                'commentInterval',
                NULL,
                '30',
                _t('评论间隔（秒）'),
                _t('同一 IP 两次评论之间的最小间隔，用于防刷，填 0 表示不限制。')
            );
            $form->addInput($commentInterval->addRule('isInteger', _t('请填写整数')));

            $clsRadio = self::elementClass('Radio');
            $avatar = new $clsRadio(
                'avatar',
                array('1' => _t('显示'), '0' => _t('隐藏')),
                '1',
                _t('评论头像'),
                _t('只作用于评论者的 Gravatar 头像。轻言卡片左侧显示的是发布时选的「心情」，不是头像。未填写邮箱时显示默认头像。')
            );
            $form->addInput($avatar);

            $clsText = self::elementClass('Text');
            $gravatar = new $clsText(
                'gravatar',
                NULL,
                'https://cravatar.cn/avatar',
                _t('评论头像镜像地址'),
                _t('国内站点建议保留 cravatar 镜像，也可改为 https://secure.gravatar.com/avatar。')
            );
            $form->addInput($gravatar);

            $clsTextarea = self::elementClass('Textarea');
            $customCss = new $clsTextarea(
                'customCss',
                NULL,
                '',
                _t('自定义 CSS'),
                _t('追加在时间线页面底部的样式，可覆盖默认配色与圆角等。')
            );
            $form->addInput($customCss);

            $clsRadio = self::elementClass('Radio');
            $keepData = new $clsRadio(
                'keepData',
                array('1' => _t('保留数据'), '0' => _t('删除数据')),
                '1',
                _t('禁用插件时'),
                _t('选择「删除数据」会在禁用插件时一并 DROP 三张数据表，请谨慎。')
            );
            $form->addInput($keepData);
        }

        /**
         * 个人用户配置面板（本插件不需要）
         *
         * @param Typecho_Widget_Helper_Form $form
         * @return void
         */
        public static function personalConfig($form)
        {
        }

        /**
         * 配置保存回调：落库 + 按需同步路由
         *
         * 【为什么不交给框架自动保存】
         * 官方 HelloWorld 不定义 configHandle，靠框架自动写库。
         * 但在本机（Typecho 1.3 / PHP 8.3）实测：config() 正常、
         * configHandle 也不存在，点保存却报 Server Error，值始终没入库。
         * 说明这条自动保存链路在本环境不可用，所以这里主动接管。
         *
         * 落库走三级降级，确保一定能写进去：
         *   1. Db 构造器
         *   2. 裸 SQL（用 adapter->quoteValue 正确转义）
         *   3. Helper::configPlugin
         *
         * slug 变化时额外强制重写路由 —— 这正是「去设置页点一次保存」
         * 能重写路由的实现依据。
         *
         * @param array $settings
         * @param bool  $isInit 框架初始化回填（启用插件后）时为 true
         * @return void
         */
        public static function configHandle($settings, $isInit = false)
        {
            if ($isInit) {
                // 纯回填阶段：不落库、不同步路由。
                // 此阶段 $settings 是表单默认值，若照单落库会把用户已有配置冲掉，
                // 也会用默认 slug 覆盖真实路由。
                return;
            }

            if (!is_array($settings)) {
                $settings = array();
            }

            $stored  = self::rawConfig();
            $oldSlug = self::normalizeSlug(isset($stored['slug']) ? $stored['slug'] : 'qingyan');
            $newSlug = array_key_exists('slug', $settings)
                ? self::normalizeSlug($settings['slug'])
                : $oldSlug;

            self::saveSettings($settings);

            // slug 变了必须强制重写；没变时也跑一次，installRoute() 自带
            // 「已是最新就不动」判断，可顺带修复被删除的路由。
            try {
                self::installRoute($oldSlug !== $newSlug);
            } catch (Throwable $e) {
                // 路由同步失败不能影响配置保存本身
            }
        }

        /**
         * 把配置写入 options 表
         *
         * @param array $settings
         * @return bool
         */
        public static function saveSettings($settings)
        {
            if (!is_array($settings)) {
                $settings = array();
            }

            $name = 'plugin:Qingyan';

            // 与库中现有值合并，避免只提交部分字段时把其余的冲掉；
            // 再裁剪到本版本真正声明的键 —— 否则旧版本残留的键会让
            // core 渲染设置页时 Form::getInput($key) 返回 null 而 fatal。
            $merged = self::pruneConfig(array_merge(self::rawConfig(), $settings));
            $value  = serialize($merged);

            // ---- 1. Db 构造器 ----
            try {
                $db = qy_db();
                $db->query(
                    $db->update('table.options')
                        ->rows(array('value' => $value))
                        ->where('name = ?', $name)
                );

                if (self::verifySaved($value)) {
                    self::$optCache = array();

                    return true;
                }
            } catch (Throwable $e) {
                // 继续降级
            }

            // ---- 2. 裸 SQL（用 adapter 正确转义，Pg/SQLite 下不能 addslashes）----
            try {
                $db     = qy_db();
                $prefix = method_exists($db, 'getPrefix') ? $db->getPrefix() : 'typecho_';
                $safe   = self::quoteValue($db, $value);
                $safeName = self::quoteValue($db, $name);
                $db->query(
                    "UPDATE `{$prefix}options` SET `value` = {$safe} WHERE `name` = {$safeName}",
                    QY_DB_WRITE
                );

                if (self::verifySaved($value)) {
                    self::$optCache = array();

                    return true;
                }
            } catch (Throwable $e) {
                // 继续降级
            }

            // ---- 3. Helper ----
            try {
                Helper::configPlugin('Qingyan', $merged);

                if (self::verifySaved($value)) {
                    self::$optCache = array();

                    return true;
                }
            } catch (Throwable $e) {
                // 全部失败
            }

            return false;
        }

        /**
         * 裁剪配置数组：只保留本版本 config() / defaults() 里声明的键
         *
         * 【为什么必须做】core 的 Widget\Plugins\Config::config() 会
         * `foreach ($options as $key => $val) $form->getInput($key)->value($val)`，
         * 而 Form::getInput() 没有 isset 守卫。旧版本删掉的键一旦留在库里，
         * 设置页就会 `Call to a member function value() on null` 直接 500。
         *
         * @param mixed $config
         * @return array
         */
        protected static function pruneConfig($config)
        {
            if (!is_array($config)) {
                return self::defaults();
            }

            $result = array();

            foreach (array_keys(self::defaults()) as $key) {
                if (array_key_exists($key, $config)) {
                    $result[$key] = $config[$key];
                }
            }

            return $result;
        }

        /**
         * 用数据库适配器转义字符串字面量（兼容 MySQL / PgSQL / SQLite）
         *
         * addslashes() 只在 MySQL 下语义正确；PostgreSQL 在
         * standard_conforming_strings=on 时、SQLite 下反斜杠不是转义符，
         * 会把 `\` 原样写入，直接写坏 serialize() 数据。
         *
         * @param object $db
         * @param string $value
         * @return string 已带引号的 SQL 字面量
         */
        protected static function quoteValue($db, $value)
        {
            try {
                if (is_object($db) && method_exists($db, 'getAdapter')) {
                    $adapter = $db->getAdapter();

                    if (is_object($adapter) && method_exists($adapter, 'quoteValue')) {
                        return $adapter->quoteValue((string) $value);
                    }
                }
            } catch (Throwable $e) {
                // 退回下方兜底
            }

            // 极端兜底：单引号翻倍（标准 SQL）。不应被走到。
            return "'" . str_replace("'", "''", (string) $value) . "'";
        }

        /**
         * 校验库中值是否已更新为期望值
         *
         * @param string $expect 序列化后的字符串
         * @return bool
         */
        protected static function verifySaved($expect)
        {
            try {
                $db  = qy_db();
                $row = $db->fetchRow(
                    $db->select('value')->from('table.options')
                        ->where('name = ?', 'plugin:Qingyan')
                        ->limit(1)
                );

                return !empty($row) && isset($row['value']) && $row['value'] === $expect;
            } catch (Throwable $e) {
                return false;
            }
        }

        /**
         * 从库中读取完整配置数组
         *
         * @return array
         */
        public static function rawConfig()
        {
            try {
                $db  = qy_db();
                $row = $db->fetchRow(
                    $db->select('value')->from('table.options')
                        ->where('name = ?', 'plugin:Qingyan')
                        ->limit(1)
                );

                if (empty($row) || !isset($row['value']) || '' === (string) $row['value']) {
                    return self::defaults();
                }

                $data = @unserialize($row['value']);

                return is_array($data) ? $data : self::defaults();
            } catch (Throwable $e) {
                return self::defaults();
            }
        }

        /* ------------------------------------------------------------------ */
        /* 默认配置                                                           */
        /* ------------------------------------------------------------------ */

        /**
         * 全部配置项的默认值
         *
         * @return array
         */
        public static function defaults()
        {
            return array(
                'slug'            => 'qingyan',
                'frontUrl'        => '',
                'frontTitle'      => '轻言',
                'emojiOn'         => '1',
                'pageSize'        => '10',
                'likeOn'          => '1',
                'commentOn'       => '1',
                // 前台删除按钮：**默认关闭**（用户要求）。接口侧也会一并拒绝。
                'adminDelete'     => '0',
                'commentLogin'    => '0',
                'commentReview'   => '1',
                'commentInterval' => '30',
                'avatar'          => '1',
                'gravatar'        => 'https://cravatar.cn/avatar',
                'customCss'       => '',
                'keepData'        => '1',
            );
        }

        /**
         * 确保 options 表里有 plugin:Qingyan 这一行
         *
         * 【这是本次报错的根因与修复点】
         * Typecho 1.3 的 Widget\Plugins\Config 在渲染设置页时，会先 require
         * 插件主文件，紧接着调用 Widget\Options->plugin('Qingyan') 取当前值。
         * 只要 options 表里没有 plugin:Qingyan 这一行，核心当场抛出
         * 「插件Qingyan的配置信息没有找到」——异常发生在核心里，插件自己
         * catch 不到，唯一办法就是让这一行存在。
         *
         * 之所以会缺这一行：用户是用旧版（没有写配置逻辑的版本）完成的激活，
         * 之后再覆盖文件，activate() 不会重新执行，行就一直缺失。
         * 所以这里做成「自愈」：Plugin.php 每次被加载都检查一次，缺了就补。
         *
         * 顺带把库里旧版本残留的未知键裁掉：core 渲染设置页时会
         * `$form->getInput($key)->value($val)`，未知键会让它 fatal，
         * 而这一步在 core require 本文件之后、读取配置之前执行，
         * 所以同一请求内就能生效（同时刷新 Options 单例里的那一行）。
         *
         * @return bool 是否新写入
         */
        public static function writeDefaultConfig()
        {
            try {
                $db      = qy_db();
                $name    = 'plugin:Qingyan';
                $default = serialize(self::defaults());

                // 用最朴素的写法，避开各版本对 COUNT(1) AS num 的解析差异
                $row = $db->fetchRow(
                    $db->select()->from('table.options')->where('name = ?', $name)->limit(1)
                );

                $exists = is_array($row) && isset($row['value']) && '' !== (string) $row['value'];

                if ($exists) {
                    return self::pruneStoredConfig($row['value']);
                }

                if (is_array($row) && !empty($row)) {
                    // 行在但值为空：更新
                    $db->query(
                        $db->update('table.options')
                            ->rows(array('value' => $default))
                            ->where('name = ?', $name)
                    );

                    return true;
                }

                $db->query(
                    $db->insert('table.options')
                        ->rows(array('name' => $name, 'user' => 0, 'value' => $default))
                );

                return true;
            } catch (Throwable $e) {
                // 构造器写不进去时，退回裸 SQL（不同版本 insert() 签名可能不同）
                return self::writeDefaultConfigRaw();
            }
        }

        /**
         * 裁掉库里 plugin:Qingyan 的未知键
         *
         * 写回 DB 的同时刷新 Widget_Options 单例里的插件配置行，
         * 这样同一请求里 core 读到的是裁剪后的值，设置页不会 fatal。
         *
         * @param string $stored 库中的原始值（serialize）
         * @return bool 是否发生了裁剪
         */
        protected static function pruneStoredConfig($stored)
        {
            $data = @unserialize((string) $stored);

            if (!is_array($data)) {
                return false;
            }

            $pruned = self::pruneConfig($data);

            if ($pruned === $data) {
                return false;
            }

            $value = serialize($pruned);

            $db = qy_db();
            $db->query(
                $db->update('table.options')
                    ->rows(array('value' => $value))
                    ->where('name = ?', 'plugin:Qingyan')
            );

            self::$optCache = array();

            // 让同一请求内 Helper::options()->plugin('Qingyan') 立即读到新值
            try {
                Helper::options()->{'plugin:Qingyan'} = $value;
            } catch (Throwable $e) {
                // 刷新不了也不影响下次请求
            }

            return true;
        }

        /**
         * 用裸 SQL 兜底写入配置行
         *
         * @return bool
         */
        protected static function writeDefaultConfigRaw()
        {
            try {
                $db      = qy_db();
                $prefix  = method_exists($db, 'getPrefix') ? $db->getPrefix() : 'typecho_';
                $table   = $prefix . 'options';
                $name    = 'plugin:Qingyan';
                $default = serialize(self::defaults());

                $safeValue = self::quoteValue($db, $default);
                $safeName  = self::quoteValue($db, $name);

                $row = $db->fetchRow(
                    $db->select()->from('table.options')->where('name = ?', $name)->limit(1)
                );

                if (is_array($row) && !empty($row)) {
                    $db->query("UPDATE `{$table}` SET `value` = {$safeValue} WHERE `name` = {$safeName}", QY_DB_WRITE);

                    return true;
                }

                $db->query(
                    "INSERT INTO `{$table}` (`name`, `user`, `value`) VALUES ({$safeName}, 0, {$safeValue})",
                    QY_DB_WRITE
                );

                return true;
            } catch (Throwable $e) {
                return false;
            }
        }

        /* ------------------------------------------------------------------ */
        /* 配置读取                                                           */
        /* ------------------------------------------------------------------ */

        /**
         * 读取一项配置
         *
         * @param string $key
         * @param mixed  $default
         * @return mixed
         */
        public static function opt($key, $default = NULL)
        {
            $value = self::rawOpt($key);

            if (NULL === $value || '' === $value) {
                return $default;
            }

            return $value;
        }

        /**
         * 读取原始配置值（拿不到返回 NULL，绝不抛异常）
         *
         * 首次激活时 plugin:Qingyan 还没写进库，
         * Helper::options()->plugin() 会直接抛异常，这里必须兜住。
         *
         * @param string $key
         * @return mixed
         */
        protected static function rawOpt($key)
        {
            if (isset(self::$optCache[$key])) {
                return self::$optCache[$key];
            }

            $value = NULL;

            // 【关键】先直接读库。
            // Helper::options()->plugin() 是 Widget_Options 单例，写库后
            // 同一请求内不会更新，先读它会拿到旧值并导致配置"存了不生效"。
            $value = self::optFromDb($key);

            if (NULL === $value || '' === $value) {
                try {
                    $config = Helper::options()->plugin('Qingyan');

                    if (!empty($config) && isset($config->{$key})) {
                        $value = $config->{$key};
                    }
                } catch (Throwable $e) {
                    $value = NULL;
                }
            }

            // 配置面板里的默认值
            if ((NULL === $value || '' === $value) && array_key_exists($key, self::defaults())) {
                $value = self::defaults()[$key];
            }

            if (NULL !== $value && '' !== $value) {
                self::$optCache[$key] = $value;
            }

            return $value;
        }

        /**
         * 直接从 options 表读取插件配置
         *
         * @param string $key
         * @return mixed
         */
        protected static function optFromDb($key)
        {
            try {
                $db  = qy_db();
                $row = $db->fetchRow(
                    $db->select('value')->from('table.options')
                        ->where('name = ?', 'plugin:Qingyan')
                        ->limit(1)
                );

                if (empty($row) || !isset($row['value']) || '' === $row['value']) {
                    return NULL;
                }

                $data = @unserialize($row['value']);

                if (is_array($data) && array_key_exists($key, $data)) {
                    return $data[$key];
                }

                return NULL;
            } catch (Throwable $e) {
                return NULL;
            }
        }

        /**
         * 读取整型配置
         *
         * @param string $key
         * @param int    $default
         * @return int
         */
        public static function intOpt($key, $default = 0)
        {
            return intval(self::opt($key, $default));
        }

        /**
         * 读取布尔型配置
         *
         * @param string $key
         * @param bool   $default
         * @return bool
         */
        /**
         * 前台是否显示删除入口（默认关闭）
         *
         * 渲染与接口两侧共用同一个判断，避免「按钮藏了、接口还能删」这种半吊子状态。
         *
         * @return bool
         */
        public static function frontDeleteOn()
        {
            return self::boolOpt('adminDelete', false);
        }

        public static function boolOpt($key, $default = false)
        {
            $value = self::opt($key, $default ? '1' : '0');

            return '1' === strval($value) || true === $value;
        }

        /**
         * 规范化后的访问路径
         *
         * @return string
         */
        public static function slug()
        {
            return self::normalizeSlug(self::opt('slug', 'qingyan'));
        }

        /**
         * 规范化路由前缀
         *
         * @param mixed $slug
         * @return string
         */
        protected static function normalizeSlug($slug)
        {
            $slug = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $slug);

            return '' === $slug ? 'qingyan' : $slug;
        }

        /* ------------------------------------------------------------------ */
        /* 路由                                                               */
        /* ------------------------------------------------------------------ */

        /**
         * 注册 / 同步路由
         *
         * @param bool $force 是否强制重写
         * @return bool 是否发生了重写
         */
        public static function installRoute($force = false)
        {
            $slug  = self::slug();
            $table = (array) Helper::options()->routingTable;

            $apiUrl = isset($table[self::ROUTE_API]['url']) ? (string) $table[self::ROUTE_API]['url'] : '';
            $homeUrl = isset($table[self::ROUTE_HOME]['url']) ? (string) $table[self::ROUTE_HOME]['url'] : '';
            $pageUrl = isset($table[self::ROUTE_PAGE]['url']) ? (string) $table[self::ROUTE_PAGE]['url'] : '';

            $upToDate = ('' !== $apiUrl) && (false !== strpos($apiUrl, '/' . $slug . '/api'))
                && ('' !== $homeUrl) && (false !== strpos($homeUrl, '/' . $slug));

            if ($upToDate && !$force) {
                return false;
            }

            // 【关键】Helper::addRoute() 是「追加」语义：内部
            // array_merge($pre, [新路由], $next)，若 $next 里已经有同名旧路由，
            // 旧定义会覆盖新定义 —— 改 slug 后路由 URL 根本不会变。
            // 所以重写前必须先按名字移除。
            foreach (array(self::ROUTE_API, self::ROUTE_HOME, self::ROUTE_PAGE) as $route) {
                try {
                    Helper::removeRoute($route);
                } catch (Throwable $e) {
                    // 本来就不存在时忽略
                }
            }

            // 【关键】第 5 个参数 'index' 表示插到首页路由之后。
            // Typecho 默认有一条 page 路由 /[slug]，会吃掉 /qingyan 这种单段路径。
            // 必须抢在它前面，否则访问 /qingyan 会 404。
            Helper::addRoute(self::ROUTE_API, '/' . $slug . '/api/[do:alpha]', 'Qingyan_Api', 'dispatch', 'index');
            Helper::addRoute(self::ROUTE_HOME, '/' . $slug, 'Qingyan_Front', 'dispatch', 'index');
            Helper::addRoute(self::ROUTE_PAGE, '/' . $slug . '/page/[page:digital]', 'Qingyan_Front', 'dispatch', 'index');

            return true;
        }

        /**
         * 前台展示页地址
         *
         * @deprecated 独立页面模式已移除，请用 frontUrl()
         * @param int $page
         * @return string
         */
        public static function homeUrl($page = 1)
        {
            return self::frontUrl($page);
        }


        /**
         * API 地址
         *
         * @param string $do 动作名
         * @return string
         */
        public static function apiUrl($do)
        {
            $options = Helper::options();
            $action  = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $do);
            $path    = self::slug() . '/api/' . $action;

            // 【关键】不要额外再拼 index.php。
            // Typecho 的 $options->index 在「未开启伪静态」时，
            // 值已经是 http://域名/index.php（见 Widget/Options::___index）。
            // 旧代码多拼了一次前缀，导致出现 index.php/index.php/... 的重复。
            $base = (string) $options->index;

            if ('' === $base) {
                $base = (string) $options->siteUrl;
            }

            // 未开伪静态、但基址里没有 index.php 时补上（兼容个别版本差异）
            if (empty($options->rewrite) && !preg_match('#index\.php(/|$)#', $base)) {
                $base = Typecho_Common::url('index.php', $base);
            }

            $url = Typecho_Common::url($path, $base);

            // 兜底：无论基址里已经含了多少段 index.php，
            // 统一折叠成一段，确保不会再出现重复。
            $url = preg_replace('#(index\.php/)+#', 'index.php/', $url);

            return $url;
        }

        /**
         * 静态资源地址
         *
         * 末尾带一个「文件修改时间」版本号。
         *
         * 【为什么必须带】插件直接引用这个地址，页面本身不带任何指纹，
         * 不加版本号的话改完 CSS/JS 浏览器会一直吃旧缓存 ——
         * 曾经就因为 `.qy-mood-ico` 是新加的规则，旧缓存里没有，
         * 结果心情 SVG 按原始尺寸（200px）铺出来，整个发布框一团糟。
         *
         * @param string $file
         * @return string
         */
        public static function assetUrl($file)
        {
            $file = ltrim((string) $file, '/');
            $url  = rtrim(Helper::options()->pluginUrl, '/') . '/Qingyan/assets/' . $file;

            // 文件可能已经被 rawurlencode 过（中文名场景），查文件系统时要还原
            $path = __TYPECHO_ROOT_DIR__ . '/usr/plugins/Qingyan/assets/' . rawurldecode($file);

            if (is_file($path)) {
                $url .= '?v=' . filemtime($path);
            }

            return $url;
        }

        /* ------------------------------------------------------------------ */
        /* 后台菜单                                                           */
        /* ------------------------------------------------------------------ */

        /**
         * 注册后台面板：后台 → 管理 → 轻言
         *
         * 【菜单重复的根治】
         * 之前用 Helper::addMenu() 新建一级菜单，而 addMenu 每次调用都会
         * 在 panelTable['parent'] 里追加一个新索引，activate() 跑几次就堆几个。
         *
         * 现在改成:挂到已有的「管理」菜单（索引 3）下面做二级面板，
         * 不创建任何一级菜单 —— 这是同类插件 Typecho-Plugin-Says 的做法。
         *
         * Helper::addPanel() 本身是追加式的（child[$index][] = ...），
         * 所以这里先 remove 再 add，保证幂等：无论启用多少次都只有一项。
         *
         * @return void
         */
        public static function installMenu()
        {
            try {
                // 先清：包括历史遗留的重复面板
                Helper::removePanel(self::MENU_INDEX, self::PANEL_FILE);
            } catch (Throwable $e) {
                // 忽略，某些版本在没有该面板时可能报错
            }

            try {
                // 清理早期版本误建的一级菜单
                self::purgeMenus(NULL);
            } catch (Throwable $e) {
                // 忽略
            }

            Helper::addPanel(
                self::MENU_INDEX,
                self::PANEL_FILE,
                self::MENU_TITLE,
                '发布与管理轻言短内容',
                'administrator'
            );
        }

        /**
         * 统计 panelTable 里「轻言」面板的数量
         *
         * 正常应为 1。大于 1 说明历史版本堆积了重复项。
         *
         * @return int
         */
        public static function countPanels()
        {
            $count = 0;
            $table = self::panelTable();

            if (empty($table) || !isset($table['child']) || !is_array($table['child'])) {
                return 0;
            }

            // 目标文件名在库里的形态：addPanel 会 urlencode
            $target = array(
                'extending.php?panel=' . urlencode(trim(self::PANEL_FILE, '/')),
                'extending.php?panel=' . trim(self::PANEL_FILE, '/'),
            );

            foreach ($table['child'] as $index => $panels) {
                if (!is_array($panels)) {
                    continue;
                }

                foreach ($panels as $panel) {
                    if (is_array($panel) && isset($panel[2]) && in_array((string) $panel[2], $target, true)) {
                        $count++;
                    }
                }
            }

            return $count;
        }

        /**
         * 清理所有「轻言」面板，只保留一个
         *
         * 直接改 panelTable 写回 options 表，不走 Helper，
         * 这样能跨索引清理（历史版本可能建在别的一级菜单下）。
         *
         * @return int 清理掉的条数
         */
        public static function dedupePanels()
        {
            $removed = 0;

            try {
                $table = self::panelTable();

                if (empty($table) || !isset($table['child']) || !is_array($table['child'])) {
                    return 0;
                }

                $target = array(
                    'extending.php?panel=' . urlencode(trim(self::PANEL_FILE, '/')),
                    'extending.php?panel=' . trim(self::PANEL_FILE, '/'),
                );

                $kept = false;

                foreach ($table['child'] as $index => $panels) {
                    if (!is_array($panels)) {
                        continue;
                    }

                    foreach ($panels as $key => $panel) {
                        if (!is_array($panel) || !isset($panel[2]) || !in_array((string) $panel[2], $target, true)) {
                            continue;
                        }

                        // 保留第一个，且挪到「管理」下
                        if (!$kept) {
                            $kept = true;

                            if ((int) $index !== self::MENU_INDEX) {
                                $table['child'][self::MENU_INDEX][] = $panel;
                                unset($table['child'][$index][$key]);
                                $removed++;
                            }

                            continue;
                        }

                        unset($table['child'][$index][$key]);
                        $removed++;
                    }
                }

                if ($removed > 0) {
                    self::writePanelTable($table);
                }
            } catch (Throwable $e) {
                return 0;
            }

            return $removed;
        }

        /**
         * 读取 panelTable（自动反序列化）
         *
         * @return array
         */
        public static function panelTable()
        {
            try {
                $table = Helper::options()->panelTable;

                if (is_string($table)) {
                    $table = @unserialize($table);
                }

                return is_array($table) ? $table : array();
            } catch (Throwable $e) {
                return array();
            }
        }

        /**
         * 查找所有标题为「轻言」的菜单索引
         *
         * panelTable 结构（1.2 / 1.3 一致）：
         *
         *     array(
         *         'parent' => array( 5 => '轻言', ... ),   // 索引 => 菜单标题
         *         'child'  => array( 5 => array(...) ),    // 索引 => 子面板
         *         'file'   => array( ... ),
         *     )
         *
         * 所以当索引的地方是 parent 的**键**，不是顶层键。
         *
         * @return array 索引数组（整型，已排序）
         */
        public static function findMenuIndexes()
        {
            $found = array();
            $table = self::panelTable();

            if (empty($table)) {
                return $found;
            }

            // 主路径：从 parent 里找
            if (isset($table['parent']) && is_array($table['parent'])) {
                foreach ($table['parent'] as $key => $title) {
                    if (is_int($key) && self::MENU_TITLE === $title) {
                        $found[] = (int) $key;
                    }
                }
            }

            // 兜底：从 child 里找子面板文件名含 Qingyan/panel.php 的
            if (empty($found) && isset($table['child']) && is_array($table['child'])) {
                foreach ($table['child'] as $key => $panels) {
                    if (!is_int($key) || !is_array($panels)) {
                        continue;
                    }

                    foreach ($panels as $panel) {
                        if (is_array($panel) && isset($panel[1])
                            && false !== strpos((string) $panel[1], 'Qingyan/panel.php')) {
                            $found[] = (int) $key;
                            break;
                        }
                    }
                }
            }

            // 兼容：顶层直接是数字键的扁平结构
            if (empty($found)) {
                foreach ($table as $key => $value) {
                    if (is_int($key) && self::MENU_TITLE === $value) {
                        $found[] = (int) $key;
                    }
                }
            }

            $found = array_values(array_unique($found));
            sort($found, SORT_NUMERIC);

            return $found;
        }

        /**
         * 清理「轻言」菜单
         *
         * 直接改 panelTable 并写回 options 表，不走 Helper，
         * 这样能一次清掉所有重复项（Helper::removeMenu 按标题删，
         * 历史遗留的多个索引未必都能覆盖）。
         *
         * @param int|null $keep 保留的索引，传 NULL 表示全部删除
         * @return int 删除的条数
         */
        public static function purgeMenus($keep = NULL)
        {
            $removed = 0;

            try {
                $table   = self::panelTable();
                $indexes = self::findMenuIndexes();

                if (empty($indexes) || empty($table)) {
                    return 0;
                }

                foreach ($indexes as $index) {
                    if (NULL !== $keep && (int) $keep === (int) $index) {
                        continue;
                    }

                    foreach (array('parent', 'child', 'file') as $group) {
                        if (isset($table[$group]) && is_array($table[$group])) {
                            unset($table[$group][$index]);
                        }
                    }

                    $removed++;
                }

                if ($removed > 0) {
                    self::writePanelTable($table);
                }
            } catch (Throwable $e) {
                return 0;
            }

            return $removed;
        }

        /**
         * 写回 panelTable
         *
         * @param array $table
         * @return bool
         */
        public static function writePanelTablePublic($table)
        {
            return self::writePanelTable($table);
        }

        /**
         * 写回 panelTable
         *
         * @param array $table
         * @return bool
         */
        protected static function writePanelTable($table)
        {
            try {
                $db = qy_db();
                $db->query(
                    $db->update('table.options')
                        ->rows(array('value' => serialize($table)))
                        ->where('name = ?', 'panelTable')
                );

                return true;
            } catch (Throwable $e) {
                // 构造器失败就退回裸 SQL
                try {
                    $db     = qy_db();
                    $prefix = method_exists($db, 'getPrefix') ? $db->getPrefix() : 'typecho_';
                    $value  = self::quoteValue($db, serialize($table));

                    $db->query(
                        "UPDATE `{$prefix}options` SET `value` = {$value} WHERE `name` = 'panelTable'",
                        QY_DB_WRITE
                    );

                    return true;
                } catch (Throwable $e2) {
                    return false;
                }
            }
        }

        /* ------------------------------------------------------------------ */
        /* 对外辅助                                                           */
        /* ------------------------------------------------------------------ */

        /**
         * 供主题调用：获取最新的若干条轻言
         *
         * @param int $limit
         * @return array
         */
        public static function latest($limit = 5)
        {
            return Qingyan_Model::latest($limit);
        }

        /**
         * 【主题嵌入】在当前位置输出完整时间线
         *
         * 放在主题模板里，时间线会长在主题的结构中（带着主题的
         * header / sidebar / footer），而不是另开一个独立页面。
         *
         * 典型用法（主题的 page-qingyan.php 或任意模板）：
         *
         *     <?php $this->need('header.php'); ?>
         *     <main class="site-main">
         *         <?php Qingyan_Plugin::render(); ?>
         *     </main>
         *     <?php $this->need('footer.php'); ?>
         *
         * @param array $opts 可选参数：
         *                    size     每页条数，默认取插件配置
         *                    editor   是否显示发布框，默认自动判断
         *                    css      是否引入自带样式，默认 true
         *                    baseUrl  分页基地址，默认当前页面
         *                    page     页码，默认读 $_GET['page']
         * @return void
         */
        public static function render($opts = array())
        {
            if (!is_array($opts)) {
                $opts = array();
            }

            $page = isset($opts['page'])
                ? intval($opts['page'])
                : (isset($_GET['page']) ? intval($_GET['page']) : 1);

            Qingyan_Renderer::timeline($page, $opts);
        }

        /**
         * 【主题嵌入】只取数据，不输出 HTML
         *
         * 想完全自己控制 markup 时用这个，拿到数组自己在模板里循环。
         *
         * @param int $limit
         * @return array
         */
        public static function renderList($limit = 10)
        {
            return Qingyan_Model::latest($limit);
        }

        /**
         * 【自定义输出】取一页完整的数据包
         *
         * 这是给主题自定义布局的唯一入口。一次调用，返回列表数据、
         * 发布框 / 分页 / 资源的 HTML 片段，以及分页参数。
         * 想放哪、想怎么用，全部由主题决定。
         *
         * 最简用法：
         *
         *     <?php $qy = Qingyan_Plugin::query(array('size' => 10)); ?>
         *     <?php echo $qy['assets']; ?>
         *     <?php echo $qy['editor']; ?>
         *     <?php foreach ($qy['list'] as $s): ?>
         *         <div class="say">
         *             <div><?php echo $s['html']; ?></div>
         *             <button data-qy-like="<?php echo $s['id']; ?>">
         *                 <?php echo $s['liked'] ? '已赞' : '赞'; ?>
         *                 <span data-qy-count><?php echo $s['likes']; ?></span>
         *             </button>
         *             <?php echo $s['commentsHtml']; ?>
         *         </div>
         *     <?php endforeach; ?>
         *     <?php echo $qy['pager']; ?>
         *
         * @param array $opts
         *   size    每页条数，默认取插件配置
         *   page    页码，默认读 $_GET['page']
         *   css     是否附带自带样式，默认 true
         *   baseUrl 分页基地址，默认当前页面
         * @return array
         *   list       条目数组（字段见 README）
         *   editor     发布框 HTML（无权限时为空串）
         *   pager      分页 HTML
         *   emptyHtml  空状态 HTML
         *   css        样式片段（link + 自定义 CSS）
         *   js         脚本片段（配置 + JS 文件）
         *   assets     css + js 的合体，图省事用这个
         *   pagerData  分页参数，自己写分页时用
         *   canPost    当前访客能否发布
         *   isAdmin    是否管理员
         *   page/pages/total/size/empty
         *
         * 其中 editor / pager / emptyHtml / list[].itemHtml 与
         * render() 的输出**逐字节一致**，均取自同一段渲染代码，
         * 所以照抄模板即可得到与内置完全相同的 DOM 结构。
         */
        public static function query($opts = array())
        {
            return Qingyan_Renderer::snapshot($opts);
        }

        /**
         * 前台展示页地址（后台链接与跳转用）
         *
         * 优先用配置项 frontUrl（比如你把时间线嵌在了某个独立页面里）。
         * 留空时回落到插件自带的独立前台 /qingyan。
         *
         * @param int $page 页码，1 起
         * @return string
         */
        public static function frontUrl($page = 1)
        {
            $url = trim((string) self::opt('frontUrl', ''));

            if ('' !== $url) {
                return $url;
            }

            return self::frontPageUrl($page);
        }

        /**
         * 插件自带独立前台的地址
         *
         * 【关键】不要额外再拼 index.php。
         * Typecho 的 $options->index 在未开伪静态时，
         * 值本身已经是 http://域名/index.php。多拼一次就会出现
         * index.php/index.php/... 的重复（这个坑已经踩过）。
         *
         * @param int $page 页码，1 起
         * @return string
         */
        public static function frontPageUrl($page = 1)
        {
            $options = Helper::options();
            $base    = (string) $options->index;

            if ('' === $base) {
                $base = (string) $options->siteUrl;
            }

            // 未开伪静态、但基址里没有 index.php 时才补（兼容版本差异）
            if (empty($options->rewrite) && !preg_match('#index\.php(/|$)#', $base)) {
                $base = Typecho_Common::url('index.php', $base);
            }

            $path = self::slug() . (intval($page) > 1 ? '/page/' . intval($page) : '');
            $url  = Typecho_Common::url($path, $base);

            // 兜底：无论已有几段，统一折叠成一段
            return preg_replace('#(index\.php/)+#', 'index.php/', $url);
        }

        /**
         * 抛出一个 Typecho 能友好展示的插件异常
         *
         * @param string $message
         * @return Throwable
         */
        protected static function pluginException($message)
        {
            foreach (array('Typecho_Plugin_Exception', 'Typecho\\Plugin\\Exception') as $class) {
                if (class_exists($class)) {
                    return new $class(_t($message));
                }
            }

            return new Exception($message);
        }
    }
}

namespace TypechoPlugin\Qingyan {
    if (!class_exists('TypechoPlugin\\Qingyan\\Plugin', false)) {
        /**
         * Typecho 1.3+ 只认这个命名空间下的 Plugin 类
         */
        class Plugin extends \Qingyan_Plugin_Base
        {
        }
    }
}

namespace {
    if (!class_exists('Qingyan_Plugin', false)) {
        /**
         * Typecho 1.2.x 只认 目录名_Plugin 这个类名
         */
        class Qingyan_Plugin extends \Qingyan_Plugin_Base
        {
        }
    }

    /**
     * 自愈：确保 options 表里有 plugin:Qingyan 这一行
     *
     * 放在文件末尾（类定义之后）执行，这样只要 Typecho 加载了本文件，
     * 配置行就会被补齐——包括「用旧版激活过、之后才覆盖文件」的情况，
     * 因为那时 activate() 不会重跑，只有这里能补救。
     *
     * 核心是在 Config.php 里先 require 本文件、再调用 Options->plugin()，
     * 所以这一步赶在报错之前完成。
     */
    try {
        Qingyan_Plugin_Base::writeDefaultConfig();
    } catch (Throwable $e) {
        // 静默失败，不能因为自愈逻辑影响页面
    }
}
