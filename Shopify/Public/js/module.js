/**
 * Shopify module for FreeScout.
 *
 *  - loads the customer / orders block of the conversation sidebar;
 *  - opens an order in a slide-out panel (from the sidebar, from the search
 *    box, or from an order number written in a message);
 *  - turns order numbers found in messages into links.
 *
 * All HTML shown here is rendered and escaped on the server (Blade). This file
 * never builds HTML from Shopify data.
 */
(function ($) {
    'use strict';

    var cfg = null;
    var lastFocus = null;

    /**
     * Called from the sidebar template.
     *
     * @param {{conversationId:number, load:boolean, linkOrders:boolean, prefix:string, strings:Object}} config
     */
    window.shopifyInit = function (config) {
        cfg = config || {};
        cfg.strings = cfg.strings || {};
        cfg.prefix = typeof cfg.prefix === 'string' ? cfg.prefix : '#';

        $(document).ready(function () {
            bindEvents();

            if (cfg.load) {
                loadOverview(false);
            }
            if (cfg.linkOrders) {
                startLinking();
            }
        });
    };

    // ------------------------------------------------------------------
    // Events
    // ------------------------------------------------------------------

    function bindEvents() {
        var $doc = $(document);
        $doc.off('.shopify');

        // Sidebar items and links inside messages.
        $doc.on('click.shopify', '[data-shopify-order]', function (e) {
            e.preventDefault();
            e.stopPropagation();
            openOrder($(this).attr('data-shopify-order'));
        });

        $doc.on('keydown.shopify', '.shopify-order-item', function (e) {
            if (e.which === 13 || e.which === 32) {
                e.preventDefault();
                openOrder($(this).attr('data-shopify-order'));
            }
        });

        $doc.on('click.shopify', '.shopify-refresh a', function (e) {
            e.preventDefault();
            loadOverview(true);
        });

        $doc.on('submit.shopify', 'form[data-shopify-search]', function (e) {
            e.preventDefault();
            var value = $.trim($(this).find('input').val());
            if (value) {
                lookupOrder(value);
            }
        });

        $doc.on('click.shopify', '.shopify-panel-overlay, .shopify-panel-close', function () {
            closePanel();
        });

        $doc.on('keyup.shopify', function (e) {
            if (e.key === 'Escape' && isPanelOpen()) {
                closePanel();
            }
        });

        // The panel has to cover the whole page, so it lives directly in <body>.
        var $panels = $('#shopify-order-panel');
        if ($panels.length > 1) {
            $panels.slice(1).remove();
        }
        if ($panels.length && !$panels.first().parent().is('body')) {
            $panels.first().appendTo('body');
        }
    }

    // ------------------------------------------------------------------
    // Server calls
    // ------------------------------------------------------------------

    function call(data, onSuccess, onError) {
        data.conversation_id = cfg.conversationId;
        fsAjax(data, laroute.route('shopify.ajax'), onSuccess, true, onError);
    }

    function loadOverview(refresh) {
        var $box = $('#shopify-orders');
        $box.addClass('shopify-loading');

        call({action: 'orders', refresh: refresh ? 1 : 0}, function (response) {
            $box.removeClass('shopify-loading');
            if (response && response.status === 'success' && response.html) {
                $box.html(response.html);
            } else {
                showBoxError($box, response && response.msg);
            }
        }, function () {
            $box.removeClass('shopify-loading');
            showBoxError($box, cfg.strings.error);
        });
    }

    function showBoxError($box, message) {
        var $error = $('<div class="shopify-error" role="alert"></div>').text(message || cfg.strings.error || '');
        var $loader = $box.find('.shopify-loader');
        if ($loader.length) {
            $loader.replaceWith($error);
        } else {
            $box.find('.panel-body').prepend($error);
        }
    }

    // ------------------------------------------------------------------
    // Order panel
    // ------------------------------------------------------------------

    function openOrder(name) {
        name = $.trim(name || '');
        if (!name) {
            return;
        }

        // Already rendered in the sidebar? Then no request is needed.
        var $source = $('.shopify-detail-source').filter(function () {
            return String($(this).attr('data-shopify-detail')).toLowerCase() === name.toLowerCase();
        }).first();

        if ($source.length) {
            showPanel(name, $source.html());
        } else {
            lookupOrder(name);
        }
    }

    function lookupOrder(name) {
        showPanelMessage(name, cfg.strings.loading || '…', true);

        call({action: 'order', number: name}, function (response) {
            if (response && response.status === 'success' && response.html) {
                showPanel(response.title || name, response.html);
            } else {
                showPanelMessage(name, (response && response.msg) || cfg.strings.error, false);
            }
        }, function () {
            showPanelMessage(name, cfg.strings.error, false);
        });
    }

    function $panel() {
        return $('#shopify-order-panel');
    }

    function isPanelOpen() {
        return $panel().hasClass('active');
    }

    /** @param {string} title  @param {string} html Server-rendered, already escaped. */
    function showPanel(title, html) {
        $('#shopify-panel-title').text(title);
        $('#shopify-panel-body').html(html).scrollTop(0);
        openPanel();
    }

    function showPanelMessage(title, message, loading) {
        $('#shopify-panel-title').text(title);
        $('#shopify-panel-body').empty().append(
            $('<div class="shopify-panel-message"></div>').toggleClass('shopify-panel-loading', !!loading).text(message)
        );
        openPanel();
    }

    function openPanel() {
        var $p = $panel();
        if (!$p.length) {
            return;
        }
        if (!isPanelOpen()) {
            lastFocus = document.activeElement;
        }
        $p.toggleClass('shopify-dark', isDarkPage()).addClass('active').attr('aria-hidden', 'false');
        $('body').css('overflow', 'hidden');
        $p.find('.shopify-panel-close').trigger('focus');
    }

    function closePanel() {
        $panel().removeClass('active').attr('aria-hidden', 'true');
        $('body').css('overflow', '');
        if (lastFocus && typeof lastFocus.focus === 'function') {
            lastFocus.focus();
        }
        lastFocus = null;
    }

    /** True when the page background is dark (works with any dark mode module). */
    function isDarkPage() {
        var elements = [document.body, document.documentElement];
        for (var i = 0; i < elements.length; i++) {
            var color = window.getComputedStyle(elements[i]).backgroundColor;
            var m = /rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*(?:,\s*([\d.]+)\s*)?\)/.exec(color || '');
            if (!m || (m[4] !== undefined && parseFloat(m[4]) === 0)) {
                continue; // transparent: look at the parent.
            }
            return (0.299 * m[1] + 0.587 * m[2] + 0.114 * m[3]) / 255 < 0.45;
        }

        return false;
    }

    // ------------------------------------------------------------------
    // Order numbers in messages
    // ------------------------------------------------------------------

    var SKIP_TAGS = {A: 1, SCRIPT: 1, STYLE: 1, TEXTAREA: 1, CODE: 1, PRE: 1, BUTTON: 1, INPUT: 1, SELECT: 1};

    function escapeRegExp(text) {
        return text.replace(/[.*+?^${}()|[\]\\\-]/g, '\\$&');
    }

    /**
     * Matches "<prefix>1234" (for example #1234 or SHOP-1234) and also
     * "order 1234", "commande n° 1234", "Bestellung Nr. 1234"…
     *
     * Groups: 1 = digits after the prefix; 2 = words before the number;
     * 3 = optional "#"; 4 = digits after the words.
     */
    function buildPattern(prefix) {
        var words = '((?:order|commande|bestellung|pedido|ordine)\\s*(?:n[°ºo]\\.?|no\\.?|nr\\.?|num[eé]ro|number)?\\s*:?\\s*)(#?)(\\d{3,10})(?![A-Za-z0-9])';
        if (prefix) {
            return escapeRegExp(prefix) + '(\\d{3,10})(?![A-Za-z0-9])|' + words;
        }

        // Without prefix, bare numbers are too ambiguous: require a word before them.
        return '()' + words;
    }

    /**
     * Replace order numbers found in one text node by links.
     *
     * @param {Text} node
     * @param {string} source Pattern from buildPattern().
     * @return {boolean} true if the node was replaced.
     */
    function linkTextNode(node, source) {
        var text = node.nodeValue;
        var re = new RegExp(source, 'gi');
        var fragment = document.createDocumentFragment();
        var last = 0;
        var found = false;
        var m;

        while ((m = re.exec(text)) !== null) {
            var before = m.index > 0 ? text.charAt(m.index - 1) : '';
            if (before && /[A-Za-z0-9_]/.test(before)) {
                continue; // inside a longer word: "reorder 1234", "ABC#1234".
            }

            var lead, linkText, digits;
            if (m[4]) { // "order 1234" form: keep the words as plain text, link the number only.
                lead = m[2];
                linkText = m[3] + m[4];
                digits = m[4];
            } else if (m[1]) { // "#1234" form.
                lead = '';
                linkText = m[0];
                digits = m[1];
            } else {
                continue;
            }

            var start = m.index + lead.length;
            fragment.appendChild(document.createTextNode(text.slice(last, start)));

            var a = document.createElement('a');
            a.href = '#';
            a.className = 'shopify-order-link';
            a.setAttribute('data-shopify-order', cfg.prefix + digits);
            if (cfg.strings.linkTitle) {
                a.title = cfg.strings.linkTitle;
            }
            a.textContent = linkText;
            fragment.appendChild(a);

            last = start + linkText.length;
            found = true;
        }

        if (!found) {
            return false;
        }

        fragment.appendChild(document.createTextNode(text.slice(last)));
        node.parentNode.replaceChild(fragment, node);

        return true;
    }

    function isLinkable(node, root) {
        for (var el = node.parentNode; el && el !== root.parentNode; el = el.parentNode) {
            if (el.nodeType !== 1) {
                continue;
            }
            if (SKIP_TAGS[el.tagName]) {
                return false;
            }
            var cls = el.className && el.className.baseVal === undefined ? ' ' + el.className + ' ' : '';
            if (cls.indexOf(' thread-attachments ') !== -1 || cls.indexOf(' note-editor ') !== -1 || cls.indexOf(' shopify-order-link ') !== -1) {
                return false;
            }
            if (el.getAttribute && el.getAttribute('contenteditable') === 'true') {
                return false;
            }
        }

        return true;
    }

    function linkAll(source) {
        var roots = document.querySelectorAll('#conv-layout-main .thread-body');
        var test = new RegExp(source, 'i');

        for (var i = 0; i < roots.length; i++) {
            var root = roots[i];
            var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null, false);
            var nodes = [];
            while (walker.nextNode()) {
                var n = walker.currentNode;
                if (test.test(n.nodeValue) && isLinkable(n, root)) {
                    nodes.push(n);
                }
            }
            for (var j = 0; j < nodes.length; j++) {
                linkTextNode(nodes[j], source);
            }
        }
    }

    function startLinking() {
        var source = buildPattern(cfg.prefix);
        var timer = null;

        var run = function () {
            clearTimeout(timer);
            timer = setTimeout(function () {
                linkAll(source);
            }, 150);
        };

        run();

        // New messages arrive without a page reload.
        var main = document.getElementById('conv-layout-main');
        if (main && window.MutationObserver) {
            new MutationObserver(run).observe(main, {childList: true, subtree: true});
        }
    }

    // Exposed for tests.
    window.shopifyTestApi = {buildPattern: buildPattern, linkTextNode: linkTextNode, linkAll: linkAll, isDarkPage: isDarkPage};

})(jQuery);
