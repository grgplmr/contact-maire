<?php
/**
 * Plugin Name: TPMP Contact Maire
 * Description: Formulaire pour envoyer un message à la mairie via shortcode.
 * Author: Christian Auzolat
 * Version: 0.1.0
 * Text Domain: tpmp-contact-maire
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Main plugin class.
 */
class TPMP_Contact_Maire {
    /**
     * Plugin version.
     */
    const VERSION = '0.1.0';

    /**
     * Shortcode tag.
     */
    const SHORTCODE = 'tpmp_contact_maire';

    /**
     * Nonce action name.
     */
    const NONCE_ACTION = 'tpmp_contact_maire_nonce';

    /**
     * Init hooks.
     */
    public static function init() {
        add_shortcode( self::SHORTCODE, array( __CLASS__, 'render_shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
        add_action( 'wp_ajax_tpmp_contact_maire_send', array( __CLASS__, 'handle_ajax' ) );
        add_action( 'wp_ajax_nopriv_tpmp_contact_maire_send', array( __CLASS__, 'handle_ajax' ) );
    }

    /**
     * Register scripts and styles (only enqueued when shortcode is used).
     */
    public static function register_assets() {
        $plugin_url = plugin_dir_url( __FILE__ );

        wp_register_style(
            'tpmp-contact-maire-style',
            $plugin_url . 'assets/css/app.css',
            array(),
            self::VERSION
        );

        wp_register_script(
            'tpmp-contact-maire-script',
            $plugin_url . 'assets/js/app.js',
            array(),
            self::VERSION,
            true
        );
    }

    /**
     * Render shortcode and enqueue assets.
     *
     * @return string
     */
    public static function render_shortcode() {
        self::enqueue_assets();

        return '<div id="tpmp-contact-maire-root"></div>';
    }

    /**
     * Enqueue scripts and styles with localized data.
     */
    private static function enqueue_assets() {
        $communes = array(
            array(
                'slug'  => 'paris',
                'label' => 'Paris',
            ),
            array(
                'slug'  => 'marseille',
                'label' => 'Marseille',
            ),
            array(
                'slug'  => 'lyon',
                'label' => 'Lyon',
            ),
        );

        wp_enqueue_style( 'tpmp-contact-maire-style' );
        wp_enqueue_script( 'tpmp-contact-maire-script' );

        wp_localize_script(
            'tpmp-contact-maire-script',
            'TPMP_CONTACT_MAIRE',
            array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( self::NONCE_ACTION ),
                'communes' => $communes,
            )
        );
    }

    /**
     * Handle AJAX request for sending emails.
     */
    public static function handle_ajax() {
        // Validate nonce.
        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
            self::send_json_error( 'Nonce invalide.' );
        }

        $commune = isset( $_POST['commune'] ) ? sanitize_text_field( wp_unslash( $_POST['commune'] ) ) : '';
        $email   = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
        $message = isset( $_POST['message'] ) ? wp_strip_all_tags( wp_unslash( $_POST['message'] ) ) : '';

        if ( empty( $commune ) || empty( $email ) || empty( $message ) ) {
            self::send_json_error( 'Tous les champs sont obligatoires.' );
        }

        if ( ! is_email( $email ) ) {
            self::send_json_error( "L'adresse email n'est pas valide." );
        }

        $mairies = array(
            'paris'     => 'mairie-paris@example.com',
            'marseille' => 'mairie-marseille@example.com',
            'lyon'      => 'mairie-lyon@example.com',
        );

        if ( ! isset( $mairies[ $commune ] ) ) {
            self::send_json_error( 'Commune inconnue.' );
        }

        $commune_name = ucfirst( $commune );
        $to           = $mairies[ $commune ];
        $subject      = sprintf( 'Message TPMP pour la mairie de %s', $commune_name );

        $body_parts = array(
            sprintf( 'Commune : %s', $commune_name ),
            sprintf( "Email de l\'expéditeur : %s", $email ),
            'Son message :',
            $message,
            sprintf( 'IP du visiteur : %s', isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'N/A' ),
        );

        $body = implode( "\n\n", $body_parts );

        $headers = array( 'Content-Type: text/plain; charset=UTF-8' );

        $sent = wp_mail( $to, $subject, $body, $headers );

        if ( ! $sent ) {
            self::send_json_error( "Une erreur est survenue lors de l'envoi de l'email." );
        }

        self::send_json_success();
    }

    /**
     * Send JSON success response and exit.
     */
    private static function send_json_success() {
        header( 'Content-Type: application/json; charset=utf-8' );
        echo wp_json_encode( array( 'success' => true ) );
        wp_die();
    }

    /**
     * Send JSON error response and exit.
     *
     * @param string $message Error message.
     */
    private static function send_json_error( $message ) {
        header( 'Content-Type: application/json; charset=utf-8' );
        echo wp_json_encode(
            array(
                'success' => false,
                'error'   => $message,
            )
        );
        wp_die();
    }
}

TPMP_Contact_Maire::init();
