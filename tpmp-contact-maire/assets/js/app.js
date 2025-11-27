(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    function slugifyCategory(label) {
        return label
            .toString()
            .normalize('NFD')
            .replace(/\p{Diacritic}/gu, '')
            .toLowerCase()
            .replace(/[^a-z0-9\s-]/g, '')
            .trim()
            .replace(/\s+/g, '-');
    }

    function getCategoriesFromTemplates(templates) {
        var categories = [];
        var seen = {};

        if (!Array.isArray(templates)) {
            return categories;
        }

        templates.forEach(function (template) {
            if (!template || !template.category) {
                return;
            }

            var label = template.category && template.category.trim() ? template.category.trim() : '';
            if (!label) {
                return;
            }

            var slug = slugifyCategory(label);
            if (!slug || seen[slug]) {
                return;
            }

            seen[slug] = true;
            categories.push({ slug: slug, label: label });
        });

        return categories;
    }

    function showFeedback(feedbackNode, message, type) {
        feedbackNode.textContent = message;
        feedbackNode.className = 'tpmp-feedback';

        if (type === 'success') {
            feedbackNode.className += ' tpmp-feedback-success';
        } else if (type === 'error') {
            feedbackNode.className += ' tpmp-feedback-error';
        } else if (type === 'info') {
            feedbackNode.className += ' tpmp-feedback-info';
        }
    }

    function buildTemplatePreview(template) {
        var text = '';

        if (template && template.content) {
            text = template.content;
        } else if (template && template.label) {
            text = template.label;
        } else if (template && template.id) {
            text = template.id;
        }

        text = text ? text.toString().trim() : '';
        if (!text) {
            return '';
        }

        var cleaned = text.replace(/\s+/g, ' ');
        if (cleaned.length > 90) {
            return cleaned.slice(0, 90).trimEnd() + '…';
        }

        return cleaned;
    }

    function renderForm(root, communes, templates) {
        var categories = getCategoriesFromTemplates(templates);
        var templatesByCategory = {};
        var templatesIndex = {};

        if (Array.isArray(templates)) {
            templates.forEach(function (template) {
                if (!template || !template.id || !template.category) {
                    return;
                }

                var categoryLabel = template.category && template.category.trim() ? template.category.trim() : '';
                if (!categoryLabel) {
                    return;
                }

                var slug = slugifyCategory(categoryLabel);
                if (!slug) {
                    return;
                }

                if (!templatesByCategory[slug]) {
                    templatesByCategory[slug] = [];
                }

                templatesByCategory[slug].push(template);
                templatesIndex[template.id] = template;
            });
        }

        var formContainer = document.createElement('div');
        formContainer.className = 'tpmp-contact-maire';

        var title = document.createElement('h3');
        title.textContent = 'Contacter votre mairie';
        formContainer.appendChild(title);

        var form = document.createElement('form');
        form.className = 'tpmp-form';

        // Commune select.
        var communeField = document.createElement('div');
        communeField.className = 'tpmp-form-group';

        var communeLabel = document.createElement('label');
        communeLabel.textContent = 'Commune';
        communeLabel.setAttribute('for', 'tpmp-commune');
        communeField.appendChild(communeLabel);

        var select = document.createElement('select');
        select.name = 'commune';
        select.id = 'tpmp-commune';
        select.required = true;
        select.setAttribute('aria-required', 'true');

        var defaultCommuneOption = document.createElement('option');
        defaultCommuneOption.value = '';
        defaultCommuneOption.textContent = 'Choisissez votre commune';
        select.appendChild(defaultCommuneOption);

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
        emailField.className = 'tpmp-form-group';

        var emailLabel = document.createElement('label');
        emailLabel.textContent = 'Votre email';
        emailLabel.setAttribute('for', 'tpmp-email');
        emailField.appendChild(emailLabel);

        var emailInput = document.createElement('input');
        emailInput.type = 'email';
        emailInput.name = 'email';
        emailInput.id = 'tpmp-email';
        emailInput.placeholder = 'votre@email.com';
        emailInput.required = true;
        emailInput.setAttribute('aria-required', 'true');
        emailField.appendChild(emailInput);

        var emailHint = document.createElement('p');
        emailHint.className = 'tpmp-email-hint';
        emailHint.textContent = 'Votre e-mail ne sera pas enregistré. Il sert uniquement à permettre à la mairie de vous répondre.';
        emailField.appendChild(emailHint);

        form.appendChild(emailField);

        // Category select.
        var categoryField = document.createElement('div');
        categoryField.className = 'tpmp-form-group';

        var categoryLabel = document.createElement('label');
        categoryLabel.textContent = 'Catégorie de message';
        categoryLabel.setAttribute('for', 'tpmp-category-select');
        categoryField.appendChild(categoryLabel);

        var categorySelect = document.createElement('select');
        categorySelect.name = 'category';
        categorySelect.id = 'tpmp-category-select';

        if (!categories.length) {
            var noCategoryOption = document.createElement('option');
            noCategoryOption.value = '';
            noCategoryOption.textContent = 'Aucune catégorie disponible';
            categorySelect.appendChild(noCategoryOption);
        } else {
            var defaultCategoryOption = document.createElement('option');
            defaultCategoryOption.value = '';
            defaultCategoryOption.textContent = 'Choisissez une catégorie (facultatif)';
            categorySelect.appendChild(defaultCategoryOption);

            categories.forEach(function (category) {
                var option = document.createElement('option');
                option.value = category.slug;
                option.textContent = category.label;
                categorySelect.appendChild(option);
            });
        }

        categoryField.appendChild(categorySelect);
        form.appendChild(categoryField);

        // Template select by category.
        var templateField = document.createElement('div');
        templateField.className = 'tpmp-form-group';

        var templateLabel = document.createElement('label');
        templateLabel.textContent = 'Modèle de message';
        templateLabel.setAttribute('for', 'tpmp-template-select');
        templateField.appendChild(templateLabel);

        var templateSelect = document.createElement('select');
        templateSelect.name = 'template';
        templateSelect.id = 'tpmp-template-select';
        templateSelect.disabled = true;

        templateField.appendChild(templateSelect);
        form.appendChild(templateField);

        // Message textarea.
        var messageField = document.createElement('div');
        messageField.className = 'tpmp-form-group';

        var messageLabel = document.createElement('label');
        messageLabel.textContent = 'Votre message';
        messageLabel.setAttribute('for', 'tpmp-message');
        messageField.appendChild(messageLabel);

        var messageInput = document.createElement('textarea');
        messageInput.name = 'message';
        messageInput.id = 'tpmp-message';
        messageInput.rows = 5;
        messageInput.required = true;
        messageInput.setAttribute('aria-required', 'true');
        messageField.appendChild(messageInput);

        form.appendChild(messageField);

        // Actions.
        var actionsWrapper = document.createElement('div');
        actionsWrapper.className = 'tpmp-form-group tpmp-form-actions';

        var submitButton = document.createElement('button');
        submitButton.type = 'submit';
        submitButton.textContent = 'Envoyer';
        submitButton.className = 'tpmp-contact-maire__submit';
        actionsWrapper.appendChild(submitButton);

        form.appendChild(actionsWrapper);

        var feedback = document.createElement('div');
        feedback.id = 'tpmp-feedback';
        feedback.className = 'tpmp-feedback';
        feedback.setAttribute('aria-live', 'polite');
        form.appendChild(feedback);

        function resetTemplateSelect(showCategoryPrompt) {
            if (typeof showCategoryPrompt === 'undefined') {
                showCategoryPrompt = categories.length > 0;
            }

            templateSelect.disabled = true;
            while (templateSelect.firstChild) {
                templateSelect.removeChild(templateSelect.firstChild);
            }
            var placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = showCategoryPrompt ? 'Choisissez d’abord une catégorie' : 'Aucun modèle disponible';
            templateSelect.appendChild(placeholder);
        }

        function populateTemplateSelect(slug) {
            if (!slug || !templatesByCategory[slug]) {
                resetTemplateSelect(categories.length > 0);
                return;
            }

            templateSelect.disabled = false;
            while (templateSelect.firstChild) {
                templateSelect.removeChild(templateSelect.firstChild);
            }

            var defaultOptionTemplate = document.createElement('option');
            defaultOptionTemplate.value = '';
            defaultOptionTemplate.textContent = 'Choisissez un modèle dans la catégorie';
            templateSelect.appendChild(defaultOptionTemplate);

            templatesByCategory[slug].forEach(function (template) {
                var option = document.createElement('option');
                option.value = template.id;
                var previewLabel = buildTemplatePreview(template);
                option.textContent = previewLabel || template.id;
                templateSelect.appendChild(option);
            });
        }

        resetTemplateSelect(categories.length > 0);

        categorySelect.addEventListener('change', function (event) {
            var selectedCategory = event.target.value;
            if (!selectedCategory) {
                resetTemplateSelect(true);
                messageInput.value = '';
                return;
            }

            populateTemplateSelect(selectedCategory);
            messageInput.value = '';
        });

        templateSelect.addEventListener('change', function (event) {
            var selectedId = event.target.value;
            if (!selectedId) {
                return;
            }

            var matchedTemplate = templatesIndex[selectedId];
            if (matchedTemplate && typeof matchedTemplate.content === 'string') {
                // Replace the entire textarea content with the selected template.
                messageInput.value = matchedTemplate.content;
            }
        });

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            handleSubmit({
                select: select,
                emailInput: emailInput,
                messageInput: messageInput,
                feedback: feedback,
                submitButton: submitButton,
                categorySelect: categorySelect,
                templateSelect: templateSelect,
                resetTemplateSelect: resetTemplateSelect,
                categories: categories,
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
        var categorySelect = options.categorySelect;
        var templateSelect = options.templateSelect;
        var resetTemplateSelect = options.resetTemplateSelect;
        var categories = Array.isArray(options.categories) ? options.categories : [];

        showFeedback(feedback, '', '');

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
            showFeedback(feedback, errors.join(' '), 'error');
            return;
        }

        submitButton.disabled = true;
        var originalSubmitText = submitButton.textContent;
        submitButton.textContent = 'Envoi en cours...';

        var categorySlug = categorySelect && categorySelect.value ? categorySelect.value.trim() : '';
        var categoryLabel = '';

        if (categorySlug) {
            for (var i = 0; i < categories.length; i++) {
                if (categories[i].slug === categorySlug) {
                    categoryLabel = categories[i].label;
                    break;
                }
            }
        }

        var payload = new URLSearchParams();
        payload.append('action', 'tpmp_contact_maire_send');
        payload.append('nonce', TPMP_CONTACT_MAIRE.nonce || '');
        payload.append('commune', commune);
        payload.append('email', email);
        payload.append('message', message);
        payload.append('category', categorySlug);
        payload.append('category_label', categoryLabel);

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
                    showFeedback(feedback, 'Votre message a bien été envoyé à la mairie.', 'success');
                    emailInput.value = '';
                    messageInput.value = '';
                    select.value = '';
                    if (categorySelect) {
                        categorySelect.value = '';
                    }
                    if (templateSelect) {
                        resetTemplateSelect();
                    }
                } else {
                    var errorMsg = data && data.error ? data.error : 'Une erreur est survenue. Merci de réessayer plus tard.';
                    showFeedback(feedback, errorMsg, 'error');
                }
            })
            .catch(function () {
                showFeedback(feedback, 'Impossible d\'envoyer le message. Vérifiez votre connexion et réessayez.', 'error');
            })
            .finally(function () {
                submitButton.disabled = false;
                submitButton.textContent = originalSubmitText;
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

        var communes = Array.isArray(TPMP_CONTACT_MAIRE.communes) ? TPMP_CONTACT_MAIRE.communes : [];
        var templates = Array.isArray(TPMP_CONTACT_MAIRE.templates) ? TPMP_CONTACT_MAIRE.templates : [];

        renderForm(root, communes, templates);
    });
})();
