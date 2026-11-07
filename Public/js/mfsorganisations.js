/* MFS Organisations (card #423): confirmations and the backfill on the settings page. */
(function () {
    'use strict';

    // data-mfsorg-confirm on a form (submit) or on a submit button (click).
    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('button[data-mfsorg-confirm]');
        if (btn && !window.confirm(btn.getAttribute('data-mfsorg-confirm'))) {
            e.preventDefault();
        }
    }, true);
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (form.hasAttribute && form.hasAttribute('data-mfsorg-confirm') && !window.confirm(form.getAttribute('data-mfsorg-confirm'))) {
            e.preventDefault();
        }
    }, true);

    // A list badge opens all conversations of its organisation instead of the conversation.
    // Ctrl/Cmd-click or a middle click opens it in a new tab, like a normal link.
    function openBadge(e) {
        var badge = e.target.closest && e.target.closest('.mfsorg-badge-link[data-mfsorg-href]');
        if (!badge || (e.type == 'auxclick' && e.button != 1)) {
            return;
        }
        e.preventDefault();
        e.stopPropagation();
        var href = badge.getAttribute('data-mfsorg-href');
        if (e.ctrlKey || e.metaKey || e.button == 1) {
            window.open(href, '_blank');
        } else {
            window.location.href = href;
        }
    }
    document.addEventListener('click', openBadge, true);
    document.addEventListener('auxclick', openBadge, true);

    function token() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.getAttribute('content') : '';
    }

    function post(url) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-CSRF-TOKEN': token(), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        }).then(function (r) { return r.json(); });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var panel = document.getElementById('mfsorg-backfill');
        if (!panel) {
            return;
        }
        var preview = panel.querySelector('.mfsorg-preview');
        var progress = panel.querySelector('.mfsorg-progress');
        var bar = panel.querySelector('.progress-bar');
        var text = panel.querySelector('.mfsorg-progress-text');

        function show(state) {
            progress.style.display = '';
            bar.style.width = state.percent + '%';
            text.textContent = state.text;
        }

        // The page drives the batches while it is open; the scheduler continues when it's closed.
        function run() {
            post(panel.getAttribute('data-step-url')).then(function (state) {
                show(state);
                if (state.status === 'running') {
                    setTimeout(run, 300);
                }
            }).catch(function () {
                text.textContent = 'Connection lost; the backfill continues in the background. Reload the page to see its progress.';
            });
        }

        panel.querySelector('.mfsorg-preview-btn').addEventListener('click', function () {
            preview.innerHTML = '<p class="text-help">…</p>';
            fetch(panel.getAttribute('data-preview-url'), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.text(); })
                .then(function (html) {
                    preview.innerHTML = html;
                    var start = preview.querySelector('.mfsorg-start-btn');
                    if (start) {
                        start.addEventListener('click', function () {
                            start.disabled = true;
                            post(panel.getAttribute('data-start-url')).then(function (state) {
                                show(state);
                                run();
                            });
                        });
                    }
                });
        });

        if (panel.getAttribute('data-running') === '1') {
            run();
        }
    });
})();

// Searchable organisation lists (select2 comes with FreeScout), and Kanban's organisation filter.
$(function () {
    'use strict';

    function searchable(select) {
        var $s = $(select);
        if (!$.fn.select2 || $s.data('select2')) {
            return;
        }
        $s.select2({ width: '100%', dropdownAutoWidth: true });
    }

    $('.mfsorg-select').each(function () {
        // The "Change" form on a conversation is collapsed: size the list once it opens.
        var collapse = $(this).closest('.collapse');
        if (collapse.length && !collapse.hasClass('in')) {
            var select = this;
            collapse.one('shown.bs.collapse', function () { searchable(select); });
        } else {
            searchable(this);
        }
    });

    // Kanban board: a list next to Kanban's own Filters button. The choice is saved per agent
    // and the board reloads; the server then only shows that organisation's cards.
    var filters = $('.kn-param[data-param="filters"]').first();
    var url = $('meta[name="mfsorg-kanban-filter-url"]').attr('content');
    if (!filters.length || !url) {
        return;
    }
    $.getJSON(url, function (data) {
        if (!data.orgs || !data.orgs.length) {
            return;
        }
        var wrap = $('<span class="mfsorg-kn-filter"></span>').attr('title', data.labels.title);
        var select = $('<select class="form-control"></select>');
        select.append($('<option value=""></option>').text(data.labels.all));
        $.each(data.orgs, function (i, org) {
            select.append($('<option></option>').val(org.id).text(org.name));
        });
        select.val(data.current ? String(data.current) : '');
        wrap.toggleClass('mfsorg-kn-filter-on', !!data.current).append(select);
        filters.after(wrap);
        searchable(select);
        select.on('change', function () {
            $.post(url, { organisation_id: select.val(), _token: $('meta[name="csrf-token"]').attr('content') })
                .always(function () { window.location.reload(); });
        });
    });
});
