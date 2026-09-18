/*!
 * 轻言 Qingyan - 前台交互
 *
 * 原生 JS，无第三方依赖。
 *
 * 交互原则：
 *   1. 渐进增强 —— 分页渲染成真实的 <a href>，JS 失效时仍能跳转
 *   2. 事件委托 —— 列表 ajax 替换后无需重新绑定
 *   3. 失败可降级 —— ajax 翻页出错时回退到浏览器跳转
 */
(function () {
    'use strict';

    var cfg = window.Qingyan || {};

    if (!cfg.api) {
        return;
    }

    // /qingyan/api/json -> /qingyan/api
    var API = String(cfg.api).replace(/\/json\/?$/, '').replace(/\/$/, '');

    var MSG_OK = 'is-ok';
    var MSG_ERR = 'is-error';

    function $(selector, root) {
        return (root || document).querySelector(selector);
    }

    function $$(selector, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(selector));
    }

    /* ================================================================ */
    /* 请求                                                              */
    /* ================================================================ */

    /**
     * 发起一次接口请求
     */
    function request(action, data, callback) {
        var xhr = new XMLHttpRequest();
        var body = [];

        data = data || {};
        data.token = cfg.token;

        for (var key in data) {
            if (Object.prototype.hasOwnProperty.call(data, key)) {
                body.push(encodeURIComponent(key) + '=' + encodeURIComponent(data[key]));
            }
        }

        xhr.open('POST', API + '/' + action, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
        xhr.setRequestHeader('X-Qingyan-Token', cfg.token);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        xhr.onreadystatechange = function () {
            if (4 !== xhr.readyState) {
                return;
            }

            var result;

            try {
                result = JSON.parse(xhr.responseText);
            } catch (e) {
                result = { code: 500, msg: '服务器返回异常' };
            }

            callback(result || {});
        };

        xhr.send(body.join('&'));
    }

    function setMsg(el, text, type) {
        if (!el) {
            return;
        }

        el.textContent = text || '';
        el.className = 'qy-msg' + (type ? ' ' + type : '');
    }

    /* ================================================================ */
    /* 翻页：ajax + pushState                                            */
    /* ================================================================ */

    var timeline = null;
    var pagerBox = null;
    var loading = false;

    function getTimeline() {
        if (!timeline || !timeline.parentNode) {
            timeline = document.getElementById('qy-timeline');
        }

        return timeline;
    }

    /**
     * 分页容器
     *
     * 列表被 ajax 替换后分页节点也换了，所以每次都重新查，
     * 缓存会在替换后失效。
     */
    function getPager() {
        if (!pagerBox || !pagerBox.parentNode) {
            pagerBox = document.getElementById('qy-pager');
        }

        return pagerBox;
    }

    /**
     * 加载指定页，成功后替换列表与分页
     *
     * @param {number} page
     * @param {string} url   用于 pushState 的地址
     */
    function loadPage(page, url) {
        if (loading) {
            return;
        }

        var list = getTimeline();

        if (!list) {
            // 找不到容器（比如自定义结构），降级为跳转
            window.location.href = url;
            return;
        }

        loading = true;
        list.classList.add('qy-loading');

        request('fragment', {
            page: page,
            base: cfg.base || '',
            // 搜索模式下必须带上关键词，否则 ajax 翻页会翻出「全部内容」
            keyword: cfg.keyword || ''
        }, function (res) {
            loading = false;
            list.classList.remove('qy-loading');

            if (0 !== res.code || !res.data) {
                // 出错就退回浏览器跳转，保证用户总能翻页
                window.location.href = url;
                return;
            }

            var data = res.data;

            list.innerHTML = data.html || '';

            // 替换分页
            replacePager(data.pager || '');

            // 地址栏同步，支持前进/后退与刷新
            if (url && window.history && window.history.pushState) {
                window.history.pushState({ qyPage: page }, document.title, url);
            }

            // 回到列表顶部，避免停在页面中间
            scrollToList();
        });
    }

    /**
     * 用新的分页 HTML 替换旧的
     *
     * @param {string} html 为空表示移除分页
     */
    function replacePager(html) {
        var old = getPager();

        if (old && old.parentNode) {
            if (html) {
                old.outerHTML = html;
            } else {
                old.parentNode.removeChild(old);
            }

            pagerBox = null;

            return;
        }

        // 原来没有分页（比如只有一页），翻页后可能出现
        if (html) {
            var list = getTimeline();

            if (list) {
                list.insertAdjacentHTML('afterend', html);
                pagerBox = null;
            }
        }
    }

    function scrollToList() {
        var list = getTimeline();

        if (!list) {
            return;
        }

        var top = list.getBoundingClientRect().top + (window.pageYOffset || 0);
        var offset = 80;

        if (top < 0 || top > window.innerHeight) {
            window.scrollTo(0, Math.max(0, top - offset));
        }
    }

    /**
     * 浏览器前进 / 后退
     */
    if (window.history && window.history.pushState) {
        window.addEventListener('popstate', function () {
            var page = readPageFromUrl();

            // popstate 时不再 push，直接加载
            loadPageSilent(page);
        });
    }

    function readPageFromUrl() {
        var match = window.location.search.match(/[?&]page=(\d+)/);

        if (match) {
            return parseInt(match[1], 10) || 1;
        }

        // 独立前台的路径形式：/qingyan/page/2
        var pathMatch = window.location.pathname.match(/\/page\/(\d+)\/?$/);

        return pathMatch ? (parseInt(pathMatch[1], 10) || 1) : 1;
    }

    function loadPageSilent(page) {
        if (loading) {
            return;
        }

        var list = getTimeline();

        if (!list) {
            return;
        }

        loading = true;
        list.classList.add('qy-loading');

        request('fragment', { page: page, base: cfg.base || '' }, function (res) {
            loading = false;
            list.classList.remove('qy-loading');

            if (0 !== res.code || !res.data) {
                return;
            }

            list.innerHTML = res.data.html || '';

            replacePager(res.data.pager || '');
        });
    }

    /* ================================================================ */
    /* 事件代理                                                          */
    /* ================================================================ */

    document.addEventListener('click', function (event) {
        var target = event.target.closest ? event.target.closest('[data-act]') : null;

        // ── 分页链接 ──
        var pageLink = event.target.closest ? event.target.closest('.qy-page[data-page]') : null;

        if (pageLink && !pageLink.classList.contains('is-current')
            && !pageLink.classList.contains('is-disabled')) {
            event.preventDefault();

            var page = parseInt(pageLink.getAttribute('data-page'), 10);

            if (page > 0) {
                loadPage(page, pageLink.getAttribute('href'));
            }

            return;
        }

        // ── 自定义结构（Qingyan_Plugin::query）专用钩子 ──
        // data-qy-like="{id}"      点赞，可含 [data-qy-icon] / [data-qy-count]
        // data-qy-comments="{id}"  展开 / 收起评论区
        if (!target && event.target.closest) {
            var likeBtn = event.target.closest('[data-qy-like]');

            if (likeBtn) {
                onLike(likeBtn, parseInt(likeBtn.getAttribute('data-qy-like'), 10));
                return;
            }

            var cmtBtn = event.target.closest('[data-qy-comments]');

            if (cmtBtn) {
                onToggleComments(parseInt(cmtBtn.getAttribute('data-qy-comments'), 10));
                return;
            }
        }

        if (!target) {
            return;
        }

        var act = target.getAttribute('data-act');
        var id = parseInt(target.getAttribute('data-id'), 10);

        if ('like' === act) {
            onLike(target, id);
        } else if ('toggle-comments' === act) {
            onToggleComments(id);
        } else if ('delete' === act) {
            onDelete(target, id);
        } else if ('del-comment' === act) {
            onDeleteComment(target, id);
        }
    });

    function onLike(btn, id) {
        if (btn.disabled) {
            return;
        }

        btn.disabled = true;

        request('like', { id: id }, function (res) {
            btn.disabled = false;

            if (0 !== res.code) {
                return;
            }

            var data = res.data || {};
            var num = btn.querySelector('.qy-like-num') || btn.querySelector('[data-qy-count]');
            var icon = btn.querySelector('.qy-icon') || btn.querySelector('[data-qy-icon]');

            if (num) {
                num.textContent = String(data.likes || 0);
            }

            if (icon) {
                icon.innerHTML = data.liked ? '&#9829;' : '&#9825;';
            }

            // 自定义结构里可能只有一个纯文本按钮，没有内部 span
            if (!num && !icon) {
                btn.innerHTML = (data.liked ? '&#9829;' : '&#9825;') + ' ' + String(data.likes || 0);
            }

            if (data.liked) {
                btn.classList.add('is-liked');
            } else {
                btn.classList.remove('is-liked');
            }
        });
    }

    function onToggleComments(id) {
        // 优先内置结构：#qy-{id} .qy-comments
        var item = document.getElementById('qy-' + id);
        var box = item ? $('.qy-comments', item) : null;

        // 自定义结构：commentsHtml 自带的 #qy-comments-{id}
        if (!box) {
            box = document.getElementById('qy-comments-' + id);
        }

        if (!box) {
            return;
        }

        box.hidden = !box.hidden;
    }

    function onDelete(btn, id) {
        // 二次确认：删除不可撤销，必须让用户明确点一次
        if (!window.confirm('确定删除这条内容？\n\n删除后无法恢复。')) {
            return;
        }

        request('delete', { id: id, type: 'post' }, function (res) {
            if (0 !== res.code) {
                window.alert(res.msg || '删除失败');
                return;
            }

            var item = document.getElementById('qy-' + id);

            if (item && item.parentNode) {
                item.parentNode.removeChild(item);
            }
        });
    }

    function onDeleteComment(btn, id) {
        // 二次确认：同上
        if (!window.confirm('确定删除这条评论？\n\n删除后无法恢复。')) {
            return;
        }

        request('delete', { id: id, type: 'comment' }, function (res) {
            if (0 !== res.code) {
                window.alert(res.msg || '删除失败');
                return;
            }

            var li = btn.closest ? btn.closest('.qy-comment') : null;

            if (li && li.parentNode) {
                li.parentNode.removeChild(li);
            }
        });
    }

    /* ================================================================ */
    /* 评论表单                                                          */
    /* ================================================================ */

    document.addEventListener('submit', function (event) {
        var form = event.target;

        if (!form.classList || !form.classList.contains('qy-comment-form')) {
            return;
        }

        event.preventDefault();

        var id = parseInt(form.getAttribute('data-id'), 10);
        var text = $('textarea[name="text"]', form);
        var button = $('button[type="submit"]', form);
        var msg = $('.qy-msg', form);
        var content = text ? text.value.trim() : '';

        if (!content) {
            setMsg(msg, '说点什么吧', MSG_ERR);
            return;
        }

        if (button) {
            button.disabled = true;
        }

        setMsg(msg, '发送中…', '');

        request('comment', {
            id: id,
            text: content,
            author: form.author ? form.author.value : '',
            mail: form.mail ? form.mail.value : ''
        }, function (res) {
            if (button) {
                button.disabled = false;
            }

            if (0 !== res.code) {
                setMsg(msg, res.msg || '发送失败', MSG_ERR);
                return;
            }

            setMsg(msg, res.msg || '已发送', MSG_OK);

            if (text) {
                text.value = '';
            }

            var data = res.data || {};
            var item = document.getElementById('qy-' + id);

            if (item && data.html && 'approved' === data.status) {
                var list = $('.qy-comment-list', item);

                if (list) {
                    var empty = $('.qy-comment-empty', list);

                    if (empty && empty.parentNode) {
                        empty.parentNode.removeChild(empty);
                    }

                    list.insertAdjacentHTML('beforeend', data.html);
                }

                var counter = $('.qy-comment-num', item);

                if (counter) {
                    counter.textContent = String(data.count || 0);
                }
            }
        });
    });

    /* ================================================================ */
    /* 发布框（无刷新插入）                                              */
    /* ================================================================ */

    var editor = document.getElementById('qy-editor');

    if (editor) {
        var contentInput = document.getElementById('qy-content');
        var submitBtn = document.getElementById('qy-submit');
        var counter = document.getElementById('qy-counter');
        var editorMsg = $('.qy-msg', editor);

        if (contentInput && counter) {
            contentInput.addEventListener('input', function () {
                counter.textContent = contentInput.value.length + ' / 2000';
            });
        }

        if (submitBtn) {
            submitBtn.addEventListener('click', function () {
                var content = contentInput ? contentInput.value.trim() : '';

                if (!content) {
                    if (contentInput) {
                        contentInput.focus();
                    }

                    return;
                }

                // 心情徽章：选中的那个 radio，没选就 default
                var moodInput = editor.querySelector('input[name="qy-mood"]:checked');

                submitBtn.disabled = true;
                submitBtn.textContent = '发布中…';

                request('post', {
                    content: content,
                    mood: moodInput ? moodInput.value : 'default',
                    base: cfg.base || ''
                }, function (res) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = '发布';

                    if (0 !== res.code) {
                        if (editorMsg) {
                            setMsg(editorMsg, res.msg || '发布失败', MSG_ERR);
                        } else {
                            window.alert(res.msg || '发布失败');
                        }

                        return;
                    }

                    var data = res.data || {};

                    if (contentInput) {
                        contentInput.value = '';
                    }

                    if (counter) {
                        counter.textContent = '0 / 2000';
                    }

                    if (editorMsg) {
                        setMsg(editorMsg, res.msg || '已发布', MSG_OK);
                    }

                    // 无刷新插入：服务端已渲染好完整条目
                    if (data.itemHtml) {
                        prependItem(data.itemHtml);
                    } else {
                        // 拿不到渲染结果才退回刷新
                        window.setTimeout(function () {
                            window.location.reload();
                        }, 600);
                    }
                });
            });
        }
    }

    /**
     * 把新条目插到列表最前面
     *
     * @param {string} html
     */
    function prependItem(html) {
        var list = getTimeline();

        if (!list) {
            window.location.reload();
            return;
        }

        // 如果当前是空状态，先清掉
        var empty = $('.qy-empty', list);

        if (empty && empty.parentNode) {
            empty.parentNode.removeChild(empty);
        }

        list.insertAdjacentHTML('afterbegin', html);

        var first = list.firstElementChild;

        if (first && first.classList) {
            first.classList.add('is-new');
        }
    }

    /* ================================================================ */
    /* 引用按钮：把选中内容变成 `> ` 引用                                */
    /* ================================================================ */

    /*
     * 与 Model::format() 的解析规则保持一致：
     *   - 选中多行 → 每行前面加 "> "
     *   - 选中的内容本来就都在引用里 → 再点一次取消引用（切换语义）
     *   - 没选中 → 在光标处插入/去掉 "> "，接着打字就是引用
     */
    function toggleQuote(field) {
        if (!field) {
            return;
        }

        var start = field.selectionStart;
        var end   = field.selectionEnd;
        var value = field.value;

        // 不支持 selectionStart 的极老浏览器：退化成在末尾追加
        if (typeof start !== 'number') {
            field.value = value + '> ';
            field.focus();
            return;
        }

        if (start === end) {
            // 光标所在行：行首没有 > 就加上，有就去掉
            var lineStart = value.lastIndexOf('\n', start - 1) + 1;
            var lineEnd   = value.indexOf('\n', start);

            if (lineEnd === -1) {
                lineEnd = value.length;
            }

            var line = value.substring(lineStart, lineEnd);

            if (/^[ \t]{0,3}>[ \t]?/.test(line)) {
                var stripped = line.replace(/^([ \t]{0,3})>[ \t]?/, '$1');
                field.value = value.substring(0, lineStart) + stripped + value.substring(lineEnd);
                field.selectionStart = field.selectionEnd = lineStart + stripped.length;
            } else {
                field.value = value.substring(0, start) + '> ' + value.substring(end);
                field.selectionStart = field.selectionEnd = start + 2;
            }

            field.focus();
            return;
        }

        // 有选中：整段多行一起加 / 去 "> "
        var selected = value.substring(start, end);
        var lines    = selected.split('\n');
        var allQuoted = lines.every(function (l) {
            return '' === l.trim() || /^[ \t]{0,3}>[ \t]?/.test(l);
        });

        var replaced = lines.map(function (l) {
            if ('' === l.trim()) {
                return l;
            }

            return allQuoted
                ? l.replace(/^([ \t]{0,3})>[ \t]?/, '$1')
                : l.replace(/^([ \t]{0,3})/, '$1> ');
        }).join('\n');

        field.value = value.substring(0, start) + replaced + value.substring(end);
        field.selectionStart = start;
        field.selectionEnd   = start + replaced.length;
        field.focus();
    }

    function bindQuote() {
        var btns = document.querySelectorAll('[data-qy-quote]');

        for (var i = 0; i < btns.length; i++) {
            btns[i].addEventListener('click', function () {
                var scope = this.getAttribute('data-qy-quote');
                var field = null;

                if ('closest' === scope) {
                    // 评论框：用它所在表单里的 textarea
                    var form = this.closest ? this.closest('form') : null;
                    field = form ? form.querySelector('textarea') : null;
                } else {
                    field = document.getElementById(scope);
                }

                toggleQuote(field);
            });
        }
    }

    /* ================================================================ */
    /* 表情面板                                                          */
    /* ================================================================ */

    function insertAtCursor(field, text) {
        if (!field) {
            return;
        }

        var start = field.selectionStart;
        var end = field.selectionEnd;
        var value = field.value;

        // 不支持 selectionStart 时（IE8-）直接追加
        if (typeof start !== 'number') {
            field.value = value + text;
            return;
        }

        field.value = value.substring(0, start) + text + value.substring(end);
        field.selectionStart = field.selectionEnd = start + text.length;
        field.focus();
    }

    function bindEmoji(triggerId, panelId, targetId) {
        var trigger = document.getElementById(triggerId);
        var panel = document.getElementById(panelId);

        if (!trigger || !panel) {
            return;
        }

        trigger.addEventListener('click', function (event) {
            event.preventDefault();
            panel.hidden = !panel.hidden;
        });

        panel.addEventListener('click', function (event) {
            var btn = event.target.closest ? event.target.closest('.qy-emoji-item') : null;

            if (!btn) {
                return;
            }

            event.preventDefault();
            insertAtCursor(document.getElementById(targetId), btn.getAttribute('data-emoji') || '');
        });
    }

    // 发布框
    bindEmoji('qy-toggle-emoji', 'qy-emoji', 'qy-content');
    bindQuote();

    // 评论框：动态生成面板
    if (cfg.emojiOn && cfg.emoji) {
        var emojiHtml = '';

        for (var code in cfg.emoji) {
            if (!Object.prototype.hasOwnProperty.call(cfg.emoji, code)) {
                continue;
            }

            emojiHtml += '<button type="button" class="qy-emoji-item" data-emoji="'
                + cfg.emoji[code].replace(/"/g, '&quot;') + '" title="' + code + '">'
                + cfg.emoji[code] + '</button>';
        }

        document.querySelectorAll('.qy-comment-form').forEach(function (form) {
            if (form.querySelector('.qy-emoji')) {
                return;
            }

            var field = form.querySelector('textarea');

            if (!field) {
                return;
            }

            var wrap = document.createElement('div');
            wrap.className = 'qy-emoji';
            wrap.innerHTML = emojiHtml;
            wrap.hidden = true;

            var toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'qy-emoji-toggle';
            toggle.textContent = '表情';

            toggle.addEventListener('click', function (event) {
                event.preventDefault();
                wrap.hidden = !wrap.hidden;
            });

            wrap.addEventListener('click', function (event) {
                var btn = event.target.closest ? event.target.closest('.qy-emoji-item') : null;

                if (!btn) {
                    return;
                }

                event.preventDefault();
                insertAtCursor(field, btn.getAttribute('data-emoji') || '');
            });

            field.parentNode.insertBefore(toggle, field.nextSibling);
            field.parentNode.insertBefore(wrap, toggle.nextSibling);
        });
    }

})();
