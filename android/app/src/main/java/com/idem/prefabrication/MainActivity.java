package com.idem.prefabrication;

import android.app.Activity;
import android.app.DownloadManager;
import android.content.ActivityNotFoundException;
import android.content.ClipData;
import android.content.Intent;
import android.graphics.Color;
import android.graphics.Insets;
import android.net.Uri;
import android.os.Bundle;
import android.os.Environment;
import android.text.TextUtils;
import android.view.WindowInsets;
import android.view.WindowInsetsController;
import android.webkit.CookieManager;
import android.webkit.URLUtil;
import android.webkit.ValueCallback;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.FrameLayout;
import android.widget.Toast;

import java.io.UnsupportedEncodingException;
import java.net.URLDecoder;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

/**
 * Le site de l'atelier, en plein écran, sans barre de navigateur.
 *
 * Une WebView affiche la page, mais ne sait rien faire de ce qui en sort : choisir un fichier
 * à envoyer (plans, photos, PV), en télécharger un, revenir en arrière, dire que le serveur ne
 * répond pas. C'est tout ce qu'ajoute cette classe ; le reste est le site, tel quel.
 */
public class MainActivity extends Activity {

    private static final int CHOIX_FICHIERS = 1;

    private WebView vue;
    private Uri site;

    /** Le rappel de la page qui attend les fichiers choisis, le temps que le sélecteur réponde. */
    private ValueCallback<Uri[]> fichiersAttendus;

    @Override
    protected void onCreate(Bundle etat) {
        super.onCreate(etat);
        site = Uri.parse(BuildConfig.URL_SITE);

        // Android 15 dessine l'appli derrière la barre d'état, la barre de navigation et le
        // clavier. On leur rend la place en marge du cadre : sans cela, le haut de la page
        // passait sous l'heure, et le champ actif sous le clavier.
        getWindow().setDecorFitsSystemWindows(false);
        FrameLayout cadre = new FrameLayout(this);
        cadre.setBackgroundColor(Color.parseColor("#141820"));
        cadre.setOnApplyWindowInsetsListener((v, encarts) -> {
            Insets barres = encarts.getInsets(WindowInsets.Type.systemBars() | WindowInsets.Type.displayCutout());
            Insets clavier = encarts.getInsets(WindowInsets.Type.ime());
            v.setPadding(barres.left, barres.top, barres.right, Math.max(barres.bottom, clavier.bottom));
            annoncerClavier(encarts.isVisible(WindowInsets.Type.ime()));
            return WindowInsets.CONSUMED;
        });

        vue = new WebView(this);
        cadre.addView(vue, new FrameLayout.LayoutParams(
                FrameLayout.LayoutParams.MATCH_PARENT, FrameLayout.LayoutParams.MATCH_PARENT));
        setContentView(cadre);

        pleinEcran();

        WebSettings reglages = vue.getSettings();
        reglages.setJavaScriptEnabled(true);
        // Le thème choisi (clair ou sombre) est gardé dans localStorage.
        reglages.setDomStorageEnabled(true);
        reglages.setAllowFileAccess(false);
        // Le site peut reconnaître l'appli, s'il en a un jour besoin.
        reglages.setUserAgentString(reglages.getUserAgentString() + " IdemPrefa/" + BuildConfig.VERSION_NAME);
        CookieManager.getInstance().setAcceptCookie(true);
        // En debug, la page s'inspecte depuis le PC : chrome://inspect, tablette branchée.
        if (BuildConfig.DEBUG) WebView.setWebContentsDebuggingEnabled(true);

        vue.setWebViewClient(new WebViewClient() {
            @Override
            public boolean shouldOverrideUrlLoading(WebView v, WebResourceRequest demande) {
                Uri adresse = demande.getUrl();
                if (duSite(adresse)) return false;
                // Un lien qui sort du site (mailto:, tel:, autre domaine) part dans l'appli
                // qui sait l'ouvrir : l'appli d'atelier n'est pas un navigateur.
                ouvrirAilleurs(adresse);
                return true;
            }

            @Override
            public void onReceivedError(WebView v, WebResourceRequest demande, WebResourceError erreur) {
                if (demande.isForMainFrame()) afficherInjoignable(erreur.getDescription());
            }
        });

        vue.setWebChromeClient(new WebChromeClient() {
            @Override
            public boolean onShowFileChooser(WebView v, ValueCallback<Uri[]> rappel, FileChooserParams params) {
                // Un sélecteur resté sans réponse : la page attend encore, on la libère.
                if (fichiersAttendus != null) fichiersAttendus.onReceiveValue(null);
                fichiersAttendus = rappel;
                Intent choix = params.createIntent();
                // Les plans se déposent par lots : <input multiple> doit le rester.
                if (params.getMode() == FileChooserParams.MODE_OPEN_MULTIPLE) {
                    choix.putExtra(Intent.EXTRA_ALLOW_MULTIPLE, true);
                }
                try {
                    startActivityForResult(choix, CHOIX_FICHIERS);
                } catch (ActivityNotFoundException e) {
                    fichiersAttendus = null;
                    Toast.makeText(MainActivity.this, "Aucune appli pour choisir un fichier", Toast.LENGTH_LONG).show();
                    return false;
                }
                return true;
            }
        });

        vue.setDownloadListener((adresse, agent, disposition, type, taille) ->
                telecharger(adresse, agent, disposition, type));

        if (etat == null || vue.restoreState(etat) == null) vue.loadUrl(site.toString());
    }

    /** Le dernier état du clavier annoncé à la page : on ne le répète pas à chaque encart. */
    private Boolean clavierAnnonce;

    /**
     * Dit à la page que le clavier s'ouvre ou se ferme (js/composants/clavier.js). Elle le
     * devinait à la hauteur de la vue, et la barre de navigation qui revient avec le clavier
     * pouvait fausser le calcul : la page ne rendait pas la place, et une fenêtre comme
     * « Ajouter un plan » disparaissait sous le clavier. Android, lui, le sait.
     */
    private void annoncerClavier(boolean visible) {
        if (vue == null || (clavierAnnonce != null && clavierAnnonce == visible)) return;
        clavierAnnonce = visible;
        vue.evaluateJavascript("window.dispatchEvent(new CustomEvent('clavier:natif', { detail: "
                + visible + " }))", null);
    }

    /**
     * Plein écran : ni barre d'état en haut, ni barre de gestes en bas. Un glissement depuis
     * le bord les rappelle un instant, par-dessus la page, sans la décaler. Elles se cachent
     * ensuite d'elles-mêmes.
     */
    private void pleinEcran() {
        WindowInsetsController systeme = getWindow().getInsetsController();
        if (systeme == null) return;
        systeme.setSystemBarsBehavior(WindowInsetsController.BEHAVIOR_SHOW_TRANSIENT_BARS_BY_SWIPE);
        systeme.hide(WindowInsets.Type.systemBars());
    }

    /**
     * Le sélecteur de fichiers, un dialogue ou le volet des notifications rendent les barres.
     * On les recache dès que l'appli reprend la main.
     */
    @Override
    public void onWindowFocusChanged(boolean auPremierPlan) {
        super.onWindowFocusChanged(auPremierPlan);
        if (auPremierPlan) pleinEcran();
    }

    /** Même hôte et même port que le site : la page reste dans l'appli. */
    private boolean duSite(Uri adresse) {
        String schema = adresse.getScheme();
        if (schema == null) return true;
        if (!schema.equals("http") && !schema.equals("https")) {
            return schema.equals("about") || schema.equals("data") || schema.equals("blob");
        }
        return TextUtils.equals(adresse.getHost(), site.getHost()) && adresse.getPort() == site.getPort();
    }

    private void ouvrirAilleurs(Uri adresse) {
        try {
            startActivity(new Intent(Intent.ACTION_VIEW, adresse));
        } catch (ActivityNotFoundException e) {
            Toast.makeText(this, "Aucune appli pour ouvrir " + adresse, Toast.LENGTH_LONG).show();
        }
    }

