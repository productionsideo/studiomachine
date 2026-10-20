<?php
/**
 * Plugin Name: BJ21 — Ventes vers le dashboard Studio Machine
 * Description: Transmet chaque vente payée (et chaque remboursement) à studiomachine.ca/gestion, onglet Demandes.
 * Author: Productions Idéo
 * Version: 1.0.0
 *
 * Même montage que bj21-studiomachine.php : c'est le serveur qui parle au
 * dashboard, avec la clé STUDIOMACHINE_API_KEY de wp-config.php. Le
 * navigateur n'y est pour rien et ne voit jamais la clé.
 *
 * - Seules les ventes payées partent (pas les paniers abandonnés).
 * - La provenance vient de l'attribution native de WooCommerce
 *   (_wc_order_attribution_*) : aucun témoin de plus.
 * - L'envoi se fait en arrière-plan (Action Scheduler), pour ne jamais
 *   ralentir le retour de Stripe ; en cas d'échec, il est retenté jusqu'à
 *   6 fois, de plus en plus espacé, puis une note sur la commande le signale.
 * - /gestion déduplique sur « commande-<numéro> » : un renvoi ne crée pas de
 *   doublon.
 *
 * Rattraper les ventes passées :
 *   wp bj21-ventes rattraper [--essai]
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class BJ21_Ventes_Gestion {

	const API     = 'https://studiomachine.ca/gestion/api/v1/leads';
	const HOOK    = 'bj21_ventes_envoyer';
	const META    = '_bj21_gestion_envoye';   // « vente » ou « rembourse » : dernier état transmis
	const ESSAIS  = 6;

	/** Les réseaux que le dashboard sait ventiler, selon utm_source ou le référent. */
	const RESEAUX = [
		'facebook'  => [ 'facebook', 'fb', 'm.facebook', 'l.facebook', 'lm.facebook' ],
		'instagram' => [ 'instagram', 'ig', 'l.instagram' ],
		'youtube'   => [ 'youtube', 'yt', 'm.youtube', 'youtu.be' ],
		'tiktok'    => [ 'tiktok' ],
		'linkedin'  => [ 'linkedin', 'lnkd.in' ],
	];

	public static function init() {
		if ( ! defined( 'STUDIOMACHINE_API_KEY' ) || '' === STUDIOMACHINE_API_KEY ) {
			return;
		}
		add_action( 'woocommerce_payment_complete', [ __CLASS__, 'planifier' ] );
		add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'planifier' ] );
		add_action( 'woocommerce_order_status_refunded', [ __CLASS__, 'planifier' ] );
		add_action( self::HOOK, [ __CLASS__, 'envoyer' ], 10, 2 );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'bj21-ventes rattraper', [ __CLASS__, 'rattraper' ] );
		}
	}

	public static function planifier( $commande_id ) {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, [ (int) $commande_id, 1 ], 'bj21-ventes' );
		} else {
			self::envoyer( (int) $commande_id, 1 );
		}
	}

	/** Ce que la commande doit dire au dashboard : « vente », « rembourse », ou null (rien à dire). */
	private static function etat( $commande ) {
		if ( (float) $commande->get_total() <= 0 ) {
			return null;
		}
		if ( $commande->has_status( 'refunded' ) ) {
			return 'rembourse';
		}
		return $commande->is_paid() ? 'vente' : null;
	}

	public static function envoyer( $commande_id, $essai = 1 ) {
		$commande = wc_get_order( $commande_id );
		if ( ! $commande || 'shop_order' !== $commande->get_type() ) {
			return false;
		}
		$etat = self::etat( $commande );
		if ( ! $etat || $commande->get_meta( self::META ) === $etat ) {
			return false;   // rien de neuf à transmettre
		}

		$reponse = wp_remote_post( self::API, [
			'timeout' => 15,
			'headers' => [
				'X-API-Key'    => STUDIOMACHINE_API_KEY,
				'Accept'       => 'application/json',
				'Content-Type' => 'application/json',
			],
			'body'    => wp_json_encode( self::donnees( $commande, $etat ) ),
		] );
		$code = is_wp_error( $reponse ) ? 0 : (int) wp_remote_retrieve_response_code( $reponse );

		if ( $code >= 200 && $code < 300 ) {
			$commande->update_meta_data( self::META, $etat );
			$commande->save_meta_data();
			$commande->add_order_note( 'rembourse' === $etat
				? 'Remboursement transmis au dashboard Studio Machine.'
				: 'Vente transmise au dashboard Studio Machine (onglet Demandes).' );
			return true;
		}

		$erreur = is_wp_error( $reponse ) ? $reponse->get_error_message() : "HTTP {$code} " . substr( wp_remote_retrieve_body( $reponse ), 0, 200 );
		// 422 = données refusées : réessayer ne changerait rien.
		if ( 422 !== $code && $essai < self::ESSAIS && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + 600 * $essai * $essai, self::HOOK, [ (int) $commande_id, $essai + 1 ], 'bj21-ventes' );
		} else {
			$commande->add_order_note( "Échec de l'envoi au dashboard Studio Machine ({$erreur})." );
		}
		error_log( "[bj21-ventes] commande {$commande_id}, essai {$essai} : {$erreur}" );
		return false;
	}

	private static function donnees( $commande, $etat ) {
		$m = function ( $cle ) use ( $commande ) {
			$v = (string) $commande->get_meta( '_wc_order_attribution_' . $cle );
			return '' === $v || '(none)' === $v ? null : mb_substr( $v, 0, 100 );
		};

		$produits = [];
		foreach ( $commande->get_items() as $article ) {
			$produits[] = $article->get_name() . ( $article->get_quantity() > 1 ? ' × ' . $article->get_quantity() : '' );
		}

		$nom    = trim( $commande->get_billing_first_name() . ' ' . $commande->get_billing_last_name() );
		$payee  = $commande->get_date_paid() ?: $commande->get_date_created();
		$langue = (string) $commande->get_meta( 'wpml_language' );
		$appareil = $m( 'device_type' );

		return array_filter( [
			'external_id'  => 'commande-' . $commande->get_id(),
			'name'         => $nom ?: $commande->get_billing_email(),
			'email'        => $commande->get_billing_email(),
			'phone'        => mb_substr( (string) $commande->get_billing_phone(), 0, 30 ) ?: null,
			'message'      => $commande->get_customer_note() ?: null,
			'produit'      => mb_substr( implode( ', ', $produits ), 0, 255 ),
			'montant'      => (float) $commande->get_total(),
			'devise'       => $commande->get_currency(),
			'rembourse'    => 'rembourse' === $etat ? true : null,
			'utm_source'   => $m( 'utm_source' ),
			'utm_medium'   => $m( 'utm_medium' ),
			'utm_campaign' => $m( 'utm_campaign' ),
			'utm_content'  => $m( 'utm_content' ),
			'utm_term'     => $m( 'utm_term' ),
			'referrer'     => $m( 'referrer' ) ? mb_substr( (string) $commande->get_meta( '_wc_order_attribution_referrer' ), 0, 500 ) : null,
			'platform'     => self::reseau( $m( 'utm_source' ), (string) $commande->get_meta( '_wc_order_attribution_referrer' ) ),
			'device'       => $appareil ? strtolower( $appareil ) : null,
			'locale'       => $langue ? substr( $langue, 0, 5 ) : null,
			'submitted_at' => $payee ? $payee->format( DATE_ATOM ) : null,
		], function ( $v ) { return null !== $v; } );
	}

	private static function reseau( $source, $referent ) {
		$hote = $referent ? (string) wp_parse_url( $referent, PHP_URL_HOST ) : '';
		$hote = preg_replace( '/^www\./', '', strtolower( $hote ) );
		$source = strtolower( (string) $source );
		foreach ( self::RESEAUX as $reseau => $indices ) {
			foreach ( $indices as $indice ) {
				if ( $source === $indice || str_starts_with( $hote, $indice . '.' ) || $hote === $indice ) {
					return $reseau;
				}
			}
		}
		return null;
	}

	/**
	 * Transmet les ventes passées qui ne l'ont pas encore été.
	 *
	 * ## OPTIONS
	 *
	 * [--essai]
	 * : Affiche ce qui partirait, sans rien envoyer.
	 */
	public static function rattraper( $args, $options ) {
		$essai = isset( $options['essai'] );
		$n = 0;
		foreach ( wc_get_orders( [ 'limit' => -1, 'type' => 'shop_order', 'status' => [ 'completed', 'processing', 'refunded' ], 'orderby' => 'date', 'order' => 'ASC' ] ) as $commande ) {
			$etat = self::etat( $commande );
			if ( ! $etat || $commande->get_meta( self::META ) === $etat ) {
				continue;
			}
			$n++;
			$ligne = sprintf( '#%d  %s  %s $  %s', $commande->get_id(), $etat, $commande->get_total(), $commande->get_date_paid() ? $commande->get_date_paid()->date( 'Y-m-d' ) : '' );
			if ( $essai ) {
				WP_CLI::log( $ligne );
			} else {
				WP_CLI::log( $ligne . '  → ' . ( self::envoyer( $commande->get_id(), self::ESSAIS ) ? 'transmise' : 'ÉCHEC (voir error_log)' ) );
			}
		}
		WP_CLI::success( $essai ? "{$n} commande(s) à transmettre (rien n'a été envoyé)." : "{$n} commande(s) traitée(s)." );
	}
}

add_action( 'plugins_loaded', [ 'BJ21_Ventes_Gestion', 'init' ] );
