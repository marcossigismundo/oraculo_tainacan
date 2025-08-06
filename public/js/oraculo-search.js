/**
 * JavaScript para a página de busca do Oráculo Tainacan
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        // Gerencia o feedback do usuário
        $('.oraculo-feedback-button').on('click', function() {
            var button = $(this);
            var feedbackValue = button.data('value');
            var searchId = button.closest('.oraculo-feedback').data('search-id');
            
            if (!searchId) {
                $('.oraculo-feedback-message').html('<div class="oraculo-feedback-error">ID da busca não encontrado.</div>');
                return;
            }
            
            // Marca o botão como ativo
            $('.oraculo-feedback-button').removeClass('active');
            button.addClass('active');
            
            // Envia o feedback
            $.ajax({
                url: oraculo_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'oraculo_save_feedback',
                    search_id: searchId,
                    feedback: feedbackValue,
                    nonce: oraculo_params.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $('.oraculo-feedback-message').html('<div class="oraculo-feedback-success">' + 
                            (feedbackValue == 1 ? 
                                'Obrigado pelo feedback positivo!' : 
                                'Obrigado pelo feedback. Vamos trabalhar para melhorar as respostas.') + 
                            '</div>');
                    } else {
                        $('.oraculo-feedback-message').html('<div class="oraculo-feedback-error">Erro ao salvar feedback: ' + (response.data ? response.data.message : 'Erro desconhecido') + '</div>');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Erro AJAX:', error);
                    $('.oraculo-feedback-message').html('<div class="oraculo-feedback-error">Erro de comunicação: ' + error + '</div>');
                }
            });
        });
        
        // Permite clicar nos exemplos de perguntas para preencher o campo de busca
        $('.oraculo-example').on('click', function() {
            var example = $(this).text().trim();
            $('#oraculo-query').val(example);
        });
        
        // Expandir/colapsar descrições de itens
        $('.oraculo-item-description').on('click', function() {
            $(this).toggleClass('expanded');
        });
        
        // Adiciona indicadores de carregamento durante a busca
        $('.oraculo-search-form form').on('submit', function() {
            var query = $('#oraculo-query').val().trim();
            
            if (query.length > 0) {
                $('.oraculo-search-button').addClass('loading');
                $('.oraculo-search-text').text('Buscando...');
            }
        });
    });

})(jQuery);