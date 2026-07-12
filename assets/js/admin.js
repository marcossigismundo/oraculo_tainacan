/**
 * Oráculo Tainacan - Admin JS
 *
 * Script principal do painel administrativo
 */

(function($) {
    'use strict';

    // Verificar se OraculoAdmin está disponível
    if (typeof OraculoAdmin === 'undefined') {
        console.warn('OraculoAdmin não está definido. Algumas funcionalidades podem não funcionar.');
        window.OraculoAdmin = {
            ajaxUrl: (typeof ajaxurl !== 'undefined') ? ajaxurl : '/wp-admin/admin-ajax.php',
            nonce: '',
            strings: {
                confirmDelete: 'Tem certeza?',
                indexing: 'Indexando...',
                completed: 'Concluído!',
                error: 'Erro:',
                testing: 'Testando...',
                success: 'Sucesso!'
            }
        };
    }

    // Namespace
    window.OraculoTainacan = window.OraculoTainacan || {};

    /**
     * Escapa conteúdo dinâmico antes de injetar em HTML (texto e atributos).
     */
    OraculoTainacan.escapeHtml = function(value) {
        if (value === null || value === undefined) {
            return '';
        }
        return String(value).replace(/[&<>"']/g, function(ch) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[ch];
        });
    };

    /**
     * Inicialização
     */
    OraculoTainacan.init = function() {
        this.bindEvents();
        this.initTooltips();
    };

    /**
     * Bind de eventos
     */
    OraculoTainacan.bindEvents = function() {
        // Botão de testar conexão (apenas se não houver outro handler)
        $(document).on('click', '.oraculo-test-connection:not(.has-handler)', this.testConnection);

        // Botão de indexar coleção
        $(document).on('click', '.oraculo-index-btn', this.indexCollection);

        // Formulário de configurações - apenas se não estiver na página do Tainacan
        // A página do Tainacan usa OraculoAdminPage
        if (typeof OraculoAdminPage === 'undefined') {
            $(document).on('submit', '#oraculo-settings-form', this.saveSettings);
        }

        // Toggle de senha
        $(document).on('click', '.oraculo-toggle-password', this.togglePassword);

        // Confirmação de ações destrutivas
        $(document).on('click', '[data-confirm]', this.confirmAction);
    };

    /**
     * Testa conexão com provedor de IA
     */
    OraculoTainacan.testConnection = function(e) {
        e.preventDefault();

        var $button = $(this);
        var $result = $button.siblings('.oraculo-test-result');
        var provider = $button.data('provider') || 'openai';

        $button.prop('disabled', true).text(OraculoAdmin.strings.testing);

        $.ajax({
            url: OraculoAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'oraculo_test_connection',
                provider: provider,
                nonce: OraculoAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    $result.removeClass('error').addClass('success')
                        .html('<span class="dashicons dashicons-yes"></span> ' + OraculoTainacan.escapeHtml(OraculoAdmin.strings.success));
                } else {
                    $result.removeClass('success').addClass('error')
                        .html('<span class="dashicons dashicons-no"></span> ' + OraculoTainacan.escapeHtml(response.data.message));
                }
            },
            error: function(xhr, status, error) {
                var errorMsg = (OraculoAdmin.strings.error || 'Erro') + (error ? ' ' + error : '');
                $result.removeClass('success').addClass('error')
                    .html('<span class="dashicons dashicons-no"></span> ' + OraculoTainacan.escapeHtml(errorMsg));
            },
            complete: function() {
                $button.prop('disabled', false).text('Testar Conexão');
            }
        });
    };

    /**
     * Indexa coleção
     */
    OraculoTainacan.indexCollection = function(e) {
        e.preventDefault();

        var $button = $(this);
        var $row = $button.closest('tr, .oraculo-collection-item');
        var $progress = $row.find('.oraculo-progress-bar');
        var $status = $row.find('.oraculo-status');
        var collectionId = $button.data('collection-id');

        $button.prop('disabled', true);
        $status.removeClass('success error warning').addClass('pending')
            .text(OraculoAdmin.strings.indexing);

        $.ajax({
            url: OraculoAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'oraculo_index_collection',
                collection_id: collectionId,
                force: $button.data('force') ? 1 : 0,
                nonce: OraculoAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    $status.removeClass('pending').addClass('success')
                        .text(OraculoAdmin.strings.completed);
                    if ($progress.length) {
                        $progress.css('width', '100%');
                    }
                    // Atualizar status após 2 segundos
                    setTimeout(function() {
                        OraculoTainacan.refreshStatus(collectionId);
                    }, 2000);
                } else {
                    $status.removeClass('pending').addClass('error')
                        .text(OraculoAdmin.strings.error + ' ' + response.data.message);
                }
            },
            error: function(xhr, status, error) {
                $status.removeClass('pending').addClass('error')
                    .text((OraculoAdmin.strings.error || 'Erro') + (error ? ' ' + error : ''));
            },
            complete: function() {
                $button.prop('disabled', false);
            }
        });
    };

    /**
     * Atualiza status de indexação
     */
    OraculoTainacan.refreshStatus = function(collectionId) {
        $.ajax({
            url: OraculoAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'oraculo_get_indexing_status',
                collection_id: collectionId,
                nonce: OraculoAdmin.nonce
            },
            success: function(response) {
                if (response.success && response.data) {
                    OraculoTainacan.updateUI(collectionId, response.data);
                }
            }
        });
    };

    /**
     * Atualiza interface com dados de status
     */
    OraculoTainacan.updateUI = function(collectionId, data) {
        var $row = $('[data-collection-id="' + collectionId + '"]').closest('tr, .oraculo-collection-item');

        if (data.percentage !== undefined) {
            $row.find('.oraculo-progress-bar').css('width', data.percentage + '%');
            $row.find('.oraculo-percentage').text(data.percentage + '%');
        }

        if (data.indexed_count !== undefined) {
            $row.find('.oraculo-indexed-count').text(data.indexed_count);
        }
    };

    /**
     * Salva configurações
     */
    OraculoTainacan.saveSettings = function(e) {
        e.preventDefault();

        var $form = $(this);
        var $button = $form.find('input[type="submit"], button[type="submit"]');
        var $notice = $form.find('.oraculo-notice');

        // Se não houver elemento de notice, criar um
        if ($notice.length === 0) {
            $notice = $('<div class="notice oraculo-notice" style="display:none;"></div>');
            $form.prepend($notice);
        }

        $button.prop('disabled', true);

        $.ajax({
            url: OraculoAdmin.ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: $form.serialize() + '&action=oraculo_save_settings&nonce=' + OraculoAdmin.nonce,
            success: function(response) {
                if (response && response.success) {
                    $notice.removeClass('notice-error').addClass('notice-success')
                        .html('<p>' + OraculoTainacan.escapeHtml(OraculoAdmin.strings.success || 'Configurações salvas!') + '</p>').show();
                } else {
                    var msg = (response && response.data && response.data.message) ? response.data.message : 'Erro ao salvar';
                    $notice.removeClass('notice-success').addClass('notice-error')
                        .html('<p>' + OraculoTainacan.escapeHtml(msg) + '</p>').show();
                }
            },
            error: function(xhr, status, error) {
                var errorMsg = OraculoAdmin.strings.error || 'Erro:';
                if (error) errorMsg += ' ' + error;
                // Tentar extrair mensagem do response
                try {
                    var resp = JSON.parse(xhr.responseText);
                    if (resp && resp.data && resp.data.message) {
                        errorMsg = resp.data.message;
                    }
                } catch(e) {}
                $notice.removeClass('notice-success').addClass('notice-error')
                    .html('<p>' + OraculoTainacan.escapeHtml(errorMsg) + '</p>').show();
            },
            complete: function() {
                $button.prop('disabled', false);
                setTimeout(function() {
                    $notice.fadeOut();
                }, 3000);
            }
        });
    };

    /**
     * Toggle de visibilidade de senha
     */
    OraculoTainacan.togglePassword = function(e) {
        e.preventDefault();

        var $button = $(this);
        var $input = $button.siblings('input');
        var type = $input.attr('type');

        if (type === 'password') {
            $input.attr('type', 'text');
            $button.find('.dashicons').removeClass('dashicons-visibility').addClass('dashicons-hidden');
        } else {
            $input.attr('type', 'password');
            $button.find('.dashicons').removeClass('dashicons-hidden').addClass('dashicons-visibility');
        }
    };

    /**
     * Confirmação de ação
     */
    OraculoTainacan.confirmAction = function(e) {
        var message = $(this).data('confirm') || OraculoAdmin.strings.confirmDelete;

        if (!confirm(message)) {
            e.preventDefault();
            return false;
        }
    };

    /**
     * Inicializa tooltips
     */
    OraculoTainacan.initTooltips = function() {
        $('[data-tooltip]').each(function() {
            var $el = $(this);
            var text = $el.data('tooltip');

            $el.attr('title', text);
        });
    };

    /**
     * Formata número
     */
    OraculoTainacan.formatNumber = function(num) {
        return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    };

    /**
     * Exibe notificação
     */
    OraculoTainacan.notify = function(message, type) {
        type = type || 'info';

        var $notice = $('<div class="notice notice-' + OraculoTainacan.escapeHtml(type) + ' is-dismissible"><p>' + OraculoTainacan.escapeHtml(message) + '</p></div>');

        $('.oraculo-admin h1').after($notice);

        setTimeout(function() {
            $notice.fadeOut(function() {
                $(this).remove();
            });
        }, 5000);
    };

    // Inicializar quando DOM estiver pronto
    $(document).ready(function() {
        OraculoTainacan.init();
    });

})(jQuery);
