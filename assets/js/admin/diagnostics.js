/**
 * Aba Diagnóstico: teste ao vivo (rota oraculo/v1/diagnostics/probe).
 *
 * A rota passa pelo mesmo caminho da busca — mu-plugin do modo rápido e grupo
 * de processos PHP do nginx —, então o resultado mostra o que o visitante vive.
 * Tudo vai para a tela com textContent: nada do servidor vira HTML.
 */
(function () {
    'use strict';

    var root = document.querySelector('.oraculo-diagnostics');
    if (!root || typeof OraculoAdminPage === 'undefined') {
        return;
    }

    var S = OraculoAdminPage.strings || {};
    var t = function (key) {
        return S[key] || key;
    };
    var button = root.querySelector('.oraculo-diag-probe');
    var box = root.querySelector('.oraculo-diag-probe-result');

    function seconds(ms) {
        return (ms / 1000).toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 2 }) + ' s';
    }

    function row(list, label, value, verdict) {
        var li = document.createElement('li');
        li.className = 'is-' + verdict;
        var strong = document.createElement('strong');
        strong.textContent = label + ': ';
        li.appendChild(strong);
        li.appendChild(document.createTextNode(value));
        list.appendChild(li);
    }

    // Atualiza o cartão de uma verificação com o que o teste viu.
    function setCheck(id, status, label, value, text) {
        var card = root.querySelector('[data-check="' + id + '"]');
        if (!card) {
            return;
        }
        card.className = card.className.replace(/\bis-\w+\b/, 'is-' + status);
        var icons = { ok: 'dashicons-yes-alt', warn: 'dashicons-warning', bad: 'dashicons-dismiss' };
        var icon = card.querySelector('.oraculo-diag-head .dashicons');
        if (icon) {
            icon.className = 'dashicons ' + icons[status];
        }
        card.querySelector('.oraculo-diag-badge').textContent = label;
        card.querySelector('.oraculo-diag-value').textContent = value;
        if (text) {
            var p = document.createElement('p');
            p.className = 'oraculo-diag-live';
            p.textContent = text;
            var old = card.querySelector('.oraculo-diag-live');
            if (old) {
                old.remove();
            }
            card.querySelector('.oraculo-diag-text').after(p);
        }
    }

    // Placar do topo: itens em ordem / itens avaliados (o teste muda o do pool).
    function updateScore() {
        var scored = root.querySelectorAll('.oraculo-diag-item.is-ok, .oraculo-diag-item.is-warn, .oraculo-diag-item.is-bad');
        var ok = root.querySelectorAll('.oraculo-diag-item.is-ok');
        var num = root.querySelector('.oraculo-diag-score-num');
        if (num) {
            num.textContent = ok.length + '/' + scored.length;
        }
    }

    function render(data) {
        box.textContent = '';
        var title = document.createElement('h3');
        title.textContent = t('diagResult');
        box.appendChild(title);
        var list = document.createElement('ul');

        row(list, t('diagBootstrap'), seconds(data.bootstrap_ms) + ' (' + data.plugins_loaded + ' ' + t('diagPluginsLoaded') + ')',
            data.bootstrap_ms <= 150 ? 'ok' : 'warn');
        row(list, t('diagFast'), data.fast_rest ? t('diagYes') : t('diagNo'), data.fast_rest ? 'ok' : 'warn');
        row(list, t('diagPool'), data.pool ? 'sim (' + data.pool + ')' : t('diagNo'), data.pool ? 'ok' : 'warn');

        if (data.retrieval_error) {
            row(list, t('diagSearchTest'), t('diagError') + ': ' + data.retrieval_error, 'bad');
        } else if (typeof data.embedding_ms === 'number') {
            row(list, t('diagEmbedding'), seconds(data.embedding_ms), data.embedding_ms <= 1000 ? 'ok' : 'warn');
            row(list, t('diagVectors'), seconds(data.vectors_ms) + ' — ' + data.items + ' ' + t('diagItems'),
                data.vectors_ms <= 300 ? 'ok' : 'warn');
        }

        if (data.llm_error) {
            row(list, t('diagLlm'), t('diagError') + ': ' + data.llm_error, 'bad');
        } else if (typeof data.llm_ms === 'number') {
            row(list, t('diagLlm') + (data.model ? ' (' + data.model + ')' : ''), seconds(data.llm_ms),
                data.llm_ms <= 3000 ? 'ok' : 'warn');
        }

        box.appendChild(list);
        var note = document.createElement('p');
        note.className = 'description';
        note.textContent = t('diagLegend');
        box.appendChild(note);
        box.hidden = false;

        if (data.pool) {
            setCheck('pool', 'ok', t('diagStatusOk'), t('diagPoolOn') + ' (' + data.pool + ')',
                t('diagPoolOnText'));
        } else {
            setCheck('pool', 'warn', t('diagStatusWarn'), t('diagPoolOff'),
                t('diagPoolOffText'));
        }

        var fastCard = root.querySelector('[data-check="fast_rest"]');
        if (fastCard && fastCard.classList.contains('is-ok') && !data.fast_rest) {
            setCheck('fast_rest', 'warn', t('diagStatusWarn'), t('diagFastNoEffect'),
                t('diagFastNoEffectText'));
        }
        updateScore();
    }

    button.addEventListener('click', function () {
        button.disabled = true;
        box.hidden = false;
        box.textContent = t('diagTesting');

        fetch(root.getAttribute('data-probe-url'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': OraculoAdminPage.restNonce },
            body: JSON.stringify({ deep: button.getAttribute('data-deep') === '1' })
        })
            .then(function (response) {
                return response.json().then(function (json) {
                    if (!response.ok) {
                        throw new Error(json && json.message ? json.message : 'HTTP ' + response.status);
                    }
                    return json;
                });
            })
            .then(render)
            .catch(function (err) {
                box.textContent = t('diagFailed') + ' ' + err.message;
            })
            .then(function () {
                button.disabled = false;
            });
    });
})();
