<?php
/**
 * Signed requests from this site to the NotOnFire dashboard.
 *
 * The signature covers the timestamp, a single-use nonce, the method, the
 * path and the SHA-256 of the body, keyed by the Site Token. The dashboard
 * signs its own requests to the monitoring endpoint the same way.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'NotOnFire_Client', false ) ) {
    final class NotOnFire_Client {

        /**
         * @return array{status:int,body:array|null,error:string}
         */
        public static function get( $path ) {
            if ( ! NotOnFire_Config::is_connected() ) {
                return [ 'status' => 0, 'body' => null, 'error' => 'not_configured' ];
            }

            $timestamp = (string) time();
            $nonce = self::nonce();
            $signature = self::signature( $timestamp, $nonce, 'GET', $path, '', NotOnFire_Config::site_token() );

            $response = wp_remote_get( NotOnFire_Config::server_url() . $path, [
                'timeout' => 10,
                'redirection' => 0,
                'sslverify' => true,
                'headers' => [
                    'Accept' => 'application/json',
                    'X-NotOnFire-Site-ID' => (string) NotOnFire_Config::site_id(),
                    'X-NotOnFire-Timestamp' => $timestamp,
                    'X-NotOnFire-Nonce' => $nonce,
                    'X-NotOnFire-Signature' => $signature,
                    'User-Agent' => 'WP-NotOnFire/' . NotOnFire_Config::VERSION,
                ],
            ] );

            if ( is_wp_error( $response ) ) {
                return [ 'status' => 0, 'body' => null, 'error' => 'unreachable' ];
            }

            $status = (int) wp_remote_retrieve_response_code( $response );
            $body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

            if ( 200 !== $status ) {
                return [ 'status' => $status, 'body' => null, 'error' => 401 === $status ? 'unauthorized' : 'http_error' ];
            }

            if ( ! is_array( $body ) ) {
                return [ 'status' => $status, 'body' => null, 'error' => 'invalid_response' ];
            }

            return [ 'status' => $status, 'body' => $body, 'error' => '' ];
        }

        public static function signature( $timestamp, $nonce, $method, $path, $body, $token ) {
            return hash_hmac( 'sha256', implode( "\n", [
                $timestamp,
                $nonce,
                strtoupper( $method ),
                $path,
                hash( 'sha256', (string) $body ),
            ] ), $token );
        }

        public static function nonce() {
            try {
                $bytes = random_bytes( 32 );
            } catch ( Exception $exception ) {
                $bytes = openssl_random_pseudo_bytes( 32 );
            }

            if ( ! is_string( $bytes ) || 32 !== strlen( $bytes ) ) {
                $bytes = hash( 'sha256', uniqid( 'wp-notonfire-nonce-', true ), true );
            }

            return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
        }
    }
}
