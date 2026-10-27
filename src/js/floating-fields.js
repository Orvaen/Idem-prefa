(() => {
    function enhanceFields() {
        document.querySelectorAll('label[for]').forEach(label => {
            const field = document.getElementById(label.htmlFor);
            if (!field || label.closest('[data-static-fields]') || label.closest('.floating-field') || field.closest('.floating-field')) return;
            if (!field.matches('input, textarea, select') || field.matches('input[type="hidden"], input[type="file"], input[type="checkbox"], input[type="radio"], input[type="range"], input[type="submit"], input[type="button"]')) return;

            const wrapper = document.createElement('div');
            wrapper.className = 'floating-field';
            // « .ctrl-date » compte comme « .input-unit » : le champ y arrive avec le
            // déclencheur de son calendrier, et c'est l'ensemble qui tient la place du
            // contrôle. Sans ce regard, l'étiquette flottante emportait le champ seul et
            // laissait la glyphe en arrière, dans une enveloppe devenue vide.
            const unit = field.closest('.input-unit, .ctrl-date');
            const control = unit || field;
            control.before(wrapper);
            wrapper.append(control, label);
            field.classList.add('floating-control');
            if (field.matches('select, input[type="date"], input[type="datetime-local"], input[type="time"]')) {
                wrapper.classList.add('floating-always');
            } else if (!field.hasAttribute('placeholder')) {
                field.setAttribute('placeholder', ' ');
            }
        });
        // Match the surrounding panel so the floating label cuts the border
        // without leaving a contrasting rectangle behind its text.
        document.querySelectorAll('.floating-field').forEach(wrapper => {
            const field = wrapper.querySelector('.floating-control');
            if (field && (field.matches('select, [role="combobox"]') || (field.getAttribute('placeholder') || '').trim() !== '')) {
                wrapper.classList.add('floating-always');
            }
            let parent = wrapper.parentElement;
            while (parent) {
                const background = getComputedStyle(parent).backgroundColor;
                if (background !== 'transparent' && background !== 'rgba(0, 0, 0, 0)') {
                    if (wrapper.style.getPropertyValue('--field-surface') !== background) {
                        wrapper.style.setProperty('--field-surface', background);
                    }
                    break;
                }
                parent = parent.parentElement;
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', enhanceFields);
    } else {
        enhanceFields();
    }
    // Les volets arrivent par jQuery et HTMX ; et data-theme change sur <html> quand on
    // bascule de thème.
    //
    // Ce second cas comptait : la couleur de fond est relevée sur l'ancêtre et écrite en dur
    // dans --field-surface au moment où le champ est décoré. Elle restait donc celle de
    // l'ancien thème — les champs gardaient leur fond sombre sur une page devenue claire
    // jusqu'au prochain rechargement complet. Relancer la décoration la recalcule ; la
    // fonction est faite pour être rejouée, elle ne redécore pas ce qui l'est déjà.
    new MutationObserver(enhanceFields).observe(document.documentElement, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['data-theme'],
    });
})();
