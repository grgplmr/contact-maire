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
- La liste des communes est gérée depuis la page de réglages **Réglages > TPMP Contact Maire**. Vous pouvez y ajouter ou supprimer des communes en indiquant leur slug, nom complet et email mairie.
- Des modèles de messages sont disponibles et peuvent être ajoutés/supprimés depuis la même page de réglages (section "Modèles de messages"). Les visiteurs peuvent sélectionner un modèle pour pré-remplir leur message.

## Auteur
Christian Auzolat