    /**
     * Un téléchargement passe par le gestionnaire d'Android, qui le range dans Téléchargements
     * et l'annonce une fois fini. Il fait sa propre requête : il faut lui donner le cookie de
     * session, sans quoi le serveur lui répondrait par la page de connexion.
     */
    private void telecharger(String adresse, String agent, String disposition, String type) {
        String nom = nomDuFichier(adresse, disposition, type);
        try {
            DownloadManager.Request demande = new DownloadManager.Request(Uri.parse(adresse));
            String cookies = CookieManager.getInstance().getCookie(adresse);
            if (cookies != null) demande.addRequestHeader("Cookie", cookies);
            demande.addRequestHeader("User-Agent", agent);
            demande.setTitle(nom);
            demande.setMimeType(type);
            demande.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED);
            demande.setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, nom);
            ((DownloadManager) getSystemService(DOWNLOAD_SERVICE)).enqueue(demande);
            Toast.makeText(this, "Téléchargement : " + nom, Toast.LENGTH_SHORT).show();
        } catch (IllegalArgumentException | SecurityException e) {
            // Un blob: ou un data: : le gestionnaire d'Android ne sait prendre que du HTTP.
            Toast.makeText(this, "Téléchargement impossible : " + nom, Toast.LENGTH_LONG).show();
        }
    }

    private static final Pattern NOM_ETENDU = Pattern.compile("filename\\*\\s*=\\s*(?i:utf-8)''([^;]+)");
    private static final Pattern NOM_SIMPLE = Pattern.compile("filename\\s*=\\s*\"?([^\";]+)\"?");

    /**
     * Le nom du fichier, tel que le serveur le donne : « attachment » comme « inline », et
     * « filename* » (UTF-8, les accents) d'abord. URLUtil.guessFileName ne lit que
     * « attachment; filename=… » : un document servi « inline », ou un lien « download » sans
     * nom, repartait sous le nom de l'adresse — « download_prefa_attachment.php.pdf », puis
     * « …_1.php.pdf » (6 novembre 2026). Il reste le dernier recours.
     */
    private static String nomDuFichier(String adresse, String disposition, String type) {
        if (disposition != null) {
            Matcher etendu = NOM_ETENDU.matcher(disposition);
            if (etendu.find()) {
                try {
                    // « + » est un caractère du nom, pas une espace : URLDecoder le confondrait.
                    String nom = sansChemin(URLDecoder.decode(etendu.group(1).trim().replace("+", "%2B"), "UTF-8"));
                    if (!nom.isEmpty()) return nom;
                } catch (UnsupportedEncodingException | IllegalArgumentException ignore) {
                    // Mal encodé : le nom simple, ou la devinette d'Android.
                }
            }
            Matcher simple = NOM_SIMPLE.matcher(disposition);
            if (simple.find()) {
                String nom = sansChemin(simple.group(1).trim());
                if (!nom.isEmpty()) return nom;
            }
        }
        return URLUtil.guessFileName(adresse, disposition, type);
    }

    /** Pas de dossier dans un nom de fichier : seul le dernier morceau compte. */
    private static String sansChemin(String nom) {
        return nom.substring(Math.max(nom.lastIndexOf('/'), nom.lastIndexOf('\\')) + 1);
    }

    /**
     * Le site ne répond pas : plutôt que la page d'erreur de Chrome, en anglais et sans issue,
     * on dit pourquoi en français, avec un bouton pour réessayer.
     */
    private void afficherInjoignable(CharSequence raison) {
        String adresse = TextUtils.htmlEncode(site.toString());
        String page = "<!doctype html><html lang=\"fr\"><head><meta charset=\"utf-8\">"
                + "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"></head>"
                + "<body style=\"margin:0;min-height:100vh;display:grid;place-items:center;"
                + "background:#141820;color:#e2e7ef;font-family:sans-serif;text-align:center\">"
                + "<main style=\"max-width:440px;padding:24px\">"
                + "<h1 style=\"margin:0 0 12px;font-size:22px;font-weight:600\">Le site ne répond pas</h1>"
                + "<p style=\"margin:0;color:#a2adbd;line-height:1.5\">" + adresse + " est injoignable : "
                + "tablette débranchée, <b>run tablette</b> arrêté sur le PC, ou serveur éteint.</p>"
                + "<p style=\"margin:8px 0 0;color:#6b7686;font-size:13px\">"
                + TextUtils.htmlEncode(String.valueOf(raison)) + "</p>"
                + "<button onclick=\"location.replace('" + adresse + "')\" style=\"margin-top:24px;"
                + "min-height:48px;padding:0 32px;border:0;border-radius:6px;background:#0D79A8;"
                + "color:#fff;font-size:16px\">Réessayer</button>"
                + "</main></body></html>";
        vue.loadDataWithBaseURL(site.toString(), page, "text/html", "utf-8", null);
    }

    @Override
    protected void onActivityResult(int code, int resultat, Intent donnees) {
        if (code != CHOIX_FICHIERS || fichiersAttendus == null) {
            super.onActivityResult(code, resultat, donnees);
            return;
        }
        Uri[] choisis = null;
        if (resultat == RESULT_OK && donnees != null) {
            ClipData plusieurs = donnees.getClipData();
            if (plusieurs != null) {
                choisis = new Uri[plusieurs.getItemCount()];
                for (int i = 0; i < choisis.length; i++) choisis[i] = plusieurs.getItemAt(i).getUri();
            } else if (donnees.getData() != null) {
                choisis = new Uri[] { donnees.getData() };
            }
        }
        // null quand la personne annule : la page le comprend comme « aucun fichier ».
        fichiersAttendus.onReceiveValue(choisis);
        fichiersAttendus = null;
    }

    /** Le bouton retour revient d'abord dans le site, et ne ferme l'appli qu'au bout. */
    @Override
    @SuppressWarnings("deprecation")
    public void onBackPressed() {
        if (vue.canGoBack()) {
            vue.goBack();
        } else {
            super.onBackPressed();
        }
    }

    @Override
    protected void onPause() {
        vue.onPause();
        // La session survit à l'appli fermée : le cookie est écrit sur le disque tout de suite.
        CookieManager.getInstance().flush();
        super.onPause();
    }

    @Override
    protected void onResume() {
        super.onResume();
        vue.onResume();
    }

    @Override
    protected void onSaveInstanceState(Bundle etat) {
        super.onSaveInstanceState(etat);
        vue.saveState(etat);
    }

    @Override
    protected void onDestroy() {
        vue.destroy();
        super.onDestroy();
    }
}
