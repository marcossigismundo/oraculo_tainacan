<?php
/**
 * Parser de consultas em linguagem natural
 *
 * @package Oraculo_Tainacan
 */

declare( strict_types=1 );

namespace Oraculo_Tainacan\Search;

/**
 * Extrai restrições estruturadas de uma consulta em linguagem natural.
 *
 * Motivação: a busca CLIP resolve o *visual* ("vidro", "retrato a óleo"),
 * mas conceitos como "século 21" não são visuais — precisam virar filtro de
 * metadado. A API de vetores do IBRAM só suporta filtro por **igualdade de
 * string** sobre chaves do JSON de metadados, então este parser e a
 * indexação (ClipIndexer) precisam falar o mesmo vocabulário de chaves
 * derivadas: `year`, `decade`, `century` — sempre como string.
 *
 * "obras do século 21 que são de vidro"
 *   → clean_query: "obras de vidro"
 *   → filter:      { century: "21" }
 *
 * Intervalos ("entre 1900 e 1950") não são expressáveis como igualdade;
 * viram `year_range` e o chamador pós-filtra os resultados em PHP.
 */
class QueryParser {

	/**
	 * Mapa de numerais romanos aceitos em "século XXI".
	 *
	 * @var array<string,int>
	 */
	private const ROMAN = array(
		'I'     => 1,
		'II'    => 2,
		'III'   => 3,
		'IV'    => 4,
		'V'     => 5,
		'VI'    => 6,
		'VII'   => 7,
		'VIII'  => 8,
		'IX'    => 9,
		'X'     => 10,
		'XI'    => 11,
		'XII'   => 12,
		'XIII'  => 13,
		'XIV'   => 14,
		'XV'    => 15,
		'XVI'   => 16,
		'XVII'  => 17,
		'XVIII' => 18,
		'XIX'   => 19,
		'XX'    => 20,
		'XXI'   => 21,
	);

	/**
	 * Analisa a consulta e separa restrições temporais do texto de busca
	 *
	 * @param string $query Consulta em linguagem natural.
	 * @return array{
	 *   clean_query: string,
	 *   filter: array<string,string>,
	 *   year_range: array{0:int,1:int}|null,
	 *   facets: array<string,string>
	 * }
	 */
	public static function parse( string $query ): array {
		$clean      = ' ' . trim( $query ) . ' ';
		$facets     = array();
		$year_range = null;

		// 1. Intervalo de anos: "entre 1900 e 1950", "de 1900 a 1950", "1900-1950".
		if ( preg_match( '/\b(?:entre\s+|de\s+)?(1[0-9]{3}|20[0-9]{2})\s*(?:e|a|até|ate|-|–)\s*(1[0-9]{3}|20[0-9]{2})\b/iu', $clean, $m ) ) {
			$a          = (int) $m[1];
			$b          = (int) $m[2];
			$year_range = array( min( $a, $b ), max( $a, $b ) );
			$clean      = str_replace( $m[0], ' ', $clean );
		}

		// 2. Década — formas aceitas: decada de 1980, decada de 80, anos 80 e anos 1980.
		if ( preg_match( '/\b(?:d[eé]cada\s+de\s+|anos\s+)(\d{4}|\d{2})\b/iu', $clean, $m ) ) {
			$decade = (int) $m[1];
			if ( $decade < 100 ) {
				// "anos 80" → 1980. Séculos anteriores exigem o ano completo.
				$decade += 1900;
			}
			$facets['decade'] = (string) ( intdiv( $decade, 10 ) * 10 );
			$clean            = str_replace( $m[0], ' ', $clean );
		}

		// 3. Século — formas aceitas: seculo 21, sec. XXI e sec 19.
		if ( preg_match( '/\bs[eé]c(?:ulo)?\.?\s+([0-9]{1,2}|[IVX]{1,5})\b/iu', $clean, $m ) ) {
			$century = self::to_century( $m[1] );
			if ( $century >= 1 && $century <= 21 ) {
				$facets['century'] = (string) $century;
				$clean             = str_replace( $m[0], ' ', $clean );
			}
		}

		// 4. Ano isolado: "de 1922", "em 1985" — só se nada mais específico casou.
		if ( null === $year_range && ! isset( $facets['decade'] )
			&& preg_match( '/\b(1[0-9]{3}|20[0-9]{2})\b/u', $clean, $m ) ) {
			$facets['year'] = $m[1];
			$clean          = str_replace( $m[0], ' ', $clean );
		}

		// Filtro remoto = igualdade única; o mais específico vence. As chaves
		// derivadas na indexação são redundantes entre si (ano ⊃ década ⊃
		// século), então filtrar pela mais específica já implica as demais.
		$filter = array();
		foreach ( array( 'year', 'decade', 'century' ) as $key ) {
			if ( isset( $facets[ $key ] ) ) {
				$filter = array( $key => $facets[ $key ] );
				break;
			}
		}

		// Limpeza final: sobras de conectivos que ficaram soltas após remover
		// a expressão temporal ("obras do  que são de vidro").
		$clean = preg_replace( '/\s+(do|da|de|dos|das|no|na|em)\s+(?=\s)/iu', ' ', $clean );
		$clean = trim( (string) preg_replace( '/\s{2,}/u', ' ', (string) $clean ) );

		// Se a limpeza engoliu tudo (consulta puramente temporal, ex: "obras
		// de 1920"), mantém a consulta original para o encoder ter algum texto.
		if ( '' === $clean ) {
			$clean = trim( $query );
		}

		return array(
			'clean_query' => $clean,
			'filter'      => $filter,
			'year_range'  => $year_range,
			'facets'      => $facets,
		);
	}

	/**
	 * Deriva facetas temporais (year/decade/century) dos metadados de um item
	 *
	 * Usada na indexação remota para que o item carregue as mesmas chaves que
	 * o parser gera na busca. Heurística: primeiro ano plausível (1000–2099)
	 * encontrado nos valores de metadado ou no título.
	 *
	 * @param array $formatted Item no formato de format_tainacan_item().
	 * @return array<string,string> Vazio quando nenhuma data é encontrada.
	 */
	public static function derive_temporal_facets( array $formatted ): array {
		$haystacks = array();

		foreach ( (array) ( $formatted['metadata'] ?? array() ) as $meta ) {
			if ( ! empty( $meta['value'] ) && is_string( $meta['value'] ) ) {
				$haystacks[] = $meta['value'];
			}
		}

		if ( ! empty( $formatted['title'] ) ) {
			$haystacks[] = (string) $formatted['title'];
		}

		foreach ( $haystacks as $text ) {
			if ( preg_match( '/\b(1[0-9]{3}|20[0-9]{2})\b/u', $text, $m ) ) {
				$year = (int) $m[1];

				return array(
					'year'    => (string) $year,
					'decade'  => (string) ( intdiv( $year, 10 ) * 10 ),
					'century' => (string) ( intdiv( $year - 1, 100 ) + 1 ),
				);
			}
		}

		return array();
	}

	/**
	 * Converte "21" ou "XXI" em número de século
	 *
	 * @param string $raw Século em algarismo arábico ou romano.
	 * @return int 0 quando inválido.
	 */
	private static function to_century( string $raw ): int {
		if ( ctype_digit( $raw ) ) {
			return (int) $raw;
		}

		return self::ROMAN[ strtoupper( $raw ) ] ?? 0;
	}
}
