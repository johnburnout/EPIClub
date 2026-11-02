/*
 * [migration 020] Passkeys (WebAuthn) — connexion et enregistrement.
 *
 * Les options viennent du serveur avec les données binaires en base64url ;
 * les réponses du navigateur lui sont renvoyées dans le même encodage.
 */
(function () {
	'use strict';

	function versOctets(base64url) {
		var base64 = base64url.replace(/-/g, '+').replace(/_/g, '/');
		while (base64.length % 4) {
			base64 += '=';
		}
		var binaire = atob(base64);
		var octets = new Uint8Array(binaire.length);
		for (var i = 0; i < binaire.length; i++) {
			octets[i] = binaire.charCodeAt(i);
		}
		return octets.buffer;
	}

	function versBase64Url(buffer) {
		var octets = new Uint8Array(buffer);
		var binaire = '';
		for (var i = 0; i < octets.length; i++) {
			binaire += String.fromCharCode(octets[i]);
		}
		return btoa(binaire).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
	}

	function disponible() {
		return !!(window.PublicKeyCredential && navigator.credentials && window.isSecureContext);
	}

	async function appeler(url, csrf, corps) {
		var reponse = await fetch(url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, 'Accept': 'application/json' },
			body: JSON.stringify(corps || {})
		});
		var donnees = {};
		try {
			donnees = await reponse.json();
		} catch (e) {
			donnees = {};
		}
		if (!reponse.ok) {
			throw new Error(donnees.erreur || 'Erreur ' + reponse.status);
		}
		return donnees;
	}

	function messageErreur(erreur) {
		if (erreur && erreur.name === 'NotAllowedError') {
			return 'Opération annulée ou délai dépassé.';
		}
		if (erreur && erreur.name === 'InvalidStateError') {
			return 'Cet appareil a déjà une passkey enregistrée pour ce compte.';
		}
		return (erreur && erreur.message) || 'La passkey n\'a pas pu être utilisée.';
	}

	/** Connexion (sans mot de passe, ou second facteur après le mot de passe). */
	async function seConnecter(csrf, redirect) {
		// Le serveur renvoie { publicKey: { … } } (format de lbuchs/webauthn).
		var options = (await appeler('/se_connecter/passkey/options', csrf)).publicKey;
		options.challenge = versOctets(options.challenge);
		(options.allowCredentials || []).forEach(function (c) {
			c.id = versOctets(c.id);
		});

		var credential = await navigator.credentials.get({ publicKey: options });
		var r = credential.response;
		var resultat = await appeler('/se_connecter/passkey', csrf, {
			id: versBase64Url(credential.rawId),
			clientDataJSON: versBase64Url(r.clientDataJSON),
			authenticatorData: versBase64Url(r.authenticatorData),
			signature: versBase64Url(r.signature),
			userHandle: r.userHandle ? versBase64Url(r.userHandle) : null,
			redirect: redirect || null
		});
		window.location.href = resultat.redirect || '/';
	}

	/** Enregistrement d'une nouvelle passkey (page Sécurité du compte). */
	async function enregistrer(csrf, nom) {
		var options = (await appeler('/mon-compte/securite/passkey/options', csrf)).publicKey;
		options.challenge = versOctets(options.challenge);
		options.user.id = versOctets(options.user.id);
		(options.excludeCredentials || []).forEach(function (c) {
			c.id = versOctets(c.id);
		});

		var credential = await navigator.credentials.create({ publicKey: options });
		var resultat = await appeler('/mon-compte/securite/passkey', csrf, {
			id: versBase64Url(credential.rawId),
			clientDataJSON: versBase64Url(credential.response.clientDataJSON),
			attestationObject: versBase64Url(credential.response.attestationObject),
			nom: nom || ''
		});
		window.location.href = resultat.redirect || '/mon-compte/securite';
	}

	function brancher(bouton, zoneErreur, action) {
		bouton.addEventListener('click', async function () {
			zoneErreur.textContent = '';
			bouton.disabled = true;
			try {
				await action();
			} catch (e) {
				zoneErreur.textContent = messageErreur(e);
				bouton.disabled = false;
			}
		});
	}

	window.EpiclubPasskey = {
		disponible: disponible,

		/** Bloc contenant #passkey-bouton (data-csrf, data-redirect) et #passkey-erreur. */
		initConnexion: function (bloc) {
			if (!bloc || !disponible()) {
				return;
			}
			var bouton = bloc.querySelector('#passkey-bouton');
			bloc.hidden = false;
			brancher(bouton, bloc.querySelector('#passkey-erreur'), function () {
				return seConnecter(bouton.dataset.csrf, bouton.dataset.redirect);
			});
		},

		/** Bloc contenant #passkey-nom, #passkey-ajouter (data-csrf) et #passkey-erreur. */
		initEnregistrement: function (bloc) {
			if (!bloc) {
				return;
			}
			var zoneErreur = bloc.querySelector('#passkey-erreur');
			var bouton = bloc.querySelector('#passkey-ajouter');
			if (!disponible()) {
				bouton.disabled = true;
				zoneErreur.textContent = 'Ce navigateur ne prend pas en charge les passkeys.';
				return;
			}
			brancher(bouton, zoneErreur, function () {
				return enregistrer(bouton.dataset.csrf, bloc.querySelector('#passkey-nom').value);
			});
		}
	};
})();
