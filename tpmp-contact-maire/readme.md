# TPMP Contact Maire

Plugin WordPress permettant d'afficher un formulaire via le shortcode `[tpmp_contact_maire]` pour envoyer un message à une mairie.

## Installation
1. Zippez le dossier `tpmp-contact-maire` ou copiez-le dans le dossier `wp-content/plugins/` de votre site.
2. Activez le plugin depuis l'administration WordPress.
3. Ajoutez le shortcode `[tpmp_contact_maire]` dans une page ou un article.

## Fonctionnement
- Le shortcode génère un conteneur qui accueille le formulaire rendu par le script `assets/js/app.js`.
- Les styles de base sont définis dans `assets/css/app.css`.
- L'envoi s'effectue via AJAX vers `admin-ajax.php` avec vérification d'un nonce.
- Les adresses email des mairies sont pour l'instant définies en dur dans `tpmp-contact-maire.php`.

## Personnalisation
- Ajoutez ou modifiez les communes dans le tableau `$mairies` ainsi que dans la liste localisée `$communes` dans `tpmp-contact-maire.php`.

## Auteur
Christian Auzolat
