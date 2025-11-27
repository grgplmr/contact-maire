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
     * Option name for storing communes.
     */
    const OPTION_NAME = 'tpmp_contact_maire_communes';

    /**
     * Option name for storing templates.
     */
    const TEMPLATES_OPTION = 'tpmp_contact_maire_templates';

    /**
     * Option name for storing forbidden words.
     */
    const FORBIDDEN_WORDS_OPTION = 'tpmp_contact_maire_forbidden_words';

    /**
     * Log table name suffix.
     */
    const LOG_TABLE = 'tpmp_contact_logs';

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
        add_action( 'admin_menu', array( __CLASS__, 'register_settings_page' ) );
        add_action( 'admin_init', array( __CLASS__, 'handle_form_submission' ) );
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
        $communes = self::get_communes_for_front();
        $templates = self::get_templates_for_front();

        wp_enqueue_style( 'tpmp-contact-maire-style' );
        wp_enqueue_script( 'tpmp-contact-maire-script' );

        wp_localize_script(
            'tpmp-contact-maire-script',
            'TPMP_CONTACT_MAIRE',
            array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( self::NONCE_ACTION ),
                'communes' => $communes,
                'templates' => $templates,
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

        $commune_slug = isset( $_POST['commune'] ) ? sanitize_text_field( wp_unslash( $_POST['commune'] ) ) : '';
        $email        = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
        $message      = isset( $_POST['message'] ) ? wp_strip_all_tags( wp_unslash( $_POST['message'] ) ) : '';
        $sender_ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'N/A';

        if ( empty( $commune_slug ) || empty( $email ) || empty( $message ) ) {
            // Log incomplete attempts as errors to keep an audit trail of submissions.
            self::log_event( array(
                'commune_slug'  => $commune_slug,
                'commune_label' => '',
                'sender_email'  => $email,
                'sender_ip'     => $sender_ip,
                'message'       => $message,
                'status'        => 'error',
            ) );

            self::send_json_error( 'Tous les champs sont obligatoires (commune, email, message).' );
        }

        if ( ! is_email( $email ) ) {
            self::log_event( array(
                'commune_slug'  => $commune_slug,
                'commune_label' => '',
                'sender_email'  => $email,
                'sender_ip'     => $sender_ip,
                'message'       => $message,
                'status'        => 'error',
            ) );

            self::send_json_error( "L'adresse email n'est pas valide." );
        }

        $mairies = get_option( self::OPTION_NAME, array() );

        if ( ! isset( $mairies[ $commune_slug ] ) ) {
            self::log_event( array(
                'commune_slug'  => $commune_slug,
                'commune_label' => '',
                'sender_email'  => $email,
                'sender_ip'     => $sender_ip,
                'message'       => $message,
                'status'        => 'error',
            ) );

            self::send_json_error( 'Commune inconnue.' );
        }

        $commune_label = isset( $mairies[ $commune_slug ]['label'] ) ? $mairies[ $commune_slug ]['label'] : ucfirst( $commune_slug );
        $to            = isset( $mairies[ $commune_slug ]['email'] ) ? $mairies[ $commune_slug ]['email'] : '';

        if ( empty( $to ) || ! is_email( $to ) ) {
            self::log_event( array(
                'commune_slug'  => $commune_slug,
                'commune_label' => $commune_label,
                'sender_email'  => $email,
                'sender_ip'     => $sender_ip,
                'message'       => $message,
                'status'        => 'error',
            ) );

            self::send_json_error( 'Email de la mairie invalide.' );
        }

        $forbidden_words = self::sanitize_forbidden_words_array( get_option( self::FORBIDDEN_WORDS_OPTION, array() ) );

        if ( ! empty( $forbidden_words ) ) {
            $normalized_message = mb_strtolower( remove_accents( $message ), 'UTF-8' );

            foreach ( $forbidden_words as $word ) {
                $normalized_word = self::normalize_forbidden_word( $word );

                if ( '' === $normalized_word ) {
                    continue;
                }

                if ( false !== mb_strpos( $normalized_message, $normalized_word, 0, 'UTF-8' ) ) {
                    self::log_event( array(
                        'commune_slug'  => $commune_slug,
                        'commune_label' => $commune_label,
                        'sender_email'  => $email,
                        'sender_ip'     => $sender_ip,
                        'message'       => $message,
                        'status'        => 'blocked',
                    ) );

                    self::send_json_error( 'Votre message contient un terme non autorisé. Merci de le reformuler.' );
                }
            }
        }

        $subject = sprintf( 'Message TPMP pour la mairie de %s', $commune_label );

        $body_parts = array(
            sprintf( 'A l\'attention de Monsieur / Madame le / la Maire de %s.', $commune_label ),
            $message,
            '',
            '',
            sprintf( 'Répondre à : %s', $email ),
            sprintf( 'IP du visiteur : %s', $sender_ip ),
        );

        $body = implode( "\n", $body_parts );

        $headers = array( 'Content-Type: text/plain; charset=UTF-8' );

        $sent = wp_mail( $to, $subject, $body, $headers );

        $status = $sent ? 'sent' : 'error';

        self::log_event( array(
            'commune_slug'  => $commune_slug,
            'commune_label' => $commune_label,
            'sender_email'  => $email,
            'sender_ip'     => $sender_ip,
            'message'       => $message,
            'status'        => $status,
        ) );

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

    /**
     * Get communes formatted for the front-end.
     *
     * @return array
     */
    private static function get_communes_for_front() {
        $stored_communes = get_option( self::OPTION_NAME, array() );

        $communes = array();

        foreach ( $stored_communes as $slug => $data ) {
            $communes[] = array(
                'slug'  => $slug,
                'label' => isset( $data['label'] ) ? $data['label'] : ucfirst( $slug ),
            );
        }

        return $communes;
    }

    /**
     * Get templates formatted for the front-end.
     *
     * @return array
     */
    private static function get_templates_for_front() {
        $stored_templates = get_option( self::TEMPLATES_OPTION, array() );

        $templates = array();

        foreach ( $stored_templates as $template ) {
            if ( empty( $template['id'] ) ) {
                continue;
            }

            $templates[] = array(
                'id'       => sanitize_key( $template['id'] ),
                'label'    => isset( $template['label'] ) ? sanitize_text_field( $template['label'] ) : '',
                'category' => isset( $template['category'] ) ? sanitize_text_field( $template['category'] ) : '',
                'content'  => isset( $template['content'] ) ? wp_kses_post( $template['content'] ) : '',
            );
        }

        return $templates;
    }

    /**
     * Register admin settings page.
     */
    public static function register_settings_page() {
        add_options_page(
            'TPMP Contact Maire',
            'TPMP Contact Maire',
            'manage_options',
            'tpmp-contact-maire-settings',
            array( __CLASS__, 'render_settings_page' )
        );
    }

    /**
     * Handle form submission for settings page.
     */
    public static function handle_form_submission() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( isset( $_GET['tpmp_export'] ) ) {
            self::handle_exports();
            return;
        }

        if ( isset( $_POST['tpmp_communes_csv_action'] ) ) {
            self::handle_communes_import();
            return;
        }

        if ( isset( $_REQUEST['tpmp_contact_maire_action'] ) ) {
            self::handle_commune_submission();
            return;
        }

        if ( isset( $_POST['tpmp_templates_csv_action'] ) ) {
            self::handle_templates_import();
            return;
        }

        if ( isset( $_REQUEST['tpmp_contact_maire_template_action'] ) || ( isset( $_GET['tpmp_action'] ) && 'delete_template' === $_GET['tpmp_action'] ) ) {
            self::handle_template_submission();
            return;
        }

        if ( isset( $_POST['tpmp_forbidden_action'] ) ) {
            self::handle_forbidden_words();
        }
    }

    /**
     * Handle commune form submission.
     */
    private static function handle_commune_submission() {
        check_admin_referer( 'tpmp_contact_maire_manage_communes' );

        $action   = isset( $_REQUEST['tpmp_contact_maire_action'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['tpmp_contact_maire_action'] ) ) : '';
        $communes = get_option( self::OPTION_NAME, array() );

        if ( 'add' === $action ) {
            $slug  = isset( $_POST['tpmp_commune_slug'] ) ? sanitize_title( wp_unslash( $_POST['tpmp_commune_slug'] ) ) : '';
            $label = isset( $_POST['tpmp_commune_label'] ) ? sanitize_text_field( wp_unslash( $_POST['tpmp_commune_label'] ) ) : '';
            $email = isset( $_POST['tpmp_commune_email'] ) ? sanitize_email( wp_unslash( $_POST['tpmp_commune_email'] ) ) : '';

            if ( empty( $slug ) || empty( $label ) || empty( $email ) ) {
                add_settings_error( 'tpmp_contact_maire', 'tpmp_contact_maire_missing_fields', 'Veuillez renseigner tous les champs.', 'error' );
            } elseif ( ! is_email( $email ) ) {
                add_settings_error( 'tpmp_contact_maire', 'tpmp_contact_maire_invalid_email', "L'adresse email n'est pas valide.", 'error' );
            } else {
                $communes[ $slug ] = array(
                    'label' => $label,
                    'email' => $email,
                );

                update_option( self::OPTION_NAME, $communes );
                add_settings_error( 'tpmp_contact_maire', 'tpmp_contact_maire_added', 'Commune ajoutée avec succès.', 'updated' );
            }
        }

        if ( 'delete' === $action ) {
            $slug = isset( $_GET['slug'] ) ? sanitize_title( wp_unslash( $_GET['slug'] ) ) : '';

            if ( isset( $communes[ $slug ] ) ) {
                unset( $communes[ $slug ] );
                update_option( self::OPTION_NAME, $communes );
                add_settings_error( 'tpmp_contact_maire', 'tpmp_contact_maire_deleted', 'Commune supprimée avec succès.', 'updated' );
            } else {
                add_settings_error( 'tpmp_contact_maire', 'tpmp_contact_maire_not_found', 'Commune introuvable.', 'error' );
            }
        }

        self::redirect_with_notices();
    }

    /**
     * Handle commune CSV import.
     */
    private static function handle_communes_import() {
        check_admin_referer( 'tpmp_contact_maire_import_communes', 'tpmp_contact_maire_import_communes_nonce' );

        if ( empty( $_FILES['tpmp_communes_csv']['tmp_name'] ) ) {
            add_settings_error( 'tpmp_contact_maire', 'tpmp_contact_maire_no_commune_file', 'Aucun fichier CSV fourni pour les communes.', 'error' );
            self::redirect_with_notices();
        }

        $rows = self::read_csv_file( $_FILES['tpmp_communes_csv']['tmp_name'] );
        $count = 0;

        $communes = get_option( self::OPTION_NAME, array() );

        foreach ( $rows as $index => $row ) {
            if ( $index === 0 && isset( $row[0] ) && isset( $row[1] ) ) {
                $header_first  = strtolower( trim( (string) $row[0] ) );
                $header_second = strtolower( trim( (string) $row[1] ) );

                if ( in_array( $header_first, array( 'nom', 'name' ), true ) && in_array( $header_second, array( 'email' ), true ) ) {
                    continue;
                }
            }

            if ( empty( $row[0] ) || empty( $row[1] ) ) {
                continue;
            }

            $label = sanitize_text_field( wp_unslash( $row[0] ) );
            $email = sanitize_email( wp_unslash( $row[1] ) );

            if ( empty( $label ) || empty( $email ) || ! is_email( $email ) ) {
                continue;
            }

            $slug = self::slugify( $label );

            if ( empty( $slug ) ) {
                continue;
            }

            $communes[ $slug ] = array(
                'label' => $label,
                'email' => $email,
            );

            $count++;
        }

        update_option( self::OPTION_NAME, $communes );

        add_settings_error(
            'tpmp_contact_maire',
            'tpmp_contact_maire_communes_imported',
            sprintf( '%d communes importées.', $count ),
            'updated'
        );

        self::redirect_with_notices();
    }

    /**
     * Handle template form submission.
     */
    private static function handle_template_submission() {
        check_admin_referer( 'tpmp_contact_maire_manage_templates', 'tpmp_contact_maire_templates_nonce' );

        $action    = isset( $_REQUEST['tpmp_contact_maire_template_action'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['tpmp_contact_maire_template_action'] ) ) : '';
        $templates = get_option( self::TEMPLATES_OPTION, array() );

        if ( 'add_template' === $action ) {
            $template_id    = isset( $_POST['tpmp_template_id'] ) ? sanitize_key( wp_unslash( $_POST['tpmp_template_id'] ) ) : '';
            $template_label = isset( $_POST['tpmp_template_label'] ) ? sanitize_text_field( wp_unslash( $_POST['tpmp_template_label'] ) ) : '';
            $template_cat   = isset( $_POST['tpmp_template_category'] ) ? sanitize_text_field( wp_unslash( $_POST['tpmp_template_category'] ) ) : '';
            $template_body  = isset( $_POST['tpmp_template_content'] ) ? wp_kses_post( wp_unslash( $_POST['tpmp_template_content'] ) ) : '';

            if ( empty( $template_id ) || empty( $template_label ) || empty( $template_body ) ) {
                add_settings_error( 'tpmp_contact_maire', 'tpmp_contact_maire_template_missing_fields', 'Veuillez renseigner l\'ID, le titre et le contenu du modèle.', 'error' );
            } else {
                $templates[ $template_id ] = array(
                    'id'       => $template_id,
                    'label'    => $template_label,
                    'category' => $template_cat,
                    'content'  => $template_body,
                );

                update_option( self::TEMPLATES_OPTION, $templates );
                add_settings_error( 'tpmp_contact_maire', 'tpmp_contact_maire_template_saved', 'Modèle enregistré avec succès.', 'updated' );
            }
        }

        if ( ( isset( $_GET['tpmp_action'] ) && 'delete_template' === $_GET['tpmp_action'] ) && isset( $_GET['template_id'] ) ) {
            $template_id = sanitize_key( wp_unslash( $_GET['template_id'] ) );

            if ( isset( $templates[ $template_id ] ) ) {
                unset( $templates[ $template_id ] );
                update_option( self::TEMPLATES_OPTION, $templates );
                add_settings_error( 'tpmp_contact_maire', 'tpmp_contact_maire_template_deleted', 'Modèle supprimé avec succès.', 'updated' );
            } else {
                add_settings_error( 'tpmp_contact_maire', 'tpmp_contact_maire_template_not_found', 'Modèle introuvable.', 'error' );
            }
        }

        self::redirect_with_notices();
    }

    /**
     * Handle templates CSV import.
     */
    private static function handle_templates_import() {
        check_admin_referer( 'tpmp_contact_maire_import_templates', 'tpmp_contact_maire_import_templates_nonce' );

        if ( empty( $_FILES['tpmp_templates_csv']['tmp_name'] ) ) {
            add_settings_error( 'tpmp_contact_maire', 'tpmp_contact_maire_no_templates_file', 'Aucun fichier CSV fourni pour les modèles.', 'error' );
            self::redirect_with_notices();
        }

        $rows = self::read_csv_file( $_FILES['tpmp_templates_csv']['tmp_name'] );

        $existing_templates = get_option( self::TEMPLATES_OPTION, array() );
        if ( ! is_array( $existing_templates ) ) {
            $existing_templates = array();
        }

        // Force a numerically indexed array so we always append new templates at the end.
        $existing_templates = array_values( $existing_templates );

        // Track existing hashes (category|label|content) to avoid exact duplicates on re-import.
        $existing_hashes = array();
        foreach ( $existing_templates as $template ) {
            $existing_hash = md5(
                strtolower( trim( isset( $template['category'] ) ? $template['category'] : '' ) ) . '|' .
                strtolower( trim( isset( $template['label'] ) ? $template['label'] : '' ) ) . '|' .
                strtolower( trim( isset( $template['content'] ) ? $template['content'] : '' ) )
            );

            $existing_hashes[ $existing_hash ] = true;
        }

        // Keep a per-slug counter so that identical titles produce unique IDs.
        $slug_counts = array();

        $count = 0;

        foreach ( $rows as $index => $row ) {
            // Ignore the header row if present (Titre;Catégorie;Contenu).
            if ( $index === 0 && isset( $row[0], $row[1], $row[2] ) ) {
                $headers = array(
                    strtolower( trim( (string) $row[0] ) ),
                    strtolower( trim( (string) $row[1] ) ),
                    strtolower( trim( (string) $row[2] ) ),
                );

                if ( in_array( 'titre', $headers, true ) && ( in_array( 'catégorie', $headers, true ) || in_array( 'categorie', $headers, true ) ) ) {
                    continue;
                }
            }

            if ( empty( $row[0] ) || empty( $row[2] ) ) {
                continue;
            }

            $title    = sanitize_text_field( wp_unslash( $row[0] ) );
            $category = isset( $row[1] ) ? sanitize_text_field( wp_unslash( $row[1] ) ) : '';
            $content  = wp_kses_post( wp_unslash( $row[2] ) );

            $title    = trim( $title );
            $category = trim( $category );
            $content  = trim( $content );

            if ( '' === $title || '' === $content ) {
                continue;
            }

            $base_slug = self::slugify( $title );

            if ( '' === $base_slug ) {
                continue;
            }

            if ( ! isset( $slug_counts[ $base_slug ] ) ) {
                $slug_counts[ $base_slug ] = 0;
            }

            $slug_counts[ $base_slug ]++;

            // Build a unique identifier for this row based on the slug and its occurrence count.
            $id = $base_slug . '-' . $slug_counts[ $base_slug ];

            $hash = md5( strtolower( $category ) . '|' . strtolower( $title ) . '|' . strtolower( $content ) );

            // Skip exact duplicates if the same category/title/content already exists.
            if ( isset( $existing_hashes[ $hash ] ) ) {
                continue;
            }

            $existing_templates[] = array(
                'id'       => $id,
                'label'    => $title,
                'category' => $category,
                'content'  => $content,
            );

            $existing_hashes[ $hash ] = true;
            $count++;
        }

        update_option( self::TEMPLATES_OPTION, $existing_templates );

        add_settings_error(
            'tpmp_contact_maire',
            'tpmp_contact_maire_templates_imported',
            sprintf( '%d modèles importés.', $count ),
            'updated'
        );

        self::redirect_with_notices();
    }

    /**
     * Handle forbidden words save and import.
     */
    private static function handle_forbidden_words() {
        $action = isset( $_POST['tpmp_forbidden_action'] ) ? sanitize_text_field( wp_unslash( $_POST['tpmp_forbidden_action'] ) ) : '';

        if ( 'save' === $action ) {
            check_admin_referer( 'tpmp_contact_maire_save_forbidden', 'tpmp_contact_maire_save_forbidden_nonce' );

            $raw_words = isset( $_POST['tpmp_forbidden_words'] ) ? (string) wp_unslash( $_POST['tpmp_forbidden_words'] ) : '';
            $words     = preg_split( '/\r?\n/', $raw_words );
            $cleaned   = self::sanitize_forbidden_words_array( $words );

            update_option( self::FORBIDDEN_WORDS_OPTION, $cleaned );

            add_settings_error( 'tpmp_contact_maire', 'tpmp_contact_maire_forbidden_saved', 'Mots interdits enregistrés.', 'updated' );
            self::redirect_with_notices();
        }

        if ( 'import' === $action ) {
            check_admin_referer( 'tpmp_contact_maire_import_forbidden', 'tpmp_contact_maire_import_forbidden_nonce' );

            if ( empty( $_FILES['tpmp_forbidden_csv']['tmp_name'] ) ) {
                add_settings_error( 'tpmp_contact_maire', 'tpmp_contact_maire_no_forbidden_file', 'Aucun fichier CSV fourni pour les mots interdits.', 'error' );
                self::redirect_with_notices();
            }

            $rows = self::read_csv_file( $_FILES['tpmp_forbidden_csv']['tmp_name'] );
            $existing = self::sanitize_forbidden_words_array( get_option( self::FORBIDDEN_WORDS_OPTION, array() ) );

            $words = array();

            foreach ( $existing as $word ) {
                self::add_forbidden_word_to_set( $words, $word );
            }

            $imported = 0;

            foreach ( $rows as $row_index => $row ) {
                if ( $row_index === 0 && isset( $row[0] ) ) {
                    $header = strtolower( trim( (string) $row[0] ) );
                    if ( in_array( $header, array( 'mot', 'mots' ), true ) && count( $row ) === 1 ) {
                        continue;
                    }
                }

                foreach ( $row as $value ) {
                    if ( self::add_forbidden_word_to_set( $words, $value ) ) {
                        $imported++;
                    }
                }
            }

            update_option( self::FORBIDDEN_WORDS_OPTION, array_values( $words ) );

            add_settings_error(
                'tpmp_contact_maire',
                'tpmp_contact_maire_forbidden_imported',
                sprintf( '%d mots interdits importés.', $imported ),
                'updated'
            );

            self::redirect_with_notices();
        }
    }

    /**
     * Add a forbidden word into an associative set keyed by normalized value.
     *
     * @param array  $set  Associative array of normalized => original word.
     * @param string $word Word to add.
     *
     * @return bool True if the word was added, false otherwise.
     */
    private static function add_forbidden_word_to_set( &$set, $word ) {
        $original = trim( (string) $word );

        if ( '' === $original ) {
            return false;
        }

        $normalized = self::normalize_forbidden_word( $original );

        if ( '' === $normalized || isset( $set[ $normalized ] ) ) {
            return false;
        }

        $set[ $normalized ] = sanitize_text_field( $original );

        return true;
    }

    /**
     * Sanitize and deduplicate a list of forbidden words.
     *
     * @param mixed $words Raw words list.
     *
     * @return array
     */
    private static function sanitize_forbidden_words_array( $words ) {
        if ( is_string( $words ) ) {
            $words = array( $words );
        }

        if ( ! is_array( $words ) ) {
            return array();
        }

        $set = array();

        foreach ( $words as $word ) {
            self::add_forbidden_word_to_set( $set, $word );
        }

        return array_values( $set );
    }

    /**
     * Normalize a forbidden word for case-insensitive comparison.
     *
     * @param string $word Word to normalize.
     *
     * @return string
     */
    private static function normalize_forbidden_word( $word ) {
        return mb_strtolower( remove_accents( trim( (string) $word ) ), 'UTF-8' );
    }

    /**
     * Handle CSV exports for communes, templates and forbidden words.
     */
    private static function handle_exports() {
        $type = isset( $_GET['tpmp_export'] ) ? sanitize_key( wp_unslash( $_GET['tpmp_export'] ) ) : '';

        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

        if ( ! wp_verify_nonce( $nonce, 'tpmp_contact_maire_export_' . $type ) ) {
            wp_die( 'Nonce invalide.' );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Permissions insuffisantes.' );
        }

        switch ( $type ) {
            case 'communes':
                self::export_communes();
                break;
            case 'templates':
                self::export_templates();
                break;
            case 'forbidden':
                self::export_forbidden_words();
                break;
            case 'logs':
                self::export_logs();
                break;
            default:
                wp_die( 'Export inconnu.' );
        }
    }

    /**
     * Export communes as CSV.
     */
    private static function export_communes() {
        $communes = get_option( self::OPTION_NAME, array() );

        self::send_csv_headers( 'tpmp-communes.csv' );
        echo "Nom;Email\n";

        foreach ( $communes as $commune ) {
            $label = isset( $commune['label'] ) ? $commune['label'] : '';
            $email = isset( $commune['email'] ) ? $commune['email'] : '';
            echo sprintf( "%s;%s\n", $label, $email );
        }

        exit;
    }

    /**
     * Export templates as CSV.
     */
    private static function export_templates() {
        $templates = get_option( self::TEMPLATES_OPTION, array() );

        self::send_csv_headers( 'tpmp-templates.csv' );
        echo "Titre;Catégorie;Contenu\n";

        foreach ( $templates as $template ) {
            $label    = isset( $template['label'] ) ? $template['label'] : '';
            $category = isset( $template['category'] ) ? $template['category'] : '';
            $content  = isset( $template['content'] ) ? $template['content'] : '';

            echo sprintf( "%s;%s;%s\n", $label, $category, str_replace( array( "\r", "\n" ), ' ', $content ) );
        }

        exit;
    }

    /**
     * Export forbidden words as CSV.
     */
    private static function export_forbidden_words() {
        $words = self::sanitize_forbidden_words_array( get_option( self::FORBIDDEN_WORDS_OPTION, array() ) );

        self::send_csv_headers( 'tpmp-forbidden-words.csv' );
        echo "Mot\n";

        foreach ( $words as $word ) {
            echo sprintf( "%s\n", $word );
        }

        exit;
    }

    /**
     * Export logs as CSV.
     */
    private static function export_logs() {
        global $wpdb;

        $table_name = self::get_logs_table_name();

        self::send_csv_headers( 'tpmp-logs.csv' );
        echo "ID;Date;Commune slug;Commune;Email expéditeur;IP;Statut;Message\n";

        $logs = $wpdb->get_results( "SELECT * FROM {$table_name} ORDER BY created_at DESC", ARRAY_A );

        if ( ! empty( $logs ) ) {
            $output = fopen( 'php://output', 'w' );

            foreach ( $logs as $log ) {
                // fputcsv handles escaping of delimiters and line breaks.
                fputcsv(
                    $output,
                    array(
                        $log['id'],
                        $log['created_at'],
                        $log['commune_slug'],
                        $log['commune_label'],
                        $log['sender_email'],
                        $log['sender_ip'],
                        $log['status'],
                        $log['message'],
                    ),
                    ';'
                );
            }

            fclose( $output );
        }

        exit;
    }

    /**
     * Send CSV headers.
     *
     * @param string $filename File name.
     */
    private static function send_csv_headers( $filename ) {
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
    }

    /**
     * Insert a log entry and purge records older than one year.
     *
     * @param array $data Log data.
     */
    private static function log_event( $data ) {
        global $wpdb;

        $table_name = self::get_logs_table_name();

        $wpdb->insert(
            $table_name,
            array(
                'created_at'    => current_time( 'mysql' ),
                'commune_slug'  => isset( $data['commune_slug'] ) ? $data['commune_slug'] : '',
                'commune_label' => isset( $data['commune_label'] ) ? $data['commune_label'] : '',
                'sender_email'  => isset( $data['sender_email'] ) ? $data['sender_email'] : '',
                'sender_ip'     => isset( $data['sender_ip'] ) ? $data['sender_ip'] : '',
                'message'       => isset( $data['message'] ) ? $data['message'] : '',
                'status'        => isset( $data['status'] ) ? $data['status'] : 'sent',
            ),
            array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
        );

        // Purge logs older than 1 year.
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - YEAR_IN_SECONDS );
        $wpdb->query( $wpdb->prepare( "DELETE FROM $table_name WHERE created_at < %s", $cutoff ) );
    }

    /**
     * Create or update the logs table structure.
     */
    public static function create_logs_table() {
        global $wpdb;

        $table_name      = self::get_logs_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            commune_slug VARCHAR(191) NOT NULL,
            commune_label VARCHAR(191) NOT NULL,
            sender_email VARCHAR(191) NOT NULL,
            sender_ip VARCHAR(100) NOT NULL,
            message LONGTEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'sent',
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY commune_slug (commune_slug),
            KEY status (status)
        ) {$charset_collate};";

        dbDelta( $sql );
    }

    /**
     * Get the fully-qualified logs table name.
     *
     * @return string
     */
    private static function get_logs_table_name() {
        global $wpdb;

        return $wpdb->prefix . self::LOG_TABLE;
    }

    /**
     * Read CSV file into array of rows.
     *
     * @param string $tmp_name Uploaded temporary file name.
     *
     * @return array
     */
    private static function read_csv_file( $tmp_name ) {
        $rows = array();

        if ( ! file_exists( $tmp_name ) ) {
            return $rows;
        }

        $handle = fopen( $tmp_name, 'r' );

        if ( false === $handle ) {
            return $rows;
        }

        $first_line = fgets( $handle );
        if ( false === $first_line ) {
            fclose( $handle );
            return $rows;
        }

        $delimiter = self::detect_csv_delimiter( $first_line );

        $first_row = str_getcsv( $first_line, $delimiter );
        $rows[]    = $first_row;

        while ( ( $data = fgetcsv( $handle, 0, $delimiter ) ) !== false ) {
            $rows[] = $data;
        }

        fclose( $handle );

        return $rows;
    }

    /**
     * Detect CSV delimiter from first line.
     *
     * @param string $line CSV line.
     *
     * @return string
     */
    private static function detect_csv_delimiter( $line ) {
        $semicolon_count = substr_count( $line, ';' );
        $comma_count     = substr_count( $line, ',' );

        return ( $semicolon_count >= $comma_count ) ? ';' : ',';
    }

    /**
     * Normalize text by removing accents and lowercasing.
     *
     * @param string $text Text to normalize.
     *
     * @return string
     */
    private static function normalize_text( $text ) {
        return mb_strtolower( remove_accents( $text ), 'UTF-8' );
    }

    /**
     * Slugify a string.
     *
     * @param string $text Text to slugify.
     *
     * @return string
     */
    private static function slugify( $text ) {
        $text = remove_accents( strtolower( $text ) );
        $text = preg_replace( '/[^a-z0-9\s-]/', '', $text );
        $text = preg_replace( '/[\s-]+/', '-', $text );
        $text = trim( $text, '-' );

        return $text;
    }

    /**
     * Redirect back to the settings page with stored notices.
     */
    private static function redirect_with_notices() {
        set_transient( 'settings_errors', get_settings_errors( 'tpmp_contact_maire' ), 30 );

        wp_redirect( add_query_arg( array( 'page' => 'tpmp-contact-maire-settings' ), admin_url( 'options-general.php' ) ) );
        exit;
    }

    /**
     * Render settings page content.
     */
    public static function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $communes        = get_option( self::OPTION_NAME, array() );
        $templates       = get_option( self::TEMPLATES_OPTION, array() );
        $forbidden_words = self::sanitize_forbidden_words_array( get_option( self::FORBIDDEN_WORDS_OPTION, array() ) );

        $export_communes_url = wp_nonce_url(
            add_query_arg(
                array(
                    'page'        => 'tpmp-contact-maire-settings',
                    'tpmp_export' => 'communes',
                ),
                admin_url( 'options-general.php' )
            ),
            'tpmp_contact_maire_export_communes'
        );

        $export_templates_url = wp_nonce_url(
            add_query_arg(
                array(
                    'page'        => 'tpmp-contact-maire-settings',
                    'tpmp_export' => 'templates',
                ),
                admin_url( 'options-general.php' )
            ),
            'tpmp_contact_maire_export_templates'
        );

        $export_forbidden_url = wp_nonce_url(
            add_query_arg(
                array(
                    'page'        => 'tpmp-contact-maire-settings',
                    'tpmp_export' => 'forbidden',
                ),
                admin_url( 'options-general.php' )
            ),
            'tpmp_contact_maire_export_forbidden'
        );

        $export_logs_url = wp_nonce_url(
            add_query_arg(
                array(
                    'page'        => 'tpmp-contact-maire-settings',
                    'tpmp_export' => 'logs',
                ),
                admin_url( 'options-general.php' )
            ),
            'tpmp_contact_maire_export_logs'
        );
        ?>
        <div class="wrap">
            <h1>TPMP Contact Maire</h1>
            <?php settings_errors( 'tpmp_contact_maire' ); ?>

            <h2>Communes enregistrées</h2>
            <table class="widefat fixed" cellspacing="0">
                <thead>
                    <tr>
                        <th scope="col">Slug</th>
                        <th scope="col">Nom complet</th>
                        <th scope="col">Email mairie</th>
                        <th scope="col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $communes ) ) : ?>
                        <tr>
                            <td colspan="4">Aucune commune enregistrée.</td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $communes as $slug => $data ) : ?>
                            <tr>
                                <td><?php echo esc_html( $slug ); ?></td>
                                <td><?php echo esc_html( isset( $data['label'] ) ? $data['label'] : '' ); ?></td>
                                <td><?php echo esc_html( isset( $data['email'] ) ? $data['email'] : '' ); ?></td>
                                <td>
                                    <?php
                                    $delete_url = wp_nonce_url(
                                        add_query_arg(
                                            array(
                                                'page'                      => 'tpmp-contact-maire-settings',
                                                'tpmp_contact_maire_action' => 'delete',
                                                'slug'                      => $slug,
                                            ),
                                            admin_url( 'options-general.php' )
                                        ),
                                        'tpmp_contact_maire_manage_communes'
                                    );
                                    ?>
                                    <a href="<?php echo esc_url( $delete_url ); ?>" class="button button-small">Supprimer</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
              </table>

              <p>
                  <a class="button" href="<?php echo esc_url( $export_communes_url ); ?>">Exporter les communes (CSV)</a>
              </p>

              <h3>Importer des communes</h3>
              <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'options-general.php?page=tpmp-contact-maire-settings' ) ); ?>">
                  <?php wp_nonce_field( 'tpmp_contact_maire_import_communes', 'tpmp_contact_maire_import_communes_nonce' ); ?>
                  <input type="hidden" name="tpmp_communes_csv_action" value="import" />
                  <input type="file" name="tpmp_communes_csv" accept=".csv" />
                  <?php submit_button( 'Importer les communes (CSV)', 'secondary', 'submit', false ); ?>
              </form>

              <h2>Ajouter une commune</h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'options-general.php?page=tpmp-contact-maire-settings' ) ); ?>">
                <?php wp_nonce_field( 'tpmp_contact_maire_manage_communes' ); ?>
                <input type="hidden" name="tpmp_contact_maire_action" value="add" />

                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="tpmp_commune_slug">Slug</label></th>
                            <td><input name="tpmp_commune_slug" type="text" id="tpmp_commune_slug" value="" class="regular-text" required /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="tpmp_commune_label">Nom complet</label></th>
                            <td><input name="tpmp_commune_label" type="text" id="tpmp_commune_label" value="" class="regular-text" required /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="tpmp_commune_email">Email mairie</label></th>
                            <td><input name="tpmp_commune_email" type="email" id="tpmp_commune_email" value="" class="regular-text" required /></td>
                        </tr>
                    </tbody>
                </table>

                <?php submit_button( 'Ajouter la commune' ); ?>
            </form>

            <h2>Modèles de messages</h2>
            <table class="widefat fixed" cellspacing="0">
                <thead>
                    <tr>
                        <th scope="col">ID</th>
                        <th scope="col">Titre</th>
                        <th scope="col">Catégorie</th>
                        <th scope="col">Aperçu</th>
                        <th scope="col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $templates ) ) : ?>
                        <tr>
                            <td colspan="5">Aucun modèle enregistré.</td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $templates as $template_id => $template ) : ?>
                            <tr>
                                <td><?php echo esc_html( isset( $template['id'] ) ? $template['id'] : $template_id ); ?></td>
                                <td><?php echo esc_html( isset( $template['label'] ) ? $template['label'] : '' ); ?></td>
                                <td><?php echo esc_html( isset( $template['category'] ) ? $template['category'] : '' ); ?></td>
                                <td><?php echo esc_html( isset( $template['content'] ) ? wp_html_excerpt( wp_strip_all_tags( $template['content'] ), 80, '…' ) : '' ); ?></td>
                                <td>
                                    <?php
                                    $delete_url = wp_nonce_url(
                                        add_query_arg(
                                            array(
                                                'page'                       => 'tpmp-contact-maire-settings',
                                                'tpmp_action'                => 'delete_template',
                                                'template_id'                => isset( $template['id'] ) ? $template['id'] : $template_id,
                                            ),
                                            admin_url( 'options-general.php' )
                                        ),
                                        'tpmp_contact_maire_manage_templates',
                                        'tpmp_contact_maire_templates_nonce'
                                    );
                                    ?>
                                    <a href="<?php echo esc_url( $delete_url ); ?>" class="button button-small">Supprimer</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
              </table>

              <p>
                  <a class="button" href="<?php echo esc_url( $export_templates_url ); ?>">Exporter les modèles (CSV)</a>
              </p>

              <h3>Importer des modèles</h3>
              <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'options-general.php?page=tpmp-contact-maire-settings' ) ); ?>">
                  <?php wp_nonce_field( 'tpmp_contact_maire_import_templates', 'tpmp_contact_maire_import_templates_nonce' ); ?>
                  <input type="hidden" name="tpmp_templates_csv_action" value="import" />
                  <input type="file" name="tpmp_templates_csv" accept=".csv" />
                  <?php submit_button( 'Importer les modèles (CSV)', 'secondary', 'submit', false ); ?>
              </form>

              <h2>Ajouter ou modifier un modèle</h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'options-general.php?page=tpmp-contact-maire-settings' ) ); ?>">
                <?php wp_nonce_field( 'tpmp_contact_maire_manage_templates', 'tpmp_contact_maire_templates_nonce' ); ?>
                <input type="hidden" name="tpmp_contact_maire_template_action" value="add_template" />

                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="tpmp_template_id">ID du modèle</label></th>
                            <td><input name="tpmp_template_id" type="text" id="tpmp_template_id" value="" class="regular-text" required /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="tpmp_template_label">Titre du modèle</label></th>
                            <td><input name="tpmp_template_label" type="text" id="tpmp_template_label" value="" class="regular-text" required /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="tpmp_template_category">Catégorie</label></th>
                            <td><input name="tpmp_template_category" type="text" id="tpmp_template_category" value="" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="tpmp_template_content">Contenu du message</label></th>
                            <td><textarea name="tpmp_template_content" id="tpmp_template_content" rows="5" class="large-text" required></textarea></td>
                        </tr>
                    </tbody>
                </table>

                <?php submit_button( 'Enregistrer le modèle' ); ?>
            </form>

            <h2>Mots interdits</h2>
            <p>
                <a class="button" href="<?php echo esc_url( $export_forbidden_url ); ?>">Exporter les mots interdits (CSV)</a>
            </p>

            <h3>Liste des mots interdits</h3>
            <form method="post" action="<?php echo esc_url( admin_url( 'options-general.php?page=tpmp-contact-maire-settings' ) ); ?>">
                <?php wp_nonce_field( 'tpmp_contact_maire_save_forbidden', 'tpmp_contact_maire_save_forbidden_nonce' ); ?>
                <input type="hidden" name="tpmp_forbidden_action" value="save" />
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="tpmp_forbidden_words">Mots interdits (un par ligne)</label></th>
                            <td>
                                <textarea name="tpmp_forbidden_words" id="tpmp_forbidden_words" rows="6" class="large-text"><?php echo esc_textarea( implode( "\n", $forbidden_words ) ); ?></textarea>
                                <p class="description">
                                    <?php
                                    $forbidden_count = count( $forbidden_words );
                                    $forbidden_list  = implode( ', ', $forbidden_words );
                                    ?>
                                    <strong>Nombre de mots enregistrés : <?php echo esc_html( $forbidden_count ); ?></strong>
                                    <?php if ( $forbidden_count > 0 ) : ?>
                                        <br />
                                        <small title="<?php echo esc_attr( $forbidden_list ); ?>">Liste actuelle : <?php echo esc_html( $forbidden_list ); ?></small>
                                    <?php endif; ?>
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button( 'Enregistrer les mots interdits' ); ?>
            </form>

            <h3>Importer des mots interdits</h3>
            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'options-general.php?page=tpmp-contact-maire-settings' ) ); ?>">
                <?php wp_nonce_field( 'tpmp_contact_maire_import_forbidden', 'tpmp_contact_maire_import_forbidden_nonce' ); ?>
                <input type="hidden" name="tpmp_forbidden_action" value="import" />
                <input type="file" name="tpmp_forbidden_csv" accept=".csv" />
                <?php submit_button( 'Importer les mots (CSV)', 'secondary', 'submit', false ); ?>
            </form>

            <h3>Journal des envois (non visible)</h3>
            <p>Les logs ne sont pas affichés ici. Vous pouvez les exporter en CSV en cas de contrôle. Conservation maximale : 1 an.</p>
            <p>
                <a class="button" href="<?php echo esc_url( $export_logs_url ); ?>">Exporter les logs (CSV)</a>
            </p>
        </div>
        <?php
    }
}

TPMP_Contact_Maire::init();

/**
 * Initialize plugin options on activation.
 */
function tpmp_contact_maire_activate() {
    $default_communes = array(
        'paris'     => array(
            'label' => 'Paris',
            'email' => 'mairie-paris@example.com',
        ),
        'marseille' => array(
            'label' => 'Marseille',
            'email' => 'mairie-marseille@example.com',
        ),
        'lyon'      => array(
            'label' => 'Lyon',
            'email' => 'mairie-lyon@example.com',
        ),
    );

    $default_templates = 
array(
    array(
        'id'       => 'engagement-municipal-1',
        'label'    => 'Engagement municipal - Bonjour, en vue des prochaines élections municipales, j’aimerais conna…',
        'category' => 'Engagement municipal',
        'content'  => 'Bonjour, en vue des prochaines élections municipales, j’aimerais connaître votre engagement sur la question du retour du ramassage des ordures en porte-à-porte. Comptez-vous défendre cette demande auprès du Smicval ? Merci d’avance pour votre réponse.',
    ),
    array(
        'id'       => 'engagement-municipal-2',
        'label'    => 'Engagement municipal - Bonjour, la gestion des déchets est devenue un sujet central dans notr…',
        'category' => 'Engagement municipal',
        'content'  => 'Bonjour, la gestion des déchets est devenue un sujet central dans notre commune. Pensez-vous inclure dans votre programme électoral une consultation citoyenne sur la réforme Néo Smicval ? Cordialement.',
    ),
    array(
        'id'       => 'engagement-municipal-3',
        'label'    => 'Engagement municipal - Bonjour, beaucoup d’habitants espèrent un rétablissement de la collect…',
        'category' => 'Engagement municipal',
        'content'  => 'Bonjour, beaucoup d’habitants espèrent un rétablissement de la collecte des ordures à domicile. Allez-vous porter cette revendication lors de la prochaine campagne municipale ? Merci de votre écoute.',
    ),
    array(
        'id'       => 'engagement-municipal-4',
        'label'    => 'Engagement municipal - Bonjour, face au mécontentement général lié à la suppression du porte-…',
        'category' => 'Engagement municipal',
        'content'  => 'Bonjour, face au mécontentement général lié à la suppression du porte-à-porte, pouvez-vous nous dire quelles actions concrètes vous prendrez si vous êtes réélu(e) pour défendre le retour d’un service de proximité ? Cordialement.',
    ),
    array(
        'id'       => 'engagement-municipal-5',
        'label'    => 'Engagement municipal - Bonjour, j’aimerais que la question de la gestion des déchets soit au …',
        'category' => 'Engagement municipal',
        'content'  => 'Bonjour, j’aimerais que la question de la gestion des déchets soit au cœur des débats des prochaines municipales. Serait-il possible d’organiser une réunion publique pour échanger sur vos engagements à ce sujet ? Merci d’avance.',
    ),
    array(
        'id'       => 'engagement-municipal-6',
        'label'    => 'Engagement municipal - Bonjour, de nombreux administrés se sentent abandonnés par la réforme …',
        'category' => 'Engagement municipal',
        'content'  => 'Bonjour, de nombreux administrés se sentent abandonnés par la réforme Néo Smicval. Serez-vous prêt à engager un recours ou à soutenir collectivement les communes opposées au système des points d’apport volontaire ? Cordialement.',
    ),
    array(
        'id'       => 'engagement-municipal-7',
        'label'    => 'Engagement municipal - Bonjour, la suppression du ramassage en porte-à-porte pénalise surtout…',
        'category' => 'Engagement municipal',
        'content'  => 'Bonjour, la suppression du ramassage en porte-à-porte pénalise surtout les plus fragiles. Comptez-vous défendre leur cause dans votre programme municipal et demander des aménagements pour eux ? Merci pour votre attention.',
    ),
    array(
        'id'       => 'engagement-municipal-8',
        'label'    => 'Engagement municipal - Bonjour, je souhaite savoir si, dans le cadre des prochaines élections…',
        'category' => 'Engagement municipal',
        'content'  => 'Bonjour, je souhaite savoir si, dans le cadre des prochaines élections, vous prendrez l’engagement public de ne plus soutenir la collecte 100 % en PAV et de vous battre pour des solutions adaptées à notre commune. Cordialement.',
    ),
    array(
        'id'       => 'engagement-municipal-9',
        'label'    => 'Engagement municipal - Bonjour, pour beaucoup d’entre nous, la réforme a dégradé le service p…',
        'category' => 'Engagement municipal',
        'content'  => 'Bonjour, pour beaucoup d’entre nous, la réforme a dégradé le service public et notre cadre de vie. Quels engagements précis comptez-vous prendre devant les électeurs pour améliorer la situation ? Merci d’avance.',
    ),
    array(
        'id'       => 'engagement-municipal-10',
        'label'    => 'Engagement municipal - Bonjour, la question du mode de collecte des ordures est déterminante …',
        'category' => 'Engagement municipal',
        'content'  => 'Bonjour, la question du mode de collecte des ordures est déterminante pour mon vote. Êtes-vous prêt à consulter régulièrement les habitants et à défendre leurs intérêts sur ce sujet essentiel lors de votre prochain mandat ? Cordialement.',
    ),
    array(
        'id'       => 'parents-familles-1',
        'label'    => 'Parents / familles - Bonjour, en tant que parent de jeunes enfants, je trouve difficile de …',
        'category' => 'Parents / familles',
        'content'  => 'Bonjour, en tant que parent de jeunes enfants, je trouve difficile de gérer les trajets vers le point d’apport volontaire avec des poussettes ou des enfants en bas âge. La mairie prévoit-elle des facilités pour les familles ? Merci d’avance pour votre compréhension.',
    ),
    array(
        'id'       => 'parents-familles-2',
        'label'    => 'Parents / familles - Bonjour, avec une famille nombreuse, la quantité de déchets à transpor…',
        'category' => 'Parents / familles',
        'content'  => 'Bonjour, avec une famille nombreuse, la quantité de déchets à transporter jusqu’aux bornes devient compliquée à gérer chaque semaine. Des solutions spécifiques sont-elles envisagées pour les foyers avec plusieurs enfants ? Cordialement.',
    ),
    array(
        'id'       => 'parents-familles-3',
        'label'    => 'Parents / familles - Bonjour, gérer les allers-retours vers le point d’apport volontaire to…',
        'category' => 'Parents / familles',
        'content'  => 'Bonjour, gérer les allers-retours vers le point d’apport volontaire tout en surveillant mes enfants est compliqué, surtout lorsque les accès ne sont pas sécurisés. La mairie peut-elle prévoir des aménagements ou une aide pour les familles ? Merci d’avance.',
    ),
    array(
        'id'       => 'parents-familles-4',
        'label'    => 'Parents / familles - Bonjour, le volume de déchets lié à la vie de famille nécessite plusie…',
        'category' => 'Parents / familles',
        'content'  => 'Bonjour, le volume de déchets lié à la vie de famille nécessite plusieurs déplacements par semaine jusqu’aux bornes. Un dispositif de ramassage ou de points de collecte temporaires pour les familles serait-il envisageable ? Cordialement.',
    ),
    array(
        'id'       => 'personnes-agees-1',
        'label'    => 'Personnes âgées - Bonjour, étant une personne âgée, j’ai de plus en plus de mal à transp…',
        'category' => 'Personnes âgées',
        'content'  => 'Bonjour, étant une personne âgée, j’ai de plus en plus de mal à transporter mes sacs jusqu’aux points d’apport volontaire éloignés. La commune propose-t-elle une aide pour les aînés dans cette situation ? Merci pour votre attention.',
    ),
    array(
        'id'       => 'personnes-agees-2',
        'label'    => 'Personnes âgées - Bonjour, la distance et la pénibilité du transport de déchets à mon âg…',
        'category' => 'Personnes âgées',
        'content'  => 'Bonjour, la distance et la pénibilité du transport de déchets à mon âge me découragent. Envisagez-vous un retour du ramassage à domicile pour les seniors isolés ? Cordialement.',
    ),
    array(
        'id'       => 'personnes-agees-3',
        'label'    => 'Personnes âgées - Bonjour, je souhaite signaler que l’état de santé de nombreux seniors …',
        'category' => 'Personnes âgées',
        'content'  => 'Bonjour, je souhaite signaler que l’état de santé de nombreux seniors du village ne leur permet pas de transporter des déchets sur de longues distances. La mairie propose-t-elle une aide ponctuelle ou un service de ramassage ciblé ? Merci pour votre retour.',
    ),
    array(
        'id'       => 'personnes-agees-4',
        'label'    => 'Personnes âgées - Bonjour, avec l’âge, il devient difficile de soulever les sacs pour le…',
        'category' => 'Personnes âgées',
        'content'  => 'Bonjour, avec l’âge, il devient difficile de soulever les sacs pour les mettre dans les bornes en hauteur. Prévoyez-vous d’installer des équipements plus accessibles pour les personnes âgées ? Cordialement.',
    ),
    array(
        'id'       => 'commercants-artisans-1',
        'label'    => 'Commerçants / artisans - Bonjour, en tant que commerçant, la gestion des déchets professionnels…',
        'category' => 'Commerçants / artisans',
        'content'  => 'Bonjour, en tant que commerçant, la gestion des déchets professionnels est devenue plus complexe avec la suppression du porte-à-porte. Existe-t-il une collecte adaptée ou des créneaux réservés pour les commerces ? Merci pour votre retour.',
    ),
    array(
        'id'       => 'commercants-artisans-2',
        'label'    => 'Commerçants / artisans - Bonjour, la multiplication des dépôts sauvages nuit à l’image de mon c…',
        'category' => 'Commerçants / artisans',
        'content'  => 'Bonjour, la multiplication des dépôts sauvages nuit à l’image de mon commerce situé près d’un point d’apport volontaire. La mairie compte-t-elle renforcer la surveillance ou le nettoyage dans ces zones ? Cordialement.',
    ),
    array(
        'id'       => 'commercants-artisans-3',
        'label'    => 'Commerçants / artisans - Bonjour, les nouveaux horaires et l’accessibilité des points d’apport …',
        'category' => 'Commerçants / artisans',
        'content'  => 'Bonjour, les nouveaux horaires et l’accessibilité des points d’apport volontaire ne sont pas toujours compatibles avec mon activité commerciale. Une adaptation pour les professionnels du centre-ville est-elle à l’étude ? Merci d’avance.',
    ),
    array(
        'id'       => 'commercants-artisans-4',
        'label'    => 'Commerçants / artisans - Bonjour, en tant qu’artisan, j’ai besoin d’un espace de dépôt pour mes…',
        'category' => 'Commerçants / artisans',
        'content'  => 'Bonjour, en tant qu’artisan, j’ai besoin d’un espace de dépôt pour mes déchets professionnels qui soit à la fois proche et sécurisé. Existe-t-il des solutions spécifiques pour les professionnels ? Cordialement.',
    ),
    array(
        'id'       => 'personnes-de-petite-taille-1',
        'label'    => 'Personnes de petite taille - Bonjour, je rencontre des difficultés pour utiliser les bornes d’appor…',
        'category' => 'Personnes de petite taille',
        'content'  => 'Bonjour, je rencontre des difficultés pour utiliser les bornes d’apport volontaire dont les trappes sont placées en hauteur, étant de petite taille. Un aménagement est-il prévu pour faciliter l’accès à tous ? Merci d’avance.',
    ),
    array(
        'id'       => 'personnes-de-petite-taille-2',
        'label'    => 'Personnes de petite taille - Bonjour, l’ergonomie des bornes actuelles ne permet pas à toutes les p…',
        'category' => 'Personnes de petite taille',
        'content'  => 'Bonjour, l’ergonomie des bornes actuelles ne permet pas à toutes les personnes, notamment celles de petite taille, d’utiliser le service dans de bonnes conditions. Envisagez-vous des adaptations ? Cordialement.',
    ),
    array(
        'id'       => 'personnes-de-petite-taille-3',
        'label'    => 'Personnes de petite taille - Bonjour, étant de petite taille, il m’est difficile d’utiliser les tra…',
        'category' => 'Personnes de petite taille',
        'content'  => 'Bonjour, étant de petite taille, il m’est difficile d’utiliser les trappes situées trop haut sur les bornes d’apport volontaire. Est-il prévu d’aménager certains points avec des dispositifs accessibles à tous ? Merci pour votre attention.',
    ),
    array(
        'id'       => 'personnes-de-petite-taille-4',
        'label'    => 'Personnes de petite taille - Bonjour, l’accès aux bornes pose problème non seulement en hauteur mai…',
        'category' => 'Personnes de petite taille',
        'content'  => 'Bonjour, l’accès aux bornes pose problème non seulement en hauteur mais aussi en termes de marche ou de manœuvre pour les personnes ayant des difficultés physiques. Des aménagements sont-ils prévus pour améliorer l’accessibilité ? Cordialement.',
    ),
    array(
        'id'       => 'personnes-malades-fragiles-handicap-non-reconnu-1',
        'label'    => 'Personnes malades / fragiles / handicap non reconnu - Bonjour, ma maladie chronique limite mes déplacements et je n’ai pas d…',
        'category' => 'Personnes malades / fragiles / handicap non reconnu',
        'content'  => 'Bonjour, ma maladie chronique limite mes déplacements et je n’ai pas droit à une assistance officielle pour la collecte des déchets. La commune prévoit-elle des solutions d’entraide ou des dispositifs solidaires ? Merci pour votre écoute.',
    ),
    array(
        'id'       => 'personnes-malades-fragiles-handicap-non-reconnu-2',
        'label'    => 'Personnes malades / fragiles / handicap non reconnu - Bonjour, bien que je ne bénéficie pas d’une reconnaissance de handicap…',
        'category' => 'Personnes malades / fragiles / handicap non reconnu',
        'content'  => 'Bonjour, bien que je ne bénéficie pas d’une reconnaissance de handicap, mon état de santé rend le transport des déchets très difficile. Serait-il possible de bénéficier d’un accompagnement spécifique ? Cordialement.',
    ),
    array(
        'id'       => 'personnes-malades-fragiles-handicap-non-reconnu-3',
        'label'    => 'Personnes malades / fragiles / handicap non reconnu - Bonjour, malgré mes difficultés de santé, je ne peux bénéficier d’aucu…',
        'category' => 'Personnes malades / fragiles / handicap non reconnu',
        'content'  => 'Bonjour, malgré mes difficultés de santé, je ne peux bénéficier d’aucune dérogation officielle pour la collecte des déchets. La commune prévoit-elle un dispositif d’entraide ou de volontariat pour les habitants en situation fragile ? Merci d’avance.',
    ),
    array(
        'id'       => 'personnes-malades-fragiles-handicap-non-reconnu-4',
        'label'    => 'Personnes malades / fragiles / handicap non reconnu - Bonjour, mon état de santé limite fortement mes capacités physiques sa…',
        'category' => 'Personnes malades / fragiles / handicap non reconnu',
        'content'  => 'Bonjour, mon état de santé limite fortement mes capacités physiques sans pour autant être reconnu administrativement comme handicapé. Serait-il envisageable de mettre en place une aide ponctuelle ou des collectes solidaires ? Cordialement.',
    ),
    array(
        'id'       => 'general-1',
        'label'    => 'Général - Bonjour, j’aimerais savoir comment la commune prend en compte le mécon…',
        'category' => 'Général',
        'content'  => 'Bonjour, j’aimerais savoir comment la commune prend en compte le mécontentement général concernant la réforme Néo Smicval et la suppression du ramassage des ordures en porte-à-porte, qui pénalise de nombreux habitants. Merci par avance pour votre réponse.',
    ),
    array(
        'id'       => 'general-2',
        'label'    => 'Général - Bonjour, je rencontre d’importantes difficultés pour accéder au point …',
        'category' => 'Général',
        'content'  => 'Bonjour, je rencontre d’importantes difficultés pour accéder au point d’apport volontaire, la distance et le transport des déchets étant problématiques pour moi. Envisagez-vous des solutions concrètes pour les personnes isolées ou sans véhicule ? Cordialement.',
    ),
    array(
        'id'       => 'general-3',
        'label'    => 'Général - Bonjour, la généralisation des points d’apport volontaire a entraîné u…',
        'category' => 'Général',
        'content'  => 'Bonjour, la généralisation des points d’apport volontaire a entraîné une dégradation visible de la propreté autour de chez moi (dépôts sauvages, nuisibles, odeurs). Quelles actions la mairie prévoit-elle pour y remédier ? Merci pour votre attention.',
    ),
    array(
        'id'       => 'general-4',
        'label'    => 'Général - Bonjour, je souhaite exprimer mon incompréhension face au maintien de …',
        'category' => 'Général',
        'content'  => 'Bonjour, je souhaite exprimer mon incompréhension face au maintien de la taxe d’enlèvement des ordures, alors que le service rendu aux habitants est fortement réduit et que chacun doit se déplacer lui-même aux bornes. Merci de bien vouloir m’éclairer.',
    ),
    array(
        'id'       => 'general-5',
        'label'    => 'Général - Bonjour, la suppression de la collecte porte-à-porte a rendu la gestio…',
        'category' => 'Général',
        'content'  => 'Bonjour, la suppression de la collecte porte-à-porte a rendu la gestion des déchets très compliquée pour les personnes âgées, handicapées ou sans moyen de transport. Quelles mesures spécifiques la commune compte-t-elle mettre en place pour garantir l’égalité d’accès au service public ? Cordialement.',
    ),
    array(
        'id'       => 'general-6',
        'label'    => 'Général - Bonjour, depuis la réforme, je constate de nombreux dépôts sauvages au…',
        'category' => 'Général',
        'content'  => 'Bonjour, depuis la réforme, je constate de nombreux dépôts sauvages aux abords des bornes et une insalubrité croissante dans le quartier. Serait-il possible d’intensifier le nettoyage ou d’augmenter la fréquence de collecte ? Merci pour votre écoute.',
    ),
    array(
        'id'       => 'general-7',
        'label'    => 'Général - Bonjour, je souhaite suggérer la mise en place d’un système d’aide pou…',
        'category' => 'Général',
        'content'  => 'Bonjour, je souhaite suggérer la mise en place d’un système d’aide pour les habitants qui ne peuvent pas se rendre seuls aux points d’apport volontaire, afin d’éviter l’accumulation des déchets à domicile. Merci d’avance pour votre prise en compte.',
    ),
    array(
        'id'       => 'general-8',
        'label'    => 'Général - Bonjour, je regrette l’absence de concertation avec les habitants avan…',
        'category' => 'Général',
        'content'  => 'Bonjour, je regrette l’absence de concertation avec les habitants avant la généralisation des PAV. Serait-il possible d’organiser une réunion publique ou une consultation citoyenne pour discuter de solutions alternatives ? Cordialement.',
    ),
    array(
        'id'       => 'general-9',
        'label'    => 'Général - Bonjour, certains points d’apport volontaire de la commune sont réguli…',
        'category' => 'Général',
        'content'  => 'Bonjour, certains points d’apport volontaire de la commune sont régulièrement saturés ou difficiles d’accès (trappes en hauteur, stationnement limité). Pourriez-vous envisager des aménagements pour améliorer leur utilisation ? Merci d’avance.',
    ),
    array(
        'id'       => 'general-10',
        'label'    => 'Général - Bonjour, je comprends la nécessité de moderniser la gestion des déchet…',
        'category' => 'Général',
        'content'  => 'Bonjour, je comprends la nécessité de moderniser la gestion des déchets, mais la réforme Néo Smicval semble accentuer les inégalités et la précarité pour certains foyers. La mairie envisage-t-elle de demander un retour, même partiel, à la collecte en porte-à-porte ? Cordialement.',
    ),
    array(
        'id'       => 'general-11',
        'label'    => 'Général - Bonjour, suite à la mise en place des points d’apport volontaire, je t…',
        'category' => 'Général',
        'content'  => 'Bonjour, suite à la mise en place des points d’apport volontaire, je trouve que l’effort demandé aux habitants n’est pas adapté à la réalité de nos communes rurales. Prévoyez-vous un accompagnement pour ceux qui peinent à suivre ce nouveau fonctionnement ? Merci pour votre retour.',
    ),
    array(
        'id'       => 'general-12',
        'label'    => 'Général - Bonjour, habitant à plus d’un kilomètre du point d’apport volontaire l…',
        'category' => 'Général',
        'content'  => 'Bonjour, habitant à plus d’un kilomètre du point d’apport volontaire le plus proche et n’ayant pas de véhicule, je me sens lésé par la réforme. Quelles alternatives proposez-vous pour les personnes dans mon cas ? Cordialement.',
    ),
    array(
        'id'       => 'general-13',
        'label'    => 'Général - Bonjour, la multiplication des dépôts sauvages depuis la suppression d…',
        'category' => 'Général',
        'content'  => 'Bonjour, la multiplication des dépôts sauvages depuis la suppression du porte-à-porte nuit à la propreté et à la sécurité de notre commune. Envisagez-vous de renforcer la surveillance ou de prendre d’autres mesures dissuasives ? Merci d’avance.',
    ),
    array(
        'id'       => 'general-14',
        'label'    => 'Général - Bonjour, je souhaiterais savoir si une réévaluation de la réforme Néo …',
        'category' => 'Général',
        'content'  => 'Bonjour, je souhaiterais savoir si une réévaluation de la réforme Néo Smicval est envisagée, au vu du nombre de plaintes et du malaise exprimé par les habitants et les élus. Merci de m’informer sur la position de la commune.',
    ),
    array(
        'id'       => 'general-15',
        'label'    => 'Général - Bonjour, pourriez-vous expliquer comment la mairie assure l’équité ent…',
        'category' => 'Général',
        'content'  => 'Bonjour, pourriez-vous expliquer comment la mairie assure l’équité entre les habitants dans l’accès au service de collecte, notamment pour les personnes vulnérables ou vivant en zone isolée ? Merci pour votre attention.',
    ),
    array(
        'id'       => 'general-16',
        'label'    => 'Général - Bonjour, depuis l’instauration des PAV, je constate que la collecte n’…',
        'category' => 'Général',
        'content'  => 'Bonjour, depuis l’instauration des PAV, je constate que la collecte n’est pas toujours assez fréquente, ce qui provoque des débordements et des nuisances. Prévoyez-vous d’ajuster la fréquence de collecte ? Cordialement.',
    ),
    array(
        'id'       => 'general-17',
        'label'    => 'Général - Bonjour, le nouveau système d’apport volontaire n’est pas adapté à tou…',
        'category' => 'Général',
        'content'  => 'Bonjour, le nouveau système d’apport volontaire n’est pas adapté à tous, notamment aux familles nombreuses qui produisent plus de déchets. Des solutions spécifiques sont-elles à l’étude pour ces situations ? Merci pour votre écoute.',
    ),
    array(
        'id'       => 'general-18',
        'label'    => 'Général - Bonjour, la fermeture ou le verrouillage des bornes pose parfois probl…',
        'category' => 'Général',
        'content'  => 'Bonjour, la fermeture ou le verrouillage des bornes pose parfois problème pour déposer les déchets, notamment pour les personnes âgées. Est-il prévu d’améliorer l’accessibilité et la facilité d’usage de ces équipements ? Merci d’avance.',
    ),
    array(
        'id'       => 'general-19',
        'label'    => 'Général - Bonjour, je vous fais part de mon inquiétude concernant la proliférati…',
        'category' => 'Général',
        'content'  => 'Bonjour, je vous fais part de mon inquiétude concernant la prolifération de nuisibles près des points d’apport volontaire et la dégradation de l’environnement local. Quelles solutions la commune peut-elle proposer ? Cordialement.',
    ),
    array(
        'id'       => 'general-20',
        'label'    => 'Général - Bonjour, j’aimerais savoir si la commune va soutenir le recours collec…',
        'category' => 'Général',
        'content'  => 'Bonjour, j’aimerais savoir si la commune va soutenir le recours collectif initié par plusieurs maires contre la réforme Néo Smicval, afin de défendre les intérêts des habitants. Merci de bien vouloir me tenir informé(e).',
    ),
    array(
        'id'       => 'general-21',
        'label'    => 'Général - Bonjour, depuis la mise en place des points d’apport volontaire, la ge…',
        'category' => 'Général',
        'content'  => 'Bonjour, depuis la mise en place des points d’apport volontaire, la gestion de mes déchets est devenue très contraignante, surtout lors des intempéries. La mairie prévoit-elle des abris ou protections pour faciliter l’accès aux bornes ? Merci pour votre retour.',
    ),
    array(
        'id'       => 'general-22',
        'label'    => 'Général - Bonjour, je constate que certains habitants laissent désormais leurs d…',
        'category' => 'Général',
        'content'  => 'Bonjour, je constate que certains habitants laissent désormais leurs déchets au pied des bornes, ce qui dégrade l’image et la propreté de notre village. Quelles mesures comptez-vous prendre pour sensibiliser et responsabiliser les usagers ? Cordialement.',
    ),
    array(
        'id'       => 'general-23',
        'label'    => 'Général - Bonjour, la distance et l’absence de trottoirs sécurisés pour accéder …',
        'category' => 'Général',
        'content'  => 'Bonjour, la distance et l’absence de trottoirs sécurisés pour accéder aux points d’apport volontaire rendent les trajets dangereux, notamment pour les enfants et les personnes âgées. Envisagez-vous des aménagements pour sécuriser ces accès ? Merci par avance.',
    ),
    array(
        'id'       => 'general-24',
        'label'    => 'Général - Bonjour, la réforme Néo Smicval a été décidée sans réelle concertation…',
        'category' => 'Général',
        'content'  => 'Bonjour, la réforme Néo Smicval a été décidée sans réelle concertation locale. Pensez-vous organiser prochainement une consultation citoyenne pour recueillir nos avis et besoins ? Cordialement.',
    ),
    array(
        'id'       => 'general-25',
        'label'    => 'Général - Bonjour, je souhaiterais obtenir un rendez-vous ou participer à une ré…',
        'category' => 'Général',
        'content'  => 'Bonjour, je souhaiterais obtenir un rendez-vous ou participer à une réunion pour discuter des difficultés rencontrées avec la nouvelle collecte des ordures. Merci de bien vouloir m’indiquer les prochaines dates prévues.',
    ),
    array(
        'id'       => 'general-26',
        'label'    => 'Général - Bonjour, l’absence de service de collecte à domicile est particulièrem…',
        'category' => 'Général',
        'content'  => 'Bonjour, l’absence de service de collecte à domicile est particulièrement pénalisante pour les personnes en situation de handicap. La commune va-t-elle élargir les critères d’assistance ou proposer des solutions de proximité ? Merci pour votre écoute.',
    ),
    array(
        'id'       => 'general-27',
        'label'    => 'Général - Bonjour, je vous sollicite afin de connaître les démarches pour demand…',
        'category' => 'Général',
        'content'  => 'Bonjour, je vous sollicite afin de connaître les démarches pour demander une dérogation ou une aide au transport de mes déchets jusqu’aux points d’apport volontaire. Merci d’avance pour votre aide.',
    ),
    array(
        'id'       => 'general-28',
        'label'    => 'Général - Bonjour, il serait utile d’installer une signalétique claire autour de…',
        'category' => 'Général',
        'content'  => 'Bonjour, il serait utile d’installer une signalétique claire autour des points d’apport volontaire pour rappeler les bonnes pratiques et éviter les erreurs de tri ou les incivilités. Envisagez-vous ce type d’action ? Cordialement.',
    ),
    array(
        'id'       => 'general-29',
        'label'    => 'Général - Bonjour, je regrette le manque de communication sur la fréquence de co…',
        'category' => 'Général',
        'content'  => 'Bonjour, je regrette le manque de communication sur la fréquence de collecte et la gestion des débordements aux bornes. Serait-il possible d’informer régulièrement les habitants sur ces sujets ? Merci pour votre attention.',
    ),
    array(
        'id'       => 'general-30',
        'label'    => 'Général - Bonjour, la situation actuelle engendre beaucoup de tensions dans la c…',
        'category' => 'Général',
        'content'  => 'Bonjour, la situation actuelle engendre beaucoup de tensions dans la commune. Quelles démarches la mairie entreprend-elle pour relayer nos difficultés auprès du Smicval et faire évoluer la réforme ? Cordialement.',
    ),
);

    if ( null === get_option( TPMP_Contact_Maire::OPTION_NAME, null ) ) {
        update_option( TPMP_Contact_Maire::OPTION_NAME, $default_communes );
    }

    if ( null === get_option( TPMP_Contact_Maire::TEMPLATES_OPTION, null ) ) {
        update_option( TPMP_Contact_Maire::TEMPLATES_OPTION, $default_templates );
    }

    if ( null === get_option( TPMP_Contact_Maire::FORBIDDEN_WORDS_OPTION, null ) ) {
        update_option( TPMP_Contact_Maire::FORBIDDEN_WORDS_OPTION, array() );
    }

    TPMP_Contact_Maire::create_logs_table();
}

register_activation_hook( __FILE__, 'tpmp_contact_maire_activate' );
