<?php
/**
 * Plugin Name: BJ21 — Alertes de panne
 * Description: Envoie à info@productionsideo.com le courriel « mode de récupération » que WordPress expédie lors d'une erreur fatale.
 * Author: Productions Idéo
 * Version: 1.0.0
 *
 * Sans ce filtre, WordPress l'envoie à l'adresse d'administration du site
 * (info@bj21rules.com), qu'on ne lit pas en continu. Complète la veille du
 * serveur (/root/veille), qui voit, elle, les pannes silencieuses.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'recovery_mode_email', function ( $courriel ) {
	$courriel['to'] = 'info@productionsideo.com';
	return $courriel;
} );
