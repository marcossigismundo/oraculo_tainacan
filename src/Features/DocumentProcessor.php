<?php
/**
 * Processador de Documentos
 *
 * Extrai texto de diferentes tipos de documentos para indexação.
 * Suporta PDF, DOCX, imagens (OCR), e mais.
 *
 * @package Oraculo_Tainacan
 */

namespace Oraculo_Tainacan\Features;

use WP_Error;

/**
 * Processa e extrai texto de documentos
 */
class DocumentProcessor {

    /**
     * Tipos MIME suportados
     */
    private const SUPPORTED_TYPES = [
        'application/pdf' => 'process_pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'process_docx',
        'application/msword' => 'process_doc',
        'text/plain' => 'process_text',
        'text/html' => 'process_html',
        'image/jpeg' => 'process_image',
        'image/png' => 'process_image',
        'image/tiff' => 'process_image',
    ];

    /**
     * Máximo de caracteres para extração
     * @var int
     */
    private int $max_chars = 50000;

    /**
     * Verifica se um tipo MIME é suportado
     *
     * @param string $mime_type
     * @return bool
     */
    public function is_supported(string $mime_type): bool {
        return isset(self::SUPPORTED_TYPES[$mime_type]);
    }

    /**
     * Extrai texto de um documento
     *
     * @param string $file_path Caminho do arquivo
     * @param string|null $mime_type Tipo MIME (auto-detecta se null)
     * @return string|WP_Error
     */
    public function extract_text(string $file_path, ?string $mime_type = null) {
        if (!file_exists($file_path)) {
            return new WP_Error('file_not_found', __('Arquivo não encontrado.', 'oraculo_tainacan'));
        }

        if (!$mime_type) {
            $mime_type = mime_content_type($file_path);
        }

        if (!$this->is_supported($mime_type)) {
            /* translators: %s: MIME type of the unsupported file */
            return new WP_Error('unsupported_type', sprintf(
                __('Tipo de arquivo não suportado: %s', 'oraculo_tainacan'),
                $mime_type
            ));
        }

        $method = self::SUPPORTED_TYPES[$mime_type];
        $text = $this->$method($file_path);

        if (is_wp_error($text)) {
            return $text;
        }

        // Limpar e truncar
        $text = $this->clean_text($text);

        if (strlen($text) > $this->max_chars) {
            $text = substr($text, 0, $this->max_chars) . '...';
        }

        return $text;
    }

    /**
     * Extrai texto de um anexo WordPress
     *
     * @param int $attachment_id
     * @return string|WP_Error
     */
    public function extract_from_attachment(int $attachment_id) {
        $file_path = get_attached_file($attachment_id);

        if (!$file_path) {
            return new WP_Error('invalid_attachment', __('Anexo inválido.', 'oraculo_tainacan'));
        }

        $mime_type = get_post_mime_type($attachment_id);

        return $this->extract_text($file_path, $mime_type);
    }

    /**
     * Processa arquivo PDF
     *
     * @param string $file_path
     * @return string|WP_Error
     */
    private function process_pdf(string $file_path) {
        // Tentar usar pdftotext se disponível
        if ($this->command_exists('pdftotext')) {
            $output = [];
            $return_var = 0;

            exec(
                sprintf('pdftotext -layout %s -', escapeshellarg($file_path)),
                $output,
                $return_var
            );

            if ($return_var === 0 && !empty($output)) {
                return implode("\n", $output);
            }
        }

        // Fallback: tentar ler com PHP
        if (class_exists('Smalot\PdfParser\Parser')) {
            try {
                $parser = new \Smalot\PdfParser\Parser();
                $pdf = $parser->parseFile($file_path);
                return $pdf->getText();
            } catch (\Exception $e) {
                // Continuar para próximo método
            }
        }

        // Último recurso: extrair texto básico
        $content = file_get_contents($file_path);
        $text = $this->extract_text_from_pdf_content($content);

        if (empty($text)) {
            return new WP_Error('pdf_extraction_failed', __('Não foi possível extrair texto do PDF.', 'oraculo_tainacan'));
        }

        return $text;
    }

    /**
     * Extrai texto básico de conteúdo PDF
     *
     * @param string $content
     * @return string
     */
    private function extract_text_from_pdf_content(string $content): string {
        // Remover objetos binários
        $content = preg_replace('/stream.*?endstream/s', '', $content);

        // Extrair strings de texto
        preg_match_all('/\(([^)]+)\)/', $content, $matches);

        $text = implode(' ', $matches[1] ?? []);

        // Limpar caracteres especiais
        $text = preg_replace('/[^\p{L}\p{N}\s.,;:!?-]/u', ' ', $text);

        return $text;
    }

