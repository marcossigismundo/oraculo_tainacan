/**
 * Oráculo Tainacan - JavaScript do Painel Administrativo
 */

(function($) {
    'use strict';

    /**
     * Escapa conteúdo dinâmico antes de injetar em HTML (texto e atributos).
     */
    function escapeHtml(value) {
        if (value === null || value === undefined) {
            return '';
        }
        return String(value).replace(/[&<>"']/g, function(ch) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[ch];
        });
    }

    window.OraculoAdmin = {
        ajaxUrl: ajaxurl,
        nonce: OraculoAdmin?.nonce || '',

        init: function() {
            this.bindEvents();
            this.initTabs();
            this.initTooltips();
            this.initProviderCards();
        },

        // As ações por aba (indexar, limpar vetores, salvar, testar conexão)
        // vivem nos arquivos assets/js/admin/<aba>.js — não duplicar aqui.
        bindEvents: function() {
            // Toggle password visibility
            $(document).on('click', '.oraculo-toggle-password', function() {
                var input = $(this).siblings('input');
                var type = input.attr('type') === 'password' ? 'text' : 'password';
                input.attr('type', type);
                $(this).text(type === 'password' ? '👁' : '🔒');
            });
        },

        initTabs: function() {
            var self = this;

            $('.oraculo-tabs .oraculo-tab').on('click', function() {
                var tab = $(this);
                var target = tab.data('tab');

                // Update active tab
                tab.siblings().removeClass('active');
                tab.addClass('active');

                // Update active content
                var container = tab.closest('.oraculo-tabs-container');
                container.find('.oraculo-tab-content').removeClass('active');
                container.find('.oraculo-tab-content[data-tab="' + target + '"]').addClass('active');

                // Store preference
                if (tab.data('persist')) {
                    localStorage.setItem('oraculo_active_tab', target);
                }
            });

            // Restore active tab
            var savedTab = localStorage.getItem('oraculo_active_tab');
            if (savedTab) {
                $('.oraculo-tab[data-tab="' + savedTab + '"]').click();
            }
        },

        initTooltips: function() {
            // Simple tooltip implementation
            $('[data-tooltip]').each(function() {
                $(this).addClass('oraculo-tooltip');
            });
        },

        initProviderCards: function() {
            $('.oraculo-provider-card input[type="radio"]').on('change', function() {
                var card = $(this).closest('.oraculo-provider-card');
                card.siblings().removeClass('active');
                card.addClass('active');
            });
        },

        addLog: function(message, type) {
            var log = $('#oraculo-indexing-log, .oraculo-log');
            if (!log.length) return;

            log.find('.oraculo-log-empty').remove();

            var time = new Date().toLocaleTimeString();
            var entry = $('<div class="oraculo-log-entry ' + escapeHtml(type || '') + '">')
                .html('<span class="oraculo-log-time">[' + escapeHtml(time) + ']</span> ' + escapeHtml(message));

            log.append(entry);
            log.scrollTop(log[0].scrollHeight);
        },

        showNotice: function(type, message) {
            var icon = {
                'success': '✓',
                'error': '✗',
                'warning': '⚠',
                'info': 'ℹ'
            }[type] || 'ℹ';

            var notice = $('<div class="oraculo-alert oraculo-alert-' + escapeHtml(type) + ' oraculo-fade-in">')
                .html('<span class="oraculo-alert-icon">' + icon + '</span><div class="oraculo-alert-content">' + escapeHtml(message) + '</div>');

            // Remove existing notices
            $('.oraculo-admin .oraculo-alert').remove();

            // Add new notice after header
            var header = $('.oraculo-admin-header');
            if (header.length) {
                header.after(notice);
            } else {
                $('.oraculo-admin h1').first().after(notice);
            }

            // Auto-remove after 5 seconds
            setTimeout(function() {
                notice.fadeOut(function() {
                    notice.remove();
                });
            }, 5000);
        },

        showModal: function(title, content, buttons) {
            var modal = $('<div class="oraculo-modal-overlay">')
                .html('<div class="oraculo-modal oraculo-slide-up">' +
                    '<div class="oraculo-modal-header">' +
                        '<h3>' + escapeHtml(title) + '</h3>' +
                        '<button class="oraculo-modal-close">&times;</button>' +
                    '</div>' +
                    '<div class="oraculo-modal-body">' + content + '</div>' +
                    '<div class="oraculo-modal-footer"></div>' +
                '</div>');

            if (buttons) {
                var footer = modal.find('.oraculo-modal-footer');
                buttons.forEach(function(btn) {
                    var button = $('<button class="button ' + (btn.primary ? 'button-primary' : '') + '">')
                        .text(btn.label)
                        .on('click', function() {
                            if (btn.callback) btn.callback();
                            if (btn.close !== false) OraculoAdmin.closeModal();
                        });
                    footer.append(button);
                });
            }

            modal.find('.oraculo-modal-close').on('click', this.closeModal);
            modal.on('click', function(e) {
                if ($(e.target).hasClass('oraculo-modal-overlay')) {
                    OraculoAdmin.closeModal();
                }
            });

            $('body').append(modal);
            return modal;
        },

        closeModal: function() {
            $('.oraculo-modal-overlay').fadeOut(function() {
                $(this).remove();
            });
        },

        formatNumber: function(num) {
            return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        },

        confirm: function(message, callback) {
            this.showModal('Confirmação', '<p>' + escapeHtml(message) + '</p>', [
                { label: 'Cancelar', close: true },
                { label: 'Confirmar', primary: true, callback: callback }
            ]);
        }
    };

    // Initialize on DOM ready
    $(document).ready(function() {
        if ($('.oraculo-admin').length) {
            OraculoAdmin.init();
        }
    });

})(jQuery);
