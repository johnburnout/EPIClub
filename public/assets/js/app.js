/**
 * [SÉCURITÉ — audit 2026-11-07] Comportements communs à toutes les pages,
 * sans attribut on*= (interdit par la CSP : script-src sans
 * 'unsafe-inline', cf. app/Engine/Csp.php).
 *
 *   data-confirm="Message"  sur un bouton, un lien ou un formulaire :
 *                           confirmation avant l'action (annulée sinon).
 *   data-auto-submit        sur une liste déroulante : envoie son
 *                           formulaire dès qu'elle change.
 *   data-print              sur un bouton : imprime la page.
 *   data-alert="Message"    sur un lien : affiche le message, sans suivre
 *                           le lien.
 *   data-fallback-src="…"   sur une image : image de remplacement si la
 *                           première ne se charge pas (data-fallback-alt
 *                           pour son texte alternatif).
 *
 * Les messages sont des attributs HTML (échappement Twig par défaut) :
 * plus besoin d'échapper apostrophes et retours à la ligne pour le JS.
 */
(function () {
    'use strict';

    // Confirmation d'un bouton ou d'un lien (clic).
    document.addEventListener('click', function (e) {
        var el = e.target.closest('[data-confirm]:not(form)');
        if (el && !window.confirm(el.getAttribute('data-confirm'))) {
            e.preventDefault();
            e.stopImmediatePropagation();
        }
    }, true);

    // Confirmation d'un formulaire (envoi).
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (form.matches && form.matches('form[data-confirm]')
            && !window.confirm(form.getAttribute('data-confirm'))) {
            e.preventDefault();
            e.stopImmediatePropagation();
        }
    }, true);

    document.addEventListener('change', function (e) {
        var el = e.target.closest('[data-auto-submit]');
        if (el && el.form) {
            el.form.submit();
        }
    });

    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-print]')) {
            e.preventDefault();
            window.print();
            return;
        }
        var el = e.target.closest('[data-alert]');
        if (el) {
            e.preventDefault();
            window.alert(el.getAttribute('data-alert'));
        }
    });

    // Image de remplacement : l'événement « error » ne remonte pas, on
    // l'écoute en phase de capture ; une image déjà en échec au chargement
    // du script est traitée tout de suite.
    function remplacer(img) {
        var secours = img.getAttribute('data-fallback-src');
        if (!secours || img.getAttribute('src') === secours) {
            return;
        }
        img.src = secours;
        if (img.hasAttribute('data-fallback-alt')) {
            img.alt = img.getAttribute('data-fallback-alt');
        }
    }
    document.addEventListener('error', function (e) {
        if (e.target instanceof HTMLImageElement) {
            remplacer(e.target);
        }
    }, true);
    function verifierImages() {
        document.querySelectorAll('img[data-fallback-src]').forEach(function (img) {
            if (img.complete && img.naturalWidth === 0) {
                remplacer(img);
            }
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', verifierImages);
    } else {
        verifierImages();
    }
})();
