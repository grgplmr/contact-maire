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

        if ( isset( $_REQUEST['tpmp_contact_maire_action'] ) ) {
            self::handle_commune_submission();
            return;
        }

        if ( isset( $_REQUEST['tpmp_contact_maire_template_action'] ) || ( isset( $_GET['tpmp_action'] ) && 'delete_template' === $_GET['tpmp_action'] ) ) {
            self::handle_template_submission();
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

        $communes = get_option( self::OPTION_NAME, array() );
        $templates = get_option( self::TEMPLATES_OPTION, array() );
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

    $default_templates = array(
        'modele_general_1' => array(
            'id'       => 'modele_general_1',
            'label'    => 'Message général - inquiétudes sur la réforme',
            'category' => 'Général',
            'content'  => "Bonjour,\n\nEn tant qu'habitant de la commune, je souhaite partager mes inquiétudes concernant la réforme en cours. Pourriez-vous m'indiquer comment la mairie accompagne les habitants et quelles informations seront diffusées ?\n\nMerci pour votre retour,\nCordialement,",
        ),
        'personnes_agees_1' => array(
            'id'       => 'personnes_agees_1',
            'label'    => 'Soutien aux personnes âgées',
            'category' => 'Solidarité',
            'content'  => "Madame, Monsieur,\n\nJe souhaite attirer votre attention sur la situation des personnes âgées isolées de notre commune. Serait-il possible de renforcer les dispositifs d'accompagnement ou d'organiser une réunion d'information ?\n\nMerci par avance,\nBien cordialement,",
        ),
        'transport_urbain_1' => array(
            'id'       => 'transport_urbain_1',
            'label'    => 'Mobilité et transports',
            'category' => 'Mobilité',
            'content'  => "Bonjour,\n\nLes questions de mobilité sont au cœur du quotidien des habitants. Pourriez-vous préciser les prochaines étapes concernant les transports en commun et les aménagements cyclables prévus ?\n\nMerci pour votre implication,\nCordialement,",
        ),
    );

    if ( ! get_option( TPMP_Contact_Maire::OPTION_NAME ) ) {
        add_option( TPMP_Contact_Maire::OPTION_NAME, $default_communes );
    }

    if ( ! get_option( TPMP_Contact_Maire::TEMPLATES_OPTION ) ) {
        add_option( TPMP_Contact_Maire::TEMPLATES_OPTION, $default_templates );
    }
}

register_activation_hook( __FILE__, 'tpmp_contact_maire_activate' );
