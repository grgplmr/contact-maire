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

        $mairies = get_option( self::OPTION_NAME, array() );

        if ( ! isset( $mairies[ $commune ] ) ) {
            self::send_json_error( 'Commune inconnue.' );
        }

        $commune_label = isset( $mairies[ $commune ]['label'] ) ? $mairies[ $commune ]['label'] : ucfirst( $commune );
        $to            = isset( $mairies[ $commune ]['email'] ) ? $mairies[ $commune ]['email'] : '';

        if ( empty( $to ) || ! is_email( $to ) ) {
            self::send_json_error( 'Email de la mairie invalide.' );
        }

        $subject = sprintf( 'Message TPMP pour la mairie de %s', $commune_label );

        $body_parts = array(
            sprintf( 'Commune : %s', $commune_label ),
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
        if ( ! isset( $_REQUEST['tpmp_contact_maire_action'] ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        check_admin_referer( 'tpmp_contact_maire_manage_communes' );

        $action   = sanitize_text_field( wp_unslash( $_REQUEST['tpmp_contact_maire_action'] ) );
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

        $communes = get_option( self::OPTION_NAME, array() );
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

    if ( ! get_option( TPMP_Contact_Maire::OPTION_NAME ) ) {
        add_option( TPMP_Contact_Maire::OPTION_NAME, $default_communes );
    }
}

register_activation_hook( __FILE__, 'tpmp_contact_maire_activate' );
