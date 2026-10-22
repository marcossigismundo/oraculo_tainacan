/**
 * Oráculo Tainacan - Cliente REST compartilhado do front.
 *
 * Problema que resolve: o nonce `wp_rest` vai embutido no HTML no momento em
 * que a página é gerada. Se a sessão muda depois disso (login em outra aba,
 * logout/login, sessão expirada, aba aberta por mais de um dia), o nonce
 * deixa de valer e o WordPress responde 403 "A verificação do cookie falhou"
 * (rest_cookie_invalid_nonce), ou o próprio plugin responde 401
 * (oraculo_invalid_nonce).
 *
 * Usa o wp.apiFetch do core: o middleware de nonce dele já renova o nonce em
 * admin-ajax (action=rest-nonce) e repete o pedido em
 * rest_cookie_invalid_nonce, mas o rest-nonce do core só existe para
 * logados. Para visitante anônimo com nonce velho essa renovação falha (o
 * apiFetch rejeita com o Response do endpoint), e o plugin também pode
 * responder oraculo_invalid_nonce. Nesses casos o nonce é renovado pela ação
 * oraculo_rest_nonce (priv + nopriv) e o pedido é repetido uma vez.
 *
 * ES5 estrito. Config via objeto localizado OraculoRestConfig: { ajaxUrl }.
 */
(function (window) {
    'use strict';

    if (window.OraculoRest) {
        return;
    }

    var cfg = window.OraculoRestConfig || {};
    var apiFetch = window.wp && window.wp.apiFetch;
    var pendingRefresh = null;

    // Erro com corpo JSON (WP_Error {code, message, data} ou o
    // {success:false, error} dos handlers do plugin) vira resposta de erro
    // para o chamador. Falha de rede ou corpo não-JSON (502/504 do proxy,
    // fatal em HTML) rejeita, para o chamador mostrar erro de conexão.
    function isResponse(err) {
        return !!(window.Response && err instanceof window.Response);
    }

    // Nonce recusado e não renovado pelo apiFetch.
    function needsNonceRenewal(err) {
        return isResponse(err) || !!(err && (
            err.code === 'oraculo_invalid_nonce' || err.code === 'rest_cookie_invalid_nonce'
        ));
    }

    function isTransportError(err) {
        return !err || typeof err !== 'object' || err instanceof Error || isResponse(err) ||
            err.code === 'fetch_error' || err.code === 'invalid_json';
    }

    // Renova o nonce de visitante anônimo pela ação nopriv do plugin.
    function refreshAnonymousNonce() {
        if (pendingRefresh) {
            return pendingRefresh;
        }

        var body = new window.FormData();
        body.append('action', 'oraculo_rest_nonce');

        pendingRefresh = window.fetch(cfg.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            body: body
        })
            .then(function (response) { return response.json(); })
            .then(function (json) {
                pendingRefresh = null;
                if (json && json.success && json.data && typeof json.data.nonce === 'string' && json.data.nonce) {
                    apiFetch.nonceMiddleware.nonce = json.data.nonce;
                    return json.data.nonce;
                }
                throw new Error('invalid nonce response');
            }, function (err) {
                pendingRefresh = null;
                throw err;
            });

        return pendingRefresh;
    }

    function send(url, data) {
        return apiFetch({ url: url, method: 'POST', data: data || {} }).then(function (payload) {
            return { ok: true, payload: payload };
        });
    }

    /**
     * POST JSON para uma rota REST do plugin.
     *
     * Resolve com { ok, payload }: ok=true com o JSON da resposta, ou ok=false
     * com o erro do WordPress ({code, message, data}). Rejeita só em falha de
     * rede ou resposta que não é JSON.
     */
    function post(url, data) {
        return send(url, data)
            .catch(function (err) {
                if (needsNonceRenewal(err) && cfg.ajaxUrl && apiFetch.nonceMiddleware) {
                    return refreshAnonymousNonce().then(function () {
                        return send(url, data);
                    }, function () {
                        throw err;
                    });
                }
                throw err;
            })
            .catch(function (err) {
                if (isTransportError(err)) {
                    throw err;
                }
                return { ok: false, payload: err };
            });
    }

    window.OraculoRest = { post: post };
})(window);