    /**
     * Processa arquivo DOCX
     *
     * @param string $file_path
     * @return string|WP_Error
     */
    private function process_docx(string $file_path) {
        $zip = new \ZipArchive();

        if ($zip->open($file_path) !== true) {
            return new WP_Error('docx_open_failed', __('Não foi possível abrir o arquivo DOCX.', 'oraculo_tainacan'));
        }

        $content = $zip->getFromName('word/document.xml');
        $zip->close();

        if (!$content) {
            return new WP_Error('docx_content_failed', __('Não foi possível ler o conteúdo do DOCX.', 'oraculo_tainacan'));
        }

        // Remover tags XML e extrair texto
        $text = wp_strip_all_tags($content);

        // Limpar espaços em branco excessivos
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    /**
     * Processa arquivo DOC (antigo)
     *
     * @param string $file_path
     * @return string|WP_Error
     */
    private function process_doc(string $file_path) {
        // Tentar antiword se disponível
        if ($this->command_exists('antiword')) {
            $output = [];
            exec(sprintf('antiword %s', escapeshellarg($file_path)), $output);

            if (!empty($output)) {
                return implode("\n", $output);
            }
        }

        // Tentar catdoc
        if ($this->command_exists('catdoc')) {
            $output = [];
            exec(sprintf('catdoc %s', escapeshellarg($file_path)), $output);

            if (!empty($output)) {
                return implode("\n", $output);
            }
        }

        // Fallback básico
        $content = file_get_contents($file_path);

        // Tentar extrair texto legível
        $text = preg_replace('/[^\x20-\x7E\x0A\x0D]/', ' ', $content);
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    /**
     * Processa arquivo de texto
     *
     * @param string $file_path
     * @return string
     */
    private function process_text(string $file_path): string {
        return file_get_contents($file_path);
    }

    /**
     * Processa arquivo HTML
     *
     * @param string $file_path
     * @return string
     */
    private function process_html(string $file_path): string {
        $content = file_get_contents($file_path);

        // Remover scripts e styles
        $content = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $content);
        $content = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $content);

        // Converter para texto
        $text = wp_strip_all_tags($content);

        // Decodificar entidades
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');

        return $text;
    }

    /**
     * Processa imagem com OCR
     *
     * @param string $file_path
     * @return string|WP_Error
     */
    private function process_image(string $file_path) {
        // Verificar se Tesseract está disponível
        if (!$this->command_exists('tesseract')) {
            return new WP_Error(
                'ocr_not_available',
                __('OCR não disponível. Instale o Tesseract para processar imagens.', 'oraculo_tainacan')
            );
        }

        $temp_file = tempnam(sys_get_temp_dir(), 'ocr_');

        // Executar OCR
        $cmd = sprintf(
            'tesseract %s %s -l por+eng 2>/dev/null',
            escapeshellarg($file_path),
            escapeshellarg($temp_file)
        );

        exec($cmd, $output, $return_var);

        $text_file = $temp_file . '.txt';

        if (!file_exists($text_file)) {
            wp_delete_file($temp_file);
            return new WP_Error('ocr_failed', __('Falha ao executar OCR na imagem.', 'oraculo_tainacan'));
        }

        $text = file_get_contents($text_file);

        // Limpar arquivos temporários
        wp_delete_file($temp_file);
        wp_delete_file($text_file);

        return $text;
    }

    /**
     * Limpa texto extraído
     *
     * @param string $text
     * @return string
     */
    private function clean_text(string $text): string {
        // Normalizar quebras de linha
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Remover linhas vazias excessivas
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        // Remover espaços em branco excessivos
        $text = preg_replace('/[ \t]+/', ' ', $text);

        // Remover caracteres de controle
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);

        // Normalizar Unicode
        if (function_exists('normalizer_normalize')) {
            $text = normalizer_normalize($text, \Normalizer::NFC);
        }

        return trim($text);
    }

    /**
     * Verifica se um comando existe no sistema
     *
     * @param string $command
     * @return bool
     */
    private function command_exists(string $command): bool {
        $return_var = 1;
        $output = [];

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            exec("where {$command} 2>NUL", $output, $return_var);
        } else {
            exec("which {$command} 2>/dev/null", $output, $return_var);
        }

        return $return_var === 0;
    }

    /**
     * Obtém lista de tipos suportados
     *
     * @return array
     */
    public function get_supported_types(): array {
        return array_keys(self::SUPPORTED_TYPES);
    }

    /**
     * Verifica capacidades do sistema
     *
     * @return array
     */
    public function check_capabilities(): array {
        return [
            'pdf' => [
                'available' => true,
                'pdftotext' => $this->command_exists('pdftotext'),
                'smalot' => class_exists('Smalot\PdfParser\Parser'),
            ],
            'docx' => [
                'available' => class_exists('ZipArchive'),
            ],
            'doc' => [
                'available' => $this->command_exists('antiword') || $this->command_exists('catdoc'),
                'antiword' => $this->command_exists('antiword'),
                'catdoc' => $this->command_exists('catdoc'),
            ],
            'ocr' => [
                'available' => $this->command_exists('tesseract'),
                'tesseract' => $this->command_exists('tesseract'),
            ],
        ];
    }
}
