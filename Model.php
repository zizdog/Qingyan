<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

require_once dirname(__FILE__) . '/compat.php';

/**
 * 轻言 - 数据层
 *
 * 负责建表 / 删表、短内容的增删改查、点赞、评论、上传与内容格式化。
 * 兼容 Typecho 的 MySQL、SQLite、Pgsql 三种数据库适配器。
 *
 * @package Qingyan
 * @author  Yuanbao
 * @version 2.8.5
 */
class Qingyan_Model
{
    /** 数据表名（单表设计：内容与点赞、评论同表存储） */
    const T_POST = 'table.qingyan';

    /**
     * 默认心情档位
     *
     * 卡片左侧那个位置默认显示发布者头像；选了别的档位才换成人情图。
     */
    const MOOD_DEFAULT = 'avatar';

    /** 单条内容最多保留的点赞指纹数，超出后丢弃最早的 */
    const MAX_LIKES = 2000;

    /** 单条内容最多保留的评论数，防止 JSON 无限膨胀 */
    const MAX_COMMENTS = 500;

    /** @var array 作者缓存，避免同一请求内重复查 users 表 */
    private static $authors = array();

    /**
     * 获取数据库实例
     *
     * @return Typecho_Db
     */
    public static function db()
    {
        return Typecho_Db::get();
    }

    /**
     * 判断当前数据库类型
     *
     * @return string mysql|sqlite|pgsql
     */
    public static function driver()
    {
        $adapter = strtolower((string) self::db()->getAdapterName());

        if (false !== strpos($adapter, 'sqlite')) {
            return 'sqlite';
        }

        if (false !== strpos($adapter, 'pgsql') || false !== strpos($adapter, 'postgres')) {
            return 'pgsql';
        }

        return 'mysql';
    }

    /* ------------------------------------------------------------------ */
    /* 安装与卸载                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * 创建数据表
     *
     * @return bool
     */
    public static function install()
    {
        $db     = self::db();
        $prefix = $db->getPrefix();
        $driver = self::driver();

        if ('sqlite' === $driver) {
            $sql = array(
                "CREATE TABLE IF NOT EXISTS {$prefix}qingyan (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    authorId INTEGER NOT NULL DEFAULT 0,
                    author TEXT NOT NULL DEFAULT '',
                    authorMail TEXT NOT NULL DEFAULT '',
                    content TEXT,
                    liked TEXT,
                    comments TEXT,
                    status TEXT NOT NULL DEFAULT 'publish',
                    mood TEXT NOT NULL DEFAULT 'default',
                    likes INTEGER NOT NULL DEFAULT 0,
                    commentsNum INTEGER NOT NULL DEFAULT 0,
                    source TEXT NOT NULL DEFAULT 'web',
                    agent TEXT NOT NULL DEFAULT '',
                    created INTEGER NOT NULL DEFAULT 0,
                    modified INTEGER NOT NULL DEFAULT 0
                )",
                "CREATE INDEX IF NOT EXISTS idx_qingyan_status_created ON {$prefix}qingyan (status, created)",
            );
        } elseif ('pgsql' === $driver) {
            $sql = array(
                "CREATE TABLE IF NOT EXISTS {$prefix}qingyan (
                    id SERIAL PRIMARY KEY,
                    \"authorId\" INTEGER NOT NULL DEFAULT 0,
                    author VARCHAR(64) NOT NULL DEFAULT '',
                    \"authorMail\" VARCHAR(128) NOT NULL DEFAULT '',
                    content TEXT,
                    liked TEXT,
                    comments TEXT,
                    status VARCHAR(16) NOT NULL DEFAULT 'publish',
                    mood VARCHAR(20) NOT NULL DEFAULT 'default',
                    likes INTEGER NOT NULL DEFAULT 0,
                    \"commentsNum\" INTEGER NOT NULL DEFAULT 0,
                    source VARCHAR(32) NOT NULL DEFAULT 'web',
                    agent VARCHAR(255) NOT NULL DEFAULT '',
                    created INTEGER NOT NULL DEFAULT 0,
                    modified INTEGER NOT NULL DEFAULT 0
                )",
                "CREATE INDEX IF NOT EXISTS idx_qingyan_status_created ON {$prefix}qingyan (status, created)",
            );
        } else {
            $charset = defined('__TYPECHO_DB_CHARSET__') ? __TYPECHO_DB_CHARSET__ : 'utf8mb4';
            $collate = 'utf8mb4_general_ci';

            if (false !== stripos($charset, 'utf8mb4')) {
                $collate = 'utf8mb4_general_ci';
            } elseif (false !== stripos($charset, 'utf8')) {
                $collate = 'utf8_general_ci';
            } else {
                $collate = '';
            }

            $collateSql = '' === $collate ? '' : " COLLATE={$collate}";

            $sql = array(
                "CREATE TABLE IF NOT EXISTS `{$prefix}qingyan` (
                    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                    `authorId` int(10) unsigned NOT NULL DEFAULT 0,
                    `author` varchar(64) NOT NULL DEFAULT '',
                    `authorMail` varchar(128) NOT NULL DEFAULT '',
                    `content` text,
                    `liked` text,
                    `comments` text,
                    `status` varchar(16) NOT NULL DEFAULT 'publish',
                    `mood` varchar(20) NOT NULL DEFAULT 'default',
                    `likes` int(10) unsigned NOT NULL DEFAULT 0,
                    `commentsNum` int(10) unsigned NOT NULL DEFAULT 0,
                    `source` varchar(32) NOT NULL DEFAULT 'web',
                    `agent` varchar(255) NOT NULL DEFAULT '',
                    `created` int(10) unsigned NOT NULL DEFAULT 0,
                    `modified` int(10) unsigned NOT NULL DEFAULT 0,
                    PRIMARY KEY (`id`),
                    KEY `idx_status_created` (`status`, `created`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$charset}{$collateSql}",
            );
        }

        foreach ($sql as $query) {
            $db->query($query, QY_DB_WRITE);
        }

        // 老库的表已经存在，CREATE TABLE IF NOT EXISTS 不会补新列，这里补一次
        self::upgrade();

        return true;
    }

    /**
     * 增量升级：给已经建好的表补新列
     *
     * install() 用的是 CREATE TABLE IF NOT EXISTS，老库不会自动长出新列。
     * 这里做成幂等的：先试探着 SELECT 一次，列不存在才 ALTER，
     * 任何异常都不往外抛，免得把插件激活整个拖垮。
     *
     * @return void
     */
    public static function upgrade()
    {
        $db     = self::db();
        $prefix = $db->getPrefix();
        $driver = self::driver();
        $table  = $prefix . 'qingyan';

        // mood：心情徽章（新增）
        if (!self::hasColumn($table, 'mood')) {
            if ('mysql' === $driver) {
                $sql = "ALTER TABLE `{$table}` ADD COLUMN `mood` varchar(20) NOT NULL DEFAULT 'default'";
            } else {
                $sql = "ALTER TABLE \"{$table}\" ADD COLUMN \"mood\" VARCHAR(20) NOT NULL DEFAULT 'default'";
            }

            try {
                $db->query($sql, QY_DB_WRITE);
            } catch (Throwable $e) {
                // 并发激活等情况下列可能刚被加上，忽略即可
            }
        }

        // 历史数据：默认档位从 'default' 改名成了 'avatar'，
        // 读的时候 normalizeMood() 也能兜住，但顺手把库里的值也统一掉
        try {
            $db->query(
                $db->update(self::T_POST)
                    ->rows(array('mood' => self::MOOD_DEFAULT))
                    ->where('mood = ?', 'default'),
                QY_DB_WRITE
            );
        } catch (Throwable $e) {
            // 忽略
        }
    }

    /**
     * 表里有没有某一列
     *
     * 用「查一下试试」而不是 information_schema：三种驱动都能用，不用分支。
     *
     * @param string $table
     * @param string $column
     * @return bool
     */
    protected static function hasColumn($table, $column)
    {
        try {
            self::db()->fetchRow(self::db()->select($column)->from(self::T_POST)->limit(1));

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * 删除数据表（禁用插件且选择清理数据时调用）
     *
     * @return bool
     */
    public static function uninstall()
    {
        $db     = self::db();
        $prefix = $db->getPrefix();

        $db->query("DROP TABLE IF EXISTS {$prefix}qingyan", QY_DB_WRITE);

        return true;
    }

    /* ------------------------------------------------------------------ */
    /* 内容 CRUD                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * 新增一条短内容
     *
     * @param array $data
     * @return int 新记录的 ID
     */
    public static function add($data)
    {
        $db   = self::db();
        $data = self::filterFields($data);

        if (!isset($data['created'])) {
            $data['created'] = self::time();
        }

        $data['modified'] = $data['created'];

        $insertId = $db->query($db->insert(self::T_POST)->rows($data));
        $insertId = intval($insertId);

        if ($insertId <= 0) {
            // 部分适配器不会回传 lastInsertId，退回取最大 ID
            $row = $db->fetchRow($db->select('MAX(id) AS mid')->from(self::T_POST));
            $insertId = $row ? intval($row['mid']) : 0;
        }

        return $insertId;
    }

    /**
     * 更新一条短内容
     *
     * @param int   $id
     * @param array $data
     * @return bool
     */
    public static function update($id, $data)
    {
        $db   = self::db();
        $data = self::filterFields($data);
        $data['modified'] = self::time();

        $db->query($db->update(self::T_POST)->rows($data)->where('id = ?', intval($id)));

        return true;
    }

    /**
     * 删除一条短内容
     *
     * 单表设计：点赞与评论都存在同一行的 JSON 字段里，删行即全部清除。
     *
     * @param int $id
     * @return bool
     */
    public static function delete($id)
    {
        $db = self::db();
        $id = intval($id);

        $db->query($db->delete(self::T_POST)->where('id = ?', $id));

        return true;
    }

    /**
     * 读取单条短内容
     *
     * @param int $id
     * @return array|null
     */
    public static function get($id)
    {
        $db  = self::db();
        $row = $db->fetchRow($db->select()->from(self::T_POST)->where('id = ?', intval($id))->limit(1));

        return $row ? self::normalize($row) : NULL;
    }

    /**
     * 分页读取短内容
     *
     * @param int    $page   页码，从 1 开始
     * @param int    $size   每页条数
     * @param string $status publish|pending|all
     * @return array
     */
    public static function page($page = 1, $size = 10, $status = 'publish')
    {
        $db     = self::db();
        $page   = max(1, intval($page));
        $size   = max(1, min(100, intval($size)));
        $select = $db->select()->from(self::T_POST);

        if ('all' !== $status) {
            $select->where('status = ?', $status);
        }

        $select->order('created', QY_SORT_DESC)
            ->offset(($page - 1) * $size)
            ->limit($size);

        $rows = $db->fetchAll($select);
        $list = array();

        foreach ($rows as $row) {
            $list[] = self::normalize($row);
        }

        return $list;
    }

    /**
     * 统计短内容数量
     *
     * @param string $status
     * @return int
     */
    public static function count($status = 'publish')
    {
        $db     = self::db();
        $select = $db->select(array('COUNT(1)' => 'num'))->from(self::T_POST);

        if ('all' !== $status) {
            $select->where('status = ?', $status);
        }

        $row = $db->fetchRow($select);

        return $row ? intval($row['num']) : 0;
    }

    /**
     * 最新若干条（供主题侧边栏调用）
     *
     * @param int $limit
     * @return array
     */
    public static function latest($limit = 5)
    {
        return self::page(1, max(1, intval($limit)), 'publish');
    }

    /**
     * 修改状态（审核 / 驳回）
     *
     * @param int    $id
     * @param string $status
     * @return bool
     */
    public static function setStatus($id, $status)
    {
        if (!in_array($status, array('publish', 'pending', 'draft'))) {
            return false;
        }

        self::update($id, array('status' => $status));

        return true;
    }

    /* ------------------------------------------------------------------ */
    /* 点赞（存在主表 liked 字段，JSON 数组）                              */
    /* ------------------------------------------------------------------ */

    /**
     * 解码 JSON 字段
     *
     * @param mixed $value
     * @return array
     */
    public static function decodeJson($value)
    {
        if (is_array($value)) {
            return $value;
        }

        if (NULL === $value || '' === $value || !is_string($value)) {
            return array();
        }

        $data = json_decode($value, true);

        return is_array($data) ? $data : array();
    }

    /**
     * 编码 JSON 字段（失败返回空串，不写坏数据）
     *
     * @param mixed $value
     * @return string
     */
    public static function encodeJson($value)
    {
        if (empty($value) || !is_array($value)) {
            return '';
        }

        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return false === $json ? '' : $json;
    }

    /**
     * 读取某条内容的点赞指纹列表
     *
     * @param int $id
     * @return array
     */
    public static function likedList($id)
    {
        $post = self::get($id);

        return $post ? self::decodeJson(isset($post['liked']) ? $post['liked'] : '') : array();
    }

    /**
     * 切换点赞状态
     *
     * @param int    $id
     * @param string $hash
     * @return array liked 状态与最新计数
     */
    public static function toggleLike($id, $hash)
    {
        $id   = intval($id);
        $hash = (string) $hash;
        $post = self::get($id);

        if (!$post) {
            return array('liked' => false, 'likes' => 0);
        }

        $list = self::decodeJson(isset($post['liked']) ? $post['liked'] : '');
        $pos  = array_search($hash, $list, true);

        if (false !== $pos) {
            // 已点过：取消
            array_splice($list, $pos, 1);
        } else {
            // 超出上限时丢弃最早的，防止字段无限膨胀
            if (count($list) >= self::MAX_LIKES) {
                array_shift($list);
            }

            $list[] = $hash;
        }

        self::saveLiked($id, $list);

        return array(
            'liked' => in_array($hash, $list, true),
            'likes' => self::recountLikes($id),
        );
    }

    /**
     * 写回点赞指纹列表
     *
     * @param int   $id
     * @param array $list
     * @return bool
     */
    protected static function saveLiked($id, $list)
    {
        $db = self::db();
        $db->query(
            $db->update(self::T_POST)
                ->rows(array('liked' => self::encodeJson(array_values($list))))
                ->where('id = ?', intval($id))
        );

        return true;
    }

    /**
     * 重算点赞数（以 JSON 数组长度为准，回写冗余计数字段）
     *
     * @param int $id
     * @return int
     */
    public static function recountLikes($id)
    {
        $id   = intval($id);
        $num  = count(self::likedList($id));
        $db   = self::db();

        $db->query($db->update(self::T_POST)->rows(array('likes' => $num))->where('id = ?', $id));

        return $num;
    }

    /* ------------------------------------------------------------------ */
    /* 评论（存在主表 comments 字段，JSON 数组）                           */
    /* ------------------------------------------------------------------ */

    /**
     * 读取某条内容的评论原始列表
     *
     * @param int $id
     * @return array
     */
    public static function commentList($id)
    {
        $post = self::get($id);

        return $post ? self::decodeJson(isset($post['comments']) ? $post['comments'] : '') : array();
    }

    /**
     * 读取某条短内容的评论
     *
     * @param int  $id
     * @param bool $approvedOnly
     * @return array
     */
    public static function comments($id, $approvedOnly = true)
    {
        return self::prepareComments(self::commentList($id), $approvedOnly);
    }

    /**
     * 过滤 + 排序评论列表
     *
     * comments() 与 commentsMap() 共用，保证两条路径返回的顺序、
     * 过滤规则与数据结构完全一致。
     *
     * @param array $list
     * @param bool  $approvedOnly
     * @return array
     */
    protected static function prepareComments($list, $approvedOnly = true)
    {
        $list = (array) $list;

        if ($approvedOnly) {
            $list = array_values(array_filter($list, function ($item) {
                return isset($item['status']) && 'approved' === $item['status'];
            }));
        }

        usort($list, function ($a, $b) {
            $ta = isset($a['created']) ? intval($a['created']) : 0;
            $tb = isset($b['created']) ? intval($b['created']) : 0;

            return $ta - $tb;
        });

        return $list;
    }

    /**
     * 批量读取多条短内容的评论
     *
     * 单次 `IN (...)` 查询取回所有行的 comments 列，避免逐条 get() 的 N+1。
     *
     * @param array $ids
     * @return array qid => comments
     */
    public static function commentsMap($ids)
    {
        $result = array();
        $clean  = array();

        foreach ((array) $ids as $id) {
            $id = intval($id);

            if ($id > 0) {
                $clean[$id] = $id;
            }
        }

        if (empty($clean)) {
            return $result;
        }

        $db   = self::db();
        $mark = implode(',', $clean);

        try {
            $rows = $db->fetchAll(
                $db->select('id', 'comments')->from(self::T_POST)
                    ->where("id IN ({$mark})")
            );
        } catch (Throwable $e) {
            return $result;
        }

        foreach ((array) $rows as $row) {
            $list = self::decodeJson(isset($row['comments']) ? $row['comments'] : '');

            if (empty($list)) {
                continue;
            }

            $list = self::prepareComments($list, true);

            if (!empty($list)) {
                $result[intval($row['id'])] = $list;
            }
        }

        return $result;
    }

    /**
     * 生成新的评论 ID（毫秒时间戳，保证全局唯一且递增）
     *
     * @param array|null $list 已有评论，用于避免冲突
     * @return int
     */
    protected static function newCommentId($list = NULL)
    {
        $max = 0;

        foreach ((array) $list as $comment) {
            if (isset($comment['id'])) {
                $max = max($max, intval($comment['id']));
            }
        }

        $id = intval(microtime(true) * 1000);

        return $id > $max ? $id : $max + 1;
    }

    /**
     * 新增评论
     *
     * @param int   $id
     * @param array $data
     * @return int 新评论 ID
     */
    public static function addComment($id, $data)
    {
        $id   = intval($id);
        $post = self::get($id);

        if (!$post) {
            return 0;
        }

        $list = self::decodeJson(isset($post['comments']) ? $post['comments'] : '');

        if (count($list) >= self::MAX_COMMENTS) {
            array_shift($list);
        }

        $data = self::filterCommentFields($data);
        $data['id']      = self::newCommentId($list);
        $data['qid']     = $id;
        $data['created'] = self::time();

        $list[] = $data;

        self::saveComments($id, $list);
        self::recountComments($id);

        return intval($data['id']);
    }

    /**
     * 写回评论列表
     *
     * @param int   $id
     * @param array $list
     * @return bool
     */
    protected static function saveComments($id, $list)
    {
        $db = self::db();
        $db->query(
            $db->update(self::T_POST)
                ->rows(array('comments' => self::encodeJson(array_values($list))))
                ->where('id = ?', intval($id))
        );

        return true;
    }

    /**
     * 按评论 ID 定位评论
     *
     * 合并表后评论没有独立的 ID 索引。为避免把整表的 comments JSON
     * 全部 json_decode 一遍，这里先用 LIKE 预筛（评论 id 以
     * `"id":<数字>` 的形式出现在 JSON 里），只解码命中的行。
     * LIKE 无法命中就退回全表，保证正确性。
     *
     * @param int $commentId
     * @return array|null array('post' => 行, 'index' => 下标, 'comment' => 评论)
     */
    public static function findComment($commentId)
    {
        $commentId = intval($commentId);
        $db        = self::db();

        if ($commentId <= 0) {
            return NULL;
        }

        $select = $db->select('id', 'comments')->from(self::T_POST)
            ->where('comments IS NOT NULL')
            ->where("comments <> ''");

        try {
            $rows = $db->fetchAll(
                $db->select('id', 'comments')->from(self::T_POST)
                    ->where('comments IS NOT NULL')
                    ->where("comments <> ''")
                    ->where('comments LIKE ?', '%"id":' . $commentId . '%')
            );
        } catch (Throwable $e) {
            $rows = array();
        }

        if (empty($rows)) {
            // 预筛不可用或未命中：退回全表扫描（保底正确）
            $rows = $db->fetchAll($select);
        }

        foreach ((array) $rows as $row) {
            $list = self::decodeJson(isset($row['comments']) ? $row['comments'] : '');

            foreach ($list as $index => $comment) {
                if (isset($comment['id']) && intval($comment['id']) === $commentId) {
                    return array('post' => $row, 'index' => $index, 'comment' => $comment);
                }
            }
        }

        return NULL;
    }

    /**
     * 删除评论
     *
     * @param int $commentId
     * @return bool
     */
    public static function deleteComment($commentId)
    {
        $found = self::findComment($commentId);

        if (NULL === $found) {
            return false;
        }

        $qid  = intval($found['post']['id']);
        $list = self::decodeJson(isset($found['post']['comments']) ? $found['post']['comments'] : '');

        unset($list[$found['index']]);

        self::saveComments($qid, array_values($list));
        self::recountComments($qid);

        return true;
    }

    /**
     * 修改评论状态
     *
     * @param int    $commentId
     * @param string $status
     * @return bool
     */
    public static function setCommentStatus($commentId, $status)
    {
        $found = self::findComment($commentId);

        if (NULL === $found) {
            return false;
        }

        $qid  = intval($found['post']['id']);
        $list = self::decodeJson(isset($found['post']['comments']) ? $found['post']['comments'] : '');

        if (!isset($list[$found['index']])) {
            return false;
        }

        $list[$found['index']]['status'] = $status;

        self::saveComments($qid, $list);
        self::recountComments($qid);

        return true;
    }

    /**
     * 重算评论数（只数已通过的，回写冗余计数字段）
     *
     * @param int $id
     * @return int
     */
    public static function recountComments($id)
    {
        $id   = intval($id);
        $list = self::commentList($id);
        $num  = 0;

        foreach ($list as $comment) {
            if (isset($comment['status']) && 'approved' === $comment['status']) {
                $num++;
            }
        }

        $db = self::db();
        $db->query($db->update(self::T_POST)->rows(array('commentsNum' => $num))->where('id = ?', $id));

        return $num;
    }

    /**
     * 某 IP 最近一次评论时间（用于频率限制）
     *
     * 先用 LIKE 预筛出 comments JSON 里含该 IP 的行（匹配带引号的完整
     * `"ip":"x.x.x.x"`，避免前缀误命中），只解码这些行；预筛不可用时
     * 退回全表，保证限频语义不变。
     *
     * @param string $ip
     * @return int
     */
    public static function lastCommentTime($ip)
    {
        $ip = (string) $ip;

        if ('' === $ip) {
            return 0;
        }

        $db     = self::db();
        $select = $db->select('comments')->from(self::T_POST)
            ->where('comments IS NOT NULL')
            ->where("comments <> ''");

        $prefiltered = false;

        try {
            $rows = $db->fetchAll(
                $db->select('comments')->from(self::T_POST)
                    ->where('comments IS NOT NULL')
                    ->where("comments <> ''")
                    ->where('comments LIKE ?', '%"ip":"' . self::escapeLike($ip) . '"%')
            );
            $prefiltered = true;
        } catch (Throwable $e) {
            $rows = array();
        }

        if (!$prefiltered) {
            // 预筛语句本身不可用时才退回全表
            $rows = $db->fetchAll($select);
        }

        $last = 0;

        foreach ((array) $rows as $row) {
            $list = self::decodeJson(isset($row['comments']) ? $row['comments'] : '');

            foreach ($list as $comment) {
                if (isset($comment['ip']) && $comment['ip'] === $ip) {
                    $last = max($last, intval($comment['created']));
                }
            }
        }

        return $last;
    }

    /**
     * 转义 LIKE 模式里的元字符（% _ \）
     *
     * @param string $value
     * @return string
     */
    protected static function escapeLike($value)
    {
        return str_replace(
            array('\\', '%', '_'),
            array('\\\\', '\\%', '\\_'),
            (string) $value
        );
    }

    /**
     * 全站评论列表（后台管理用，含待审核）
     *
     * 按创建时间倒序合并所有内容的评论。
     *
     * 只取 comments 非空的行；status='pending' 时额外用 LIKE 预筛出
     * 含待审评论的行（missing status 的旧数据按 approved 处理，所以
     * 'approved' 不做预筛，避免漏掉）。可选 $limit / $offset 直接分页
     * 返回，后台就不必把整表解码两遍。
     *
     * @param string $status 传 '' 表示全部，'approved' / 'pending' 过滤
     * @param int    $limit  0 表示不限制
     * @param int    $offset
     * @return array
     */
    public static function allComments($status = '', $limit = 0, $offset = 0)
    {
        $db     = self::db();
        $result = array();

        $select = $db->select('id', 'content', 'comments')->from(self::T_POST)
            ->where('comments IS NOT NULL')
            ->where("comments <> ''");

        if ('pending' === $status) {
            $select->where('comments LIKE ?', '%"status":"pending"%');
        }

        try {
            $rows = $db->fetchAll($select);
        } catch (Throwable $e) {
            return $result;
        }

        foreach ((array) $rows as $row) {
            $list = self::decodeJson(isset($row['comments']) ? $row['comments'] : '');

            foreach ($list as $comment) {
                if ('' !== $status) {
                    $current = isset($comment['status']) ? $comment['status'] : 'approved';

                    if ($current !== $status) {
                        continue;
                    }
                }

                $comment['qid']     = intval($row['id']);
                $comment['excerpt'] = self::excerpt(isset($row['content']) ? $row['content'] : '', 30);

                $result[] = $comment;
            }
        }

        usort($result, function ($a, $b) {
            $ta = isset($a['created']) ? intval($a['created']) : 0;
            $tb = isset($b['created']) ? intval($b['created']) : 0;

            return $tb - $ta;
        });

        $limit  = intval($limit);
        $offset = max(0, intval($offset));

        if ($limit > 0) {
            $result = array_slice($result, $offset, $limit);
        } elseif ($offset > 0) {
            $result = array_slice($result, $offset);
        }

        return $result;
    }

    /**
     * 截取正文摘要
     *
     * @param string $text
     * @param int    $length
     * @return string
     */
    /**
     * 把用户输入的搜索词拆成若干关键词
     *
     * 空格（含全角空格）分隔，最多 5 个词、每个词最多 32 字 —— 再多也没有意义，
     * 只会让 LIKE 变慢。多个词之间是 **AND**（都要命中），比 Typecho 文章搜索把
     * 空格直接换成 `%` 的写法更宽容（那个要求词序也一致）。
     *
     * @param string $keyword
     * @return array
     */
    protected static function searchWords($keyword)
    {
        $keyword = trim((string) $keyword);

        if ('' === $keyword) {
            return array();
        }

        $parts = preg_split('/[\s\x{3000}]+/u', $keyword, 6, PREG_SPLIT_NO_EMPTY);
        $parts = is_array($parts) ? $parts : array();
        $words = array();

        foreach ($parts as $part) {
            $part = trim($part);

            if ('' === $part) {
                continue;
            }

            $words[] = function_exists('mb_substr')
                ? mb_substr($part, 0, 32, 'UTF-8')
                : substr($part, 0, 32);
        }

        return array_slice($words, 0, 5);
    }

    /**
     * 搜索用的查询对象（调用方再自己 order/limit）
     *
     * @param array $words searchWords() 的结果
     * @return Typecho_Db_Query
     */
    protected static function searchSelect(array $words)
    {
        $db     = self::db();
        $select = $db->select()->from(self::T_POST)->where('status = ?', 'publish');

        foreach ($words as $word) {
            // 只搜正文。评论存在 text 字段的 JSON 里，LIKE 进去会误伤且没法排序
            $select->where('content LIKE ?', '%' . self::escapeLike($word) . '%');
        }

        return $select;
    }

    /**
     * 站内搜索：命中的轻言条数
     *
     * 【为什么需要它】Typecho 的站内搜索（`/?s=关键词`）只查 `table.contents`
     * —— 文章。轻言内容在自己的表里，所以搜索结果页默认一条轻言也看不到；
     * 用户点 `#标签#`（会跳到 `/?s=标签`）时会觉得「我明明写过」。
     *
     * 这个方法和 search() 有两个用处：
     *   1. 轻言自己的搜索页 `/qingyan?q=关键词`（Renderer::fetchPage() 在用）；
     *   2. 供「搜索增强」插件（SearchPlus）调用 —— 它在站内搜索结果页里
     *      顺带列一组轻言命中（**松耦合**：SearchPlus 不依赖轻言，轻言也不依赖它）。
     *
     * @param string $keyword
     * @return int
     */
    public static function searchCount($keyword)
    {
        $words = self::searchWords($keyword);

        if (empty($words)) {
            return 0;
        }

        $select = self::searchSelect($words);
        $select->select(array('COUNT(1)' => 'num'));

        $row = self::db()->fetchRow($select);

        return $row ? intval($row['num']) : 0;
    }

    /**
     * 站内搜索：命中的轻言列表
     *
     * @param string $keyword
     * @param int    $limit
     * @param int    $offset
     * @return array
     */
    public static function search($keyword, $limit = 10, $offset = 0)
    {
        $words = self::searchWords($keyword);

        if (empty($words)) {
            return array();
        }

        $limit  = max(1, min(50, intval($limit)));
        $offset = max(0, intval($offset));
        $select = self::searchSelect($words);

        $select->order('created', QY_SORT_DESC)->offset($offset)->limit($limit);

        $list = array();

        foreach (self::db()->fetchAll($select) as $row) {
            $list[] = self::normalize($row);
        }

        return $list;
    }

    /**
     * 搜索结果的摘要：尽量从关键词附近截，并把关键词标出来
     *
     * 【为什么不直接 excerpt()】那个永远从开头截。搜索结果里关键词可能在正文
     * 很靠后的位置，从头截会看不到它，用户不知道「为什么这条算命中」。
     *
     * 返回的是**已经转义好的 HTML**（`<mark>` 是高亮），直接 echo 即可。
     *
     * @param string $text    原文（Markdown 源码）
     * @param string $keyword 搜索词
     * @param int    $length  摘要长度（字符数）
     * @return string
     */
    public static function snippet($text, $keyword, $length = 90)
    {
        // 摘要只求可读：去掉 HTML、把连续空白压成一个空格
        $plain = preg_replace('/\s+/u', ' ', trim(strip_tags((string) $text)));
        $plain = is_string($plain) ? $plain : '';

        if ('' === $plain) {
            return '';
        }

        $length = max(20, min(300, intval($length)));
        $words  = self::searchWords($keyword);
        $start  = 0;

        // 从第一个命中词往前留 12 个字，让关键词落在摘要里而不是正好卡在开头
        foreach ($words as $word) {
            $pos = self::mbStripos($plain, $word);

            if (false !== $pos && $pos > 0) {
                $start = max(0, $pos - 12);
                break;
            }
        }

        $cut = function_exists('mb_substr')
            ? mb_substr($plain, $start, $length, 'UTF-8')
            : substr($plain, $start, $length);

        $html = htmlspecialchars((string) $cut, ENT_QUOTES, 'UTF-8');

        if (empty($words)) {
            return $html;
        }

        /*
         * 高亮**必须一趟替换**：逐个词 str_replace 的话，先插进去的
         * `<mark>` 里的字母可能被后面的词再次命中（搜索词是 "mark" 就会炸）。
         */
        $patterns = array();

        foreach ($words as $word) {
            $patterns[] = preg_quote(htmlspecialchars($word, ENT_QUOTES, 'UTF-8'), '/');
        }

        $marked = preg_replace('/(' . implode('|', $patterns) . ')/iu', '<mark>$1</mark>', $html);

        return is_string($marked) ? $marked : $html;
    }

    /**
     * 大小写不敏感的多字节 strpos（mb_stripos 缺失时的兜底）
     *
     * @param string $haystack
     * @param string $needle
     * @return int|false 字节位置
     */
    protected static function mbStripos($haystack, $needle)
    {
        $needle = (string) $needle;

        if ('' === $needle) {
            return false;
        }

        if (function_exists('mb_stripos')) {
            return mb_stripos((string) $haystack, $needle, 0, 'UTF-8');
        }

        return stripos((string) $haystack, $needle);
    }

    public static function excerpt($text, $length = 30)
    {
        $text = trim(strip_tags((string) $text));

        if (function_exists('mb_substr')) {
            $cut = mb_substr($text, 0, $length, 'UTF-8');

            return mb_strlen($text, 'UTF-8') > $length ? $cut . '…' : $cut;
        }

        return strlen($text) > $length ? substr($text, 0, $length) . '…' : $text;
    }

    /* ------------------------------------------------------------------ */
    /* 通用工具                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * 当前站点时间戳（已按站点时区偏移）
     *
     * @return int
     */
    public static function time()
    {
        $options = Helper::options();

        return intval($options->time);
    }

    /**
     * 过滤入库字段，只保留白名单列
     *
     * @param array $data
     * @return array
     */
    private static function filterFields($data)
    {
        static $allow = array(
            'authorId', 'author', 'authorMail', 'content',
            'status', 'mood', 'likes', 'commentsNum',
            'source', 'agent', 'created', 'modified',
        );

        $result = array();

        foreach ($allow as $key) {
            if (array_key_exists($key, $data)) {
                $result[$key] = $data[$key];
            }
        }

        if (isset($result['content'])) {
            $result['content'] = trim((string) $result['content']);
        }

        if (isset($result['status']) && !in_array($result['status'], array('publish', 'pending', 'draft'))) {
            $result['status'] = 'publish';
        }

        if (isset($result['mood'])) {
            $result['mood'] = self::normalizeMood($result['mood']);
        }

        return $result;
    }

    /**
     * 过滤评论入库字段
     *
     * @param array $data
     * @return array
     */
    private static function filterCommentFields($data)
    {
        $allow  = array('parent', 'author', 'mail', 'url', 'ip', 'agent', 'status', 'text');
        $result = array();

        foreach ($allow as $key) {
            if (array_key_exists($key, $data)) {
                $result[$key] = $data[$key];
            }
        }

        if (isset($result['text'])) {
            $result['text'] = trim((string) $result['text']);
        }

        if (isset($result['status']) && !in_array($result['status'], array('approved', 'pending', 'spam'))) {
            $result['status'] = 'approved';
        }

        return $result;
    }

    /**
     * 格式化一条记录：图片解码 + 计数兜底
     *
     * @param array $row
     * @return array
     */
    public static function normalize($row)
    {
        $row['id']          = intval($row['id']);
        $row['authorId']    = intval($row['authorId']);
        $row['likes']       = intval($row['likes']);
        $row['commentsNum'] = intval($row['commentsNum']);
        $row['created']     = intval($row['created']);
        $row['modified']    = intval($row['modified']);

        return $row;
    }

    /**
     * 表情短代码映射表
     *
     * 支持 :smile: 这类写法，渲染时替换为对应 emoji。
     * 前端表情面板插入的是 emoji 字符本身，此表用于手动输入的补充。
     *
     * @return array
     */
    public static function emojiMap()
    {
        static $map = array(
            'smile' => "\u{1F604}", 'joy' => "\u{1F602}", 'laugh' => "\u{1F606}",
            'wink' => "\u{1F609}", 'blush' => "\u{1F60A}", 'grin' => "\u{1F601}",
            'heart_eyes' => "\u{1F60D}", 'kiss' => "\u{1F618}", 'thinking' => "\u{1F914}",
            'sunglasses' => "\u{1F60E}", 'nerd' => "\u{1F913}", 'worried' => "\u{1F61F}",
            'cry' => "\u{1F622}", 'sob' => "\u{1F62D}", 'angry' => "\u{1F620}",
            'rage' => "\u{1F621}", 'sleepy' => "\u{1F634}", 'scream' => "\u{1F631}",
            'thumbsup' => "\u{1F44D}", 'thumbsdown' => "\u{1F44E}", 'ok' => "\u{1F44C}",
            'pray' => "\u{1F64F}", 'clap' => "\u{1F44F}", 'wave' => "\u{1F44B}",
            'point_down' => "\u{1F447}", 'muscle' => "\u{1F4AA}", 'tada' => "\u{1F389}",
            'party' => "\u{1F973}", 'heart' => "\u{2764}\u{FE0F}", 'broken_heart' => "\u{1F494}",
            'sparkles' => "\u{2728}", 'fire' => "\u{1F525}", '100' => "\u{1F4AF}",
            'star' => "\u{2B50}", 'rocket' => "\u{1F680}", 'bulb' => "\u{1F4A1}",
            'coffee' => "\u{2615}", 'cake' => "\u{1F382}", 'gift' => "\u{1F381}",
            'sun' => "\u{2600}\u{FE0F}", 'moon' => "\u{1F319}", 'rain' => "\u{1F327}\u{FE0F}",
            'snow' => "\u{2744}\u{FE0F}", 'cat' => "\u{1F431}", 'dog' => "\u{1F436}",
            'flower' => "\u{1F338}", 'leaf' => "\u{1F343}", 'music' => "\u{1F3B5}",
        );

        return $map;
    }

    /**
     * 把 :smile: 这样的短代码替换成 emoji
     *
     * @param string $text
     * @return string
     */
    public static function emoji($text)
    {
        $text = (string) $text;

        if (false === strpos($text, ':')) {
            return $text;
        }

        $map = self::emojiMap();

        return preg_replace_callback(
            '/:([a-z0-9_+-]{1,20}):/i',
            function ($matches) use ($map) {
                $key = strtolower($matches[1]);

                return isset($map[$key]) ? $map[$key] : $matches[0];
            },
            $text
        );
    }

    /**
     * 前端表情面板用的表情列表
     *
     * @return array 短代码 => emoji
     */
    public static function emojiPanel()
    {
        $map    = self::emojiMap();
        $picked = array(
            'smile', 'joy', 'laugh', 'wink', 'blush', 'grin',
            'heart_eyes', 'kiss', 'thinking', 'sunglasses', 'nerd',
            'cry', 'sob', 'angry', 'rage', 'sleepy',
            'thumbsup', 'thumbsdown', 'ok', 'pray', 'clap', 'muscle',
            'tada', 'party', 'heart', 'broken_heart', 'sparkles',
            'fire', '100', 'star', 'rocket', 'bulb',
            'coffee', 'cake', 'gift', 'sun', 'moon', 'cat', 'dog',
            'flower', 'leaf', 'music',
        );

        $result = array();

        foreach ($picked as $key) {
            if (isset($map[$key])) {
                $result[':' . $key . ':'] = $map[$key];
            }
        }

        return $result;
    }

    /**
     * 客户端 IP
     *
     * 可信代理行为是显式的：
     *   - 站点定义了 `__TYPECHO_IP_SOURCE__`（如 CDN 场景填
     *     `CF-Connecting-IP` / `X-Real-IP`）时，交由核心
     *     `Typecho_Request::getIp()` 按该 header 解析；
     *   - 未定义时只信任 `REMOTE_ADDR`，不再无条件信任
     *     `X-Forwarded-For`（否则可伪造 header 绕过评论限频/点赞去重）。
     *
     * @return string
     */
    public static function ip()
    {
        if (defined('__TYPECHO_IP_SOURCE__')) {
            try {
                $ip = Typecho_Request::getInstance()->getIp();

                if ('' !== $ip && 'unknown' !== $ip
                    && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6)) {
                    return substr($ip, 0, 64);
                }
            } catch (Throwable $e) {
                // 退回 REMOTE_ADDR
            }
        }

        $ip = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';

        if ('' !== $ip && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6)) {
            return substr($ip, 0, 64);
        }

        return '0.0.0.0';
    }

    /**
     * 访客标识：IP + UA 的哈希，用于点赞去重（不存储明文 IP）
     *
     * @return string
     */
    public static function visitorHash()
    {
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 200) : '';

        return md5(self::ip() . '|' . $ua);
    }

    /**
     * 心情档位
     *
     * 卡片左侧那个位置可以放两类东西：
     *   avatar —— 显示发布者头像（默认档）
     *   其余   —— 显示 assets/emoji/ 下的预置心情图
     *
     * 入库只存 key；label 给界面用，file 指向 assets/emoji/ 下的图
     * （file 为空表示这一档不用图，走头像）。想换图只改 file 这一处。
     *
     * @return array key => array('label' => string, 'file' => string)
     */
    public static function moods()
    {
        return array(
            'avatar'   => array('label' => '头像', 'file' => ''),
            'normal'   => array('label' => '平常', 'file' => 'normal.svg'),
            'happy'    => array('label' => '开心', 'file' => 'happy.svg'),
            'sad'      => array('label' => '伤心', 'file' => 'sad.svg'),
            'angry'    => array('label' => '愤怒', 'file' => 'angry.svg'),
            'surprise' => array('label' => '惊讶', 'file' => 'surprise.svg'),
        );
    }

    /**
     * 某一档心情图的地址（为空表示走头像）
     *
     * 文件名现在是 ASCII，rawurlencode 留着不影响，
     * 以后换成中文名或带空格的名字也不会出问题。
     *
     * @param string $file
     * @return string
     */
    public static function moodImageUrl($file)
    {
        $file = trim((string) $file);

        if ('' === $file) {
            return '';
        }

        return Qingyan_Plugin::assetUrl('emoji/' . rawurlencode($file));
    }

    /**
     * 把任意值收敛成合法的心情 key
     *
     * @param mixed $mood
     * @return string
     */
    public static function normalizeMood($mood)
    {
        $mood = strtolower(trim((string) $mood));
        $all  = self::moods();

        if (isset($all[$mood])) {
            return $mood;
        }

        // 兼容历史数据：mood 列刚加时默认值写的是 'default'，
        // 现在默认档改名成 'avatar'，老值一律落回默认档
        return self::MOOD_DEFAULT;
    }

    /**
     * 取一条记录的心情元信息
     *
     * @param array|string $row 记录数组，或直接给 key
     * @return array key / label / file / avatar / img
     */
    public static function moodOf($row)
    {
        $raw  = is_array($row) ? (isset($row['mood']) ? $row['mood'] : '') : $row;
        $key  = self::normalizeMood($raw);
        $all  = self::moods();
        $meta = $all[$key];

        return array(
            'key'    => $key,
            'label'  => $meta['label'],
            'file'   => $meta['file'],
            'avatar' => (self::MOOD_DEFAULT === $key),
            'img'    => self::moodImageUrl($meta['file']),
        );
    }

    /**
     * 头像地址
     *
     * @param string $mail
     * @param int    $size
     * @return string
     */
    public static function avatar($mail, $size = 80)
    {
        $base = Qingyan_Plugin::opt('gravatar', 'https://cravatar.cn/avatar');
        $base = rtrim(trim((string) $base), '/');

        if ('' === $base) {
            $base = 'https://cravatar.cn/avatar';
        }

        $hash = md5(strtolower(trim((string) $mail)));

        return $base . '/' . $hash . '?s=' . intval($size) . '&d=mp';
    }

    /**
     * 相对 / 绝对时间文案
     *
     * @param int $timestamp
     * @return string
     */
    public static function timeAgo($timestamp)
    {
        $diff = self::time() - intval($timestamp);

        if ($diff < 0) {
            $diff = 0;
        }

        if ($diff < 60) {
            return '刚刚';
        }

        if ($diff < 3600) {
            return floor($diff / 60) . ' 分钟前';
        }

        if ($diff < 86400) {
            return floor($diff / 3600) . ' 小时前';
        }

        if ($diff < 172800) {
            return '昨天 ' . date('H:i', intval($timestamp));
        }

        if (date('Y', intval($timestamp)) === date('Y', self::time())) {
            return date('m-d H:i', intval($timestamp));
        }

        return date('Y-m-d H:i', intval($timestamp));
    }

    /**
     * 交给 Typecho 自带的解析组件（与文章、评论同一套引擎）
     *
     * 【为什么用它】以前这里是手写的正则：只能做行内标记，列表、表格、代码块
     * 这类**块级**语法根本没法支持（块级解析需要先按行分组，正则做不干净）。
     * Typecho 1.3 的核心用 `Utils\HyperDown` 解析文章与评论，本插件直接复用它，
     * 于是段落、有序/无序/嵌套列表、表格、引用、代码块、标题、水平线、
     * 加粗/斜体/删除线/行内代码/链接/裸链接全部一次到位，而且与站点其它位置
     * 的 Markdown 观感一致。
     *
     * 【为什么不用 Utils\Markdown::convert()】那个封装是
     * `enableHtml(true)`（文章与评论都允许裸 HTML）。轻言的内容里
     * **访客评论是不可信输入**，所以这里自己 new 一个 HyperDown 并
     * `enableHtml(false)`：裸 HTML 会被转义成文本，再叠加后面的白名单过滤。
     *
     * 【降级】找不到 HyperDown（Typecho 1.2 等）时依次退到 `Utils\Markdown`、
     * `Typecho_Common::markdown`，最后退化成「转义 + 换行」。无论走哪条路，
     * 之后的白名单消毒都会照常执行，所以降级不会带来安全问题。
     *
     * @param string $text
     * @return string
     */
    public static function markdown($text)
    {
        if (class_exists('Utils\\HyperDown')) {
            $parser = new Utils\HyperDown();
            $parser->enableHtml(false);

            return $parser->makeHtml((string) $text);
        }

        if (class_exists('Utils\\Markdown') && method_exists('Utils\\Markdown', 'convert')) {
            return Utils\Markdown::convert((string) $text);
        }

        if (method_exists('Typecho_Common', 'markdown')) {
            return Typecho_Common::markdown((string) $text);
        }

        return nl2br(htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'));
    }

    /**
     * 保住「行首不是标题」的那些 `#`，别让 Markdown 吃掉它们
     *
     * 【HyperDown 的规则比 CommonMark 宽】它的标题判定是 `/^(#+)(.*)$/`
     * —— **`#` 后面不要求空格**，而且 `parseSh()` 会 `trim($line, '# ')`，
     * 于是下面这些写法都会被吃掉井号、整行变成大标题（都实测过）：
     *
     *   `#旅行# 今天天气不错`         → `<h1>旅行# 今天天气不错</h1>`   ← 话题没了
     *   `#今日心情`                   → `<h1>今日心情</h1>`             ← 微博式单井号
     *   `#一个超过二十四个字的话题#`   → `<h1>…</h1>`                    ← 超出话题长度上限
     *
     * CommonMark 里这三种都**不是**标题（ATX 标题要求 `#` 后有空格），
     * 而轻言是「一行一句话」的时间线，行首 `#话题` 太常见了 —— 所以这里把
     * 「不是真标题」的行首井号保护起来，解析完再原样还原成 `#`。
     *
     * 判定：行首（允许最多 3 个空格缩进）的 `#` 连串，**后面紧跟空白或行尾**才算标题，
     * 其余一律保护。`##文字` 这种也要保护，否则只换掉第一个 `#`，剩下的 `#文字`
     * 还是会被当成标题。
     *
     * 【为什么记号只代表那几个 `#`，而不是整个话题】代码块里也可能出现行首
     * `#话题#`：记号还原成 `#` 之后，applyTopics() 会跳过 <code>/<pre>，
     * 于是代码块里显示的仍是字面的 `#话题#`（正确）；若把整段换成 HTML 占位符，
     * 反而会往代码块里注入标签。
     *
     * @param string $text
     * @param string $token 临时记号（只含字母数字，不会和正文冲突）
     * @return string
     */
    protected static function protectLineStartTopics($text, $token)
    {
        if (false === strpos($text, '#')) {
            return $text;
        }

        /*
         * 两处细节都是实测踩出来的：
         *
         * 1. `#++` 用**占有量词**，不许回退。写成 `(#+)` 时 `## 二级标题`
         *    会退成「只吃第一个 #」，于是真标题被误保护成普通段落。
         * 2. 井号前面要允许**列表 / 引用标记**。`- #标签# 文字` 这种列表项，
         *    HyperDown 会把项内容再当块解析一次，行首那个 `#` 照样变 <h1>
         *    （实测：`<ul><li><h1>标签# 文字</h1></li></ul>`）。
         *    所以前缀写成 `(?:[-*+]|1.|>) + 空格` 的重复，支持嵌套。
         */
        return preg_replace(
            '/^([ \t]*(?:(?:[-*+]|\d{1,9}[.)]|>)[ \t]+)*)(#++)(?![ \t]|$)/mu',
            '$1' . $token,
            $text
        );
    }

    /**
     * 允许保留的标签（交给 Typecho_Common::stripTags 做白名单 + 属性过滤）
     *
     * 只列 HyperDown 自己会产出的标签。没列在这里的一律被删掉 ——
     * script / iframe / style / img / form / video / svg 等自然全部出局；
     * 属性也只在名单内的才留（例如 `<th align>`、`<ol start>`、`<code class>`），
     * 事件属性（onclick=…）、style、src 之类不会留下来。
     *
     * 【为什么没有 <a>】链接在过滤**之前**就被摘出来、由本插件自己重建
     * （见 extractLinks：只允许 http/https，并统一补 target/rel），
     * 所以这里不需要放开 href —— 放开反而会给 `javascript:` 留口子。
     *
     * @return string
     */
    public static function allowedTags()
    {
        return '<p><br><ul><ol start><li><blockquote><pre><code class>'
            . '<strong><em><del><hr><h1><h2><h3><h4><h5><h6>'
            . '<table><thead><tbody><tfoot><tr><th align><td align>'
            . '<sup><sub><kbd><small>';
    }

    /**
     * 链接文字里允许的标签（比 allowedTags 更窄：不允许块级、不允许再嵌链接）
     *
     * @return string
     */
    public static function allowedInlineTags()
    {
        return '<strong><em><del><code><sup><sub><kbd><small>';
    }

    /**
     * 内容格式化：Markdown 解析 → 白名单消毒 → 轻言自己的两处扩展
     *
     * 支持（由 HyperDown 提供）：
     *   段落、**加粗**、*斜体*、~~删除线~~、`行内代码`、
     *   - 无序列表 / 1. 有序列表 / 嵌套列表、| 表格 |、
     *   > 引用（可嵌套）、``` 代码块、# 标题、--- 水平线、
     *   [文字](链接) 与裸链接（自动变成链接）
     *
     * 轻言自己的扩展（HyperDown 不管的）：
     *   :smile: 表情短代码 → emoji（在解析前替换）
     *   #话题# → 站内搜索链接 <a class="qy-topic" href="…/?s=话题">#话题#</a>
     *
     * 安全：任何输入都当作不可信（前台评论可匿名提交）。
     * 裸 HTML 在 markdown() 里就被转义，输出再过一遍白名单 stripTags，
     * 链接只允许 http/https。旧实现「任何 HTML 都会被转义」的承诺保持不变。
     *
     * @param string $text
     * @return string
     */
    public static function format($text)
    {
        $text = str_replace(array("\r\n", "\r"), "\n", (string) $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        $text = trim($text);

        if ('' === $text) {
            return '';
        }

        // 表情短代码 :smile: → emoji（在解析之前，emoji 本身不受影响）
        $text = self::emoji($text);

        // ⓪ 行首不是标题的 # 先保护起来（#话题#、#今日心情…），否则会被 Markdown 吃掉
        $token = 'QYHASH' . substr(md5(uniqid('', true)), 0, 8);
        $text  = self::protectLineStartTopics($text, $token);

        // ① 块级 + 行内解析全部交给 Typecho 的解析组件
        $html = self::markdown($text);

        // ② 链接先摘出来自己重建：只允许 http/https，并统一补 target / rel
        $store = array();
        $html  = self::extractLinks($html, $store);

        // ③ 白名单消毒：删掉名单外的标签与属性（剥离任何脚本 / 事件属性）
        $html = Typecho_Common::stripTags($html, self::allowedTags());

        // ④ 还原自己生成的安全链接（占位符不受 stripTags 影响）
        $html = str_replace(array_keys($store), array_values($store), $html);

        // ⑤ 还原文首话题的那个 `#`，再统一处理话题
        $html = str_replace($token, '#', $html);
        $html = self::applyTopics($html);

        // ⑥ 引用块补上类名（CSS 与旧内容都依赖 .qy-quote）
        $html = str_replace('<blockquote>', '<blockquote class="qy-quote">', $html);

        /*
         * ⑦ 清掉空段落。
         * 典型来源：只写了一张图片（`![图](url)`）—— 图片不在白名单里会被删掉，
         * 于是留下一个空的 <p>，在卡片上表现为莫名的空行。
         */
        return preg_replace('#<p>(?:\s|&nbsp;|<br\s*/?>)*</p>#i', '', $html);
    }

    /**
     * 把所有 <a> 换成占位符，重建为「只允许 http/https」的安全链接
     *
     * 【为什么要在 stripTags 之前做】stripTags 会把 href 一起过滤掉
     * （我们不放开 href，否则 `javascript:` 就有了口子），所以必须先把
     * 解析结果里的链接连 URL 一起取出来，自己校验、自己生成，
     * 等白名单过滤跑完再还原。
     *
     * 链接文字里可能带行内标签（`[**粗**](url)`），所以文字单独过一遍
     * **行内**白名单，而不是直接 htmlspecialchars —— 否则加粗会变成字面量。
     *
     * @param string $html
     * @param array  $store 占位符仓库（引用传参）
     * @return string
     */
    protected static function extractLinks($html, array &$store)
    {
        if (false === stripos($html, '<a')) {
            return $html;
        }

        return preg_replace_callback(
            '#<a\b([^>]*)>(.*?)</a>#is',
            function ($matches) use (&$store) {
                $href = '';

                if (preg_match('/\bhref\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i', $matches[1], $m)) {
                    foreach (array(2, 3, 4) as $i) {
                        if (isset($m[$i]) && '' !== $m[$i]) {
                            $href = $m[$i];
                            break;
                        }
                    }
                }

                $href  = htmlspecialchars_decode(trim($href), ENT_QUOTES);
                $label = Typecho_Common::stripTags($matches[2], self::allowedInlineTags());
                $plain = trim(htmlspecialchars_decode(strip_tags($label), ENT_QUOTES));

                // 文字为空（例如 ![img]() 之类）时用 URL 兜底，避免出现空链接
                if ('' === $plain) {
                    $plain = $href;
                    $label = htmlspecialchars($href, ENT_QUOTES, 'UTF-8');
                }

                return self::stash($store, self::anchor($href, $label));
            },
            $html
        );
    }

    /**
     * 生成一个安全的外链（$label 必须是**已经消毒过的 HTML**）
     *
     * 【和 safeLink() 的区别】safeLink() 把文字也当纯文本转义（适合「URL
     * 直接当文字」的场景）；这里保留文字里的行内标签，所以调用方必须
     * 先过 allowedInlineTags() 白名单。
     *
     * 只允许 http / https（以及 // 开头的协议相对地址，补成 https），
     * 其余（javascript:、data:、vbscript: …）一律退化成纯文字。
     *
     * @param string $url
     * @param string $labelHtml
     * @return string
     */
    public static function anchor($url, $labelHtml)
    {
        $url = trim((string) $url);

        if (0 === strpos($url, '//')) {
            $url = 'https:' . $url;
        }

        if (!preg_match('/^https?:\/\/[^\s]+$/i', $url)) {
            return $labelHtml;
        }

        return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" target="_blank"'
            . ' rel="noopener external nofollow">' . $labelHtml . '</a>';
    }

    /**
     * 把 #话题# 换成 <span class="qy-topic">
     *
     * 【为什么在解析之后做】Markdown 里行首的 `#` 是标题：
     * `#话题# 今天…` 会被 HyperDown 解析成 <h1>。所以话题**不能**在解析前
     * 替换成占位符（那样会连代码块里的 `#话题#` 一起换掉，而且行首语义也乱），
     * 而是在拿到 HTML 之后逐段处理。
     *
     * 规则（比旧实现更严格，避免和 Markdown 标题打架）：
     *   - `#` 后面**紧跟非空白**才算话题：`#旅行#` ✓，`# 标题` ✗（那是标题）
     *   - 话题最长 24 字
     *   - 只在**标签之外的文本**里替换，且跳过 <code>/<pre> 内部
     *     （`` `#话题#` `` 与代码块里的话题保持字面量）
     *
     * @param string $html
     * @return string
     */
    public static function applyTopics($html)
    {
        if (false === strpos($html, '#')) {
            return $html;
        }

        // 按标签切开：奇数下标是标签，偶数下标是纯文本
        $parts  = preg_split('/(<[^>]*>)/s', (string) $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        $inCode = 0;
        $inLink = 0;

        foreach ($parts as $i => $part) {
            if (1 === $i % 2) {
                if (preg_match('#^<(/?)(code|pre)\b#i', $part, $m)) {
                    $inCode += ('/' === $m[1]) ? -1 : 1;

                    if ($inCode < 0) {
                        $inCode = 0;
                    }
                } elseif (preg_match('#^<(/?)(a)\b#i', $part, $m)) {
                    // 链接内部不再套链接（<a> 里嵌 <a> 是非法 HTML，浏览器会把外层拆开）
                    $inLink += ('/' === $m[1]) ? -1 : 1;

                    if ($inLink < 0) {
                        $inLink = 0;
                    }
                }

                continue;
            }

            if ($inCode > 0 || $inLink > 0 || '' === $part) {
                continue;
            }

            $parts[$i] = preg_replace_callback(
                '/#([^#\s][^#\n]{0,23})#/u',
                function ($m) {
                    /*
                     * 解析器给出的这段文字**已经过 HTML 转义**（`&` → `&amp;`）。
                     * 拼 URL 要用原始文字，显示仍用转义后的，两边都不能省。
                     */
                    $plain = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');

                    return '<a class="qy-topic" href="'
                        . htmlspecialchars(Qingyan_Model::siteSearchUrl($plain), ENT_QUOTES, 'UTF-8') . '">#'
                        . htmlspecialchars($plain, ENT_QUOTES, 'UTF-8') . '#</a>';
                },
                $part
            );
        }

        return implode('', $parts);
    }

    /**
     * 站内搜索地址（`网站/?s=关键词`）
     *
     * 用 `siteUrl + /?s=关键词` —— 与主题 header.php 里那个搜索表单**完全一致**
     * （`<form method="get" action="{$this->options->siteUrl}"><input name="s">`），
     * 所以行为跟用户在搜索框里手输一模一样，也不挑 Typecho 的固定链接设置
     * （开了 rewrite 是 `/?s=x`，没开也能落到 index.php 上）。
     *
     * 站点地址取不到时（理论上不会，兜底而已）退化成相对地址 `/?s=x`，
     * 至少在同域下仍然可用。
     *
     * @param string $word 话题词（未转义的原始文字）
     * @return string
     */
    public static function siteSearchUrl($word)
    {
        $site = '';

        try {
            $site = rtrim((string) Helper::options()->siteUrl, '/');
        } catch (Throwable $e) {
            $site = '';
        }

        return $site . '/?s=' . urlencode(trim((string) $word));
    }

    /**
     * topicUrl() 的别名 —— 话题链接用的就是站内搜索地址
     *
     * @param string $word
     * @return string
     */
    public static function topicUrl($word)
    {
        return self::siteSearchUrl($word);
    }

    /**
     * 生成一个安全的外链（仅允许 http / https）
     *
     * 传入的 $url 与 $label 均为「未转义的原文」，转义由本方法统一处理，
     * 避免二次转义把 & 变成 &amp;amp;。
     *
     * 【与 anchor() 的分工】format() 里的链接现在走 anchor()：解析器给出的
     * 链接文字可能带行内标签（`[**粗**](url)`），需要保留。本方法仍保留给
     * 「文字是纯文本、要一并转义」的调用方（例如主题自己拼链接）。
     *
     * @param string $url
     * @param string $label
     * @return string
     */
    public static function safeLink($url, $label)
    {
        $url   = trim((string) $url);
        $label = trim((string) $label);

        if (0 === strpos($url, '//')) {
            $url = 'https:' . $url;
        }

        if (!preg_match('/^https?:\/\/[^\s]+$/i', $url)) {
            return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        }

        if (strlen($label) > 48) {
            $label = substr($label, 0, 45) . '...';
        }

        return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" target="_blank"'
            . ' rel="noopener external nofollow">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
    }

    /**
     * 暂存已生成的 HTML，避免被后续规则二次处理
     *
     * @param array  $store
     * @param string $html
     * @return string 占位符
     */
    public static function stash(&$store, $html)
    {
        $key = "\x01" . count($store) . "\x01";
        $store[$key] = $html;

        return $key;
    }

}
