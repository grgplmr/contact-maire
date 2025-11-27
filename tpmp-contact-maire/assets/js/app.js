(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    function renderForm(root, communes) {
        var formContainer = document.createElement('div');
        formContainer.className = 'tpmp-contact-maire';

        var title = document.createElement('h3');
        title.textContent = 'Contacter votre mairie';
        formContainer.appendChild(title);

        var form = document.createElement('form');
        form.className = 'tpmp-contact-maire__form';

        // Commune select.
        var communeField = document.createElement('div');
        communeField.className = 'tpmp-contact-maire__field';

        var communeLabel = document.createElement('label');
        communeLabel.textContent = 'Commune';
        communeLabel.setAttribute('for', 'tpmp-commune');
        communeField.appendChild(communeLabel);

        var select = document.createElement('select');
        select.name = 'commune';
        select.id = 'tpmp-commune';

        var defaultOption = document.createElement('option');
        defaultOption.value = '';
        defaultOption.textContent = 'Choisissez votre commune';
        select.appendChild(defaultOption);

        communes.forEach(function (commune) {
            var option = document.createElement('option');
            option.value = commune.slug;
            option.textContent = commune.label;
            select.appendChild(option);
        });

        communeField.appendChild(select);
        form.appendChild(communeField);

        // Email input.
        var emailField = document.createElement('div');
        emailField.className = 'tpmp-contact-maire__field';

        var emailLabel = document.createElement('label');
        emailLabel.textContent = 'Votre email';
        emailLabel.setAttribute('for', 'tpmp-email');
        emailField.appendChild(emailLabel);

        var emailInput = document.createElement('input');
        emailInput.type = 'email';
        emailInput.name = 'email';
        emailInput.id = 'tpmp-email';
        emailInput.placeholder = 'votre@email.com';
        emailField.appendChild(emailInput);

        form.appendChild(emailField);

        // Message textarea.
        var messageField = document.createElement('div');
        messageField.className = 'tpmp-contact-maire__field';

        var messageLabel = document.createElement('label');
        messageLabel.textContent = 'Votre message';
        messageLabel.setAttribute('for', 'tpmp-message');
        messageField.appendChild(messageLabel);

        var messageInput = document.createElement('textarea');
        messageInput.name = 'message';
        messageInput.id = 'tpmp-message';
        messageInput.rows = 5;
        messageField.appendChild(messageInput);

        form.appendChild(messageField);

        // Submit button.
        var submitButton = document.createElement('button');
        submitButton.type = 'submit';
        submitButton.textContent = 'Envoyer';
        submitButton.className = 'tpmp-contact-maire__submit';
        form.appendChild(submitButton);

        var feedback = document.createElement('div');
        feedback.className = 'tpmp-contact-maire__feedback';
        form.appendChild(feedback);

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            handleSubmit({
                select: select,
                emailInput: emailInput,
                messageInput: messageInput,
                feedback: feedback,
                submitButton: submitButton,
            });
        });

        formContainer.appendChild(form);
        root.appendChild(formContainer);
    }

    function handleSubmit(options) {
        var select = options.select;
        var emailInput = options.emailInput;
        var messageInput = options.messageInput;
        var feedback = options.feedback;
        var submitButton = options.submitButton;

        feedback.textContent = '';
        feedback.className = 'tpmp-contact-maire__feedback';

        var commune = select.value.trim();
        var email = emailInput.value.trim();
        var message = messageInput.value.trim();

        var errors = [];

        if (!commune) {
            errors.push('Veuillez choisir une commune.');
        }

        if (!email) {
            errors.push('Veuillez saisir votre email.');
        } else if (!/^\S+@\S+\.\S+$/.test(email)) {
            errors.push('Le format de l\'email est invalide.');
        }

        if (!message) {
            errors.push('Veuillez saisir un message.');
        }

        if (errors.length) {
            feedback.textContent = errors.join(' ');
            feedback.className += ' tpmp-contact-maire__feedback--error';
            return;
        }

        submitButton.disabled = true;
        submitButton.textContent = 'Envoi en cours...';

        var payload = new URLSearchParams();
        payload.append('action', 'tpmp_contact_maire_send');
        payload.append('nonce', TPMP_CONTACT_MAIRE.nonce || '');
        payload.append('commune', commune);
        payload.append('email', email);
        payload.append('message', message);

        fetch(TPMP_CONTACT_MAIRE.ajax_url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: payload.toString(),
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (data && data.success) {
                    feedback.textContent = 'Votre message a bien été envoyé à la mairie.';
                    feedback.className += ' tpmp-contact-maire__feedback--success';
                    emailInput.value = '';
                    messageInput.value = '';
                    select.value = '';
                } else {
                    var errorMsg = data && data.error ? data.error : 'Une erreur est survenue. Merci de réessayer plus tard.';
                    feedback.textContent = errorMsg;
                    feedback.className += ' tpmp-contact-maire__feedback--error';
                }
            })
            .catch(function () {
                feedback.textContent = 'Impossible d\'envoyer le message. Vérifiez votre connexion et réessayez.';
                feedback.className += ' tpmp-contact-maire__feedback--error';
            })
            .finally(function () {
                submitButton.disabled = false;
                submitButton.textContent = 'Envoyer';
            });
    }

    ready(function () {
        if (typeof TPMP_CONTACT_MAIRE === 'undefined') {
            return;
        }

        var root = document.getElementById('tpmp-contact-maire-root');
        if (!root) {
            return;
        }

        var communes = Array.isArray(TPMP_CONTACT_MAIRE.communes)
            ? TPMP_CONTACT_MAIRE.communes
            : [
                  { slug: 'paris', label: 'Paris' },
                  { slug: 'marseille', label: 'Marseille' },
                  { slug: 'lyon', label: 'Lyon' },
              ];

        renderForm(root, communes);
    });
})();
