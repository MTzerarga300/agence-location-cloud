/* Interactions de l'interface : aucune dépendance externe. */
(function () {
    'use strict';

    const PLACEHOLDER = 'assets/car-placeholder.svg';
    const money = (value) => new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 }).format(value) + ' DA';

    // Photo indisponible : image de remplacement
    document.addEventListener('error', (event) => {
        const img = event.target;
        if (img.tagName === 'IMG' && img.hasAttribute('data-fallback') && !img.src.endsWith(PLACEHOLDER)) {
            img.src = PLACEHOLDER;
        }
    }, true);

    document.addEventListener('DOMContentLoaded', () => {
        // Photos déjà en échec avant le chargement de ce script
        document.querySelectorAll('img[data-fallback]').forEach((img) => {
            if (img.complete && img.naturalWidth === 0 && !img.src.endsWith(PLACEHOLDER)) img.src = PLACEHOLDER;
        });
        setupFilters();
        setupDialogs();
        setupConfirmations();
        setupTableSearch();
        setupUploadPreview();
        setupMaintenanceDescription();
        setupRoleForm();
        setupBooking();
        setupSectionNav();
    });

    // Filtre de la flotte par agence
    function setupFilters() {
        document.querySelectorAll('[data-filter-group]').forEach((group) => {
            const target = document.querySelector(`[data-filter-target="${group.dataset.filterGroup}"]`);
            if (!target) return;
            group.addEventListener('click', (event) => {
                const chip = event.target.closest('[data-filter]');
                if (!chip) return;
                group.querySelectorAll('[data-filter]').forEach((c) => c.classList.toggle('is-active', c === chip));
                const key = chip.dataset.filter;
                target.querySelectorAll('[data-filter-key]').forEach((item) => {
                    item.hidden = key !== 'all' && item.dataset.filterKey !== key;
                });
            });
        });
    }

    // Ouverture et fermeture des dialogues, avec pré-remplissage éventuel
    function setupDialogs() {
        document.addEventListener('click', (event) => {
            const opener = event.target.closest('[data-open]');
            if (opener) {
                const dialog = document.getElementById(opener.dataset.open);
                if (!dialog) return;
                if (opener.dataset.fill) fillDialog(dialog, JSON.parse(opener.dataset.fill));
                dialog.showModal();
                return;
            }
            const closer = event.target.closest('[data-close]');
            if (closer) closer.closest('dialog')?.close();
        });

        document.querySelectorAll('dialog').forEach((dialog) => {
            dialog.addEventListener('click', (event) => {
                if (event.target === dialog) dialog.close();
            });
        });
    }

    function fillDialog(dialog, values) {
        Object.entries(values).forEach(([name, value]) => {
            const field = dialog.querySelector(`[name="${name}"]`);
            if (field) field.value = value;
            const text = dialog.querySelector(`[data-fill-text="${name}"]`);
            if (text) text.textContent = value;
        });
    }

    // Confirmation avant une action sensible
    function setupConfirmations() {
        document.addEventListener('submit', (event) => {
            const form = event.target;
            const button = event.submitter;
            const message = button?.dataset.confirm || form.dataset.confirm;
            if (message && !window.confirm(message)) event.preventDefault();
        });
    }

    // Recherche instantanée dans un tableau
    function setupTableSearch() {
        document.querySelectorAll('[data-table-filter]').forEach((input) => {
            const table = document.getElementById(input.dataset.tableFilter);
            if (!table) return;
            input.addEventListener('input', () => {
                const term = input.value.trim().toLowerCase();
                table.querySelectorAll('tbody tr:not(.table__empty)').forEach((row) => {
                    row.hidden = term !== '' && !row.textContent.toLowerCase().includes(term);
                });
            });
        });
    }

    // Aperçu de la photo choisie
    function setupUploadPreview() {
        document.querySelectorAll('input[type=file][data-preview]').forEach((input) => {
            const preview = document.getElementById(input.dataset.preview);
            input.addEventListener('change', () => {
                const file = input.files?.[0];
                if (!file || !preview) return;
                preview.src = URL.createObjectURL(file);
                preview.hidden = false;
            });
        });
    }

    // Description proposée selon le type d'intervention
    function setupMaintenanceDescription() {
        const source = document.querySelector('[data-description-source]');
        const target = document.querySelector('[data-description-target]');
        if (!source || !target) return;
        source.addEventListener('change', () => {
            target.value = source.selectedOptions[0]?.dataset.text || '';
            if (target.value === '') target.focus();
        });
    }

    // Les champs d'agence ne concernent que le rôle « Agence »
    function setupRoleForm() {
        document.querySelectorAll('[data-role-form]').forEach((form) => {
            const agentOnly = form.querySelector('[data-agent-only]');
            const update = () => {
                const isAgent = form.querySelector('input[name=role]:checked')?.value === 'agent';
                agentOnly.hidden = !isAgent;
                agentOnly.querySelectorAll('input').forEach((input) => { input.required = isAgent; });
            };
            form.addEventListener('change', update);
            update();
        });
    }

    // Récapitulatif de réservation calculé en direct
    function setupBooking() {
        const form = document.querySelector('[data-booking]');
        if (!form) return;
        const car = form.querySelector('[data-booking-car]');
        const start = form.querySelector('[data-booking-start]');
        const end = form.querySelector('[data-booking-end]');
        const out = (name) => form.querySelector(`[data-booking-${name}]`);

        const update = () => {
            const option = car.selectedOptions[0];
            const chosen = option && option.value !== '';

            if (start.value) {
                const next = new Date(start.value);
                next.setDate(next.getDate() + 1);
                end.min = next.toISOString().slice(0, 10);
            }

            out('name').textContent = chosen ? option.dataset.name : 'Aucune voiture choisie';
            out('agency').textContent = chosen ? option.dataset.agency : "Choisissez une voiture pour voir l'agence et le prix.";
            out('image').src = chosen ? option.dataset.image : PLACEHOLDER;
            out('plate').hidden = !chosen;
            out('plate').textContent = chosen ? option.dataset.plate : '';
            out('price').textContent = chosen ? money(Number(option.dataset.price)) : '—';

            const days = start.value && end.value
                ? Math.round((new Date(end.value) - new Date(start.value)) / 86400000)
                : 0;
            out('days').textContent = days > 0 ? `${days} jour${days > 1 ? 's' : ''}` : '—';
            out('total').textContent = chosen && days > 0 ? money(days * Number(option.dataset.price)) : '—';
        };

        [car, start, end].forEach((field) => field.addEventListener('change', update));
        update();
    }

    // Lien actif dans la barre latérale selon la section visible
    function setupSectionNav() {
        const links = [...document.querySelectorAll('.dash-nav a[href^="#"]')];
        if (!links.length || !('IntersectionObserver' in window)) return;
        const byId = new Map(links.map((link) => [link.getAttribute('href').slice(1), link]));
        const observer = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) return;
                links.forEach((link) => link.classList.remove('is-active'));
                byId.get(entry.target.id)?.classList.add('is-active');
            });
        }, { rootMargin: '-30% 0px -60% 0px' });
        byId.forEach((_, id) => {
            const section = document.getElementById(id);
            if (section) observer.observe(section);
        });
    }
})();
