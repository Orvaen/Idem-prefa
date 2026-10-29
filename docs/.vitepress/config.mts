import { defineConfig } from 'vitepress'

// La documentation d'IDEM Préfabrication. Elle vit dans docs/, à côté du code qu'elle
// décrit, et se sert en mode développement sur http://localhost:8082 — voir
// documentation.md pour la lancer et l'écrire.
export default defineConfig({
  lang: 'fr-FR',
  title: 'IDEM Préfabrication',
  description: "Installer, utiliser et faire évoluer l'application de suivi des demandes de préfabrication",
  cleanUrls: true,
  // La date de dernière modification demande git, que l'image node:alpine n'a pas.
  lastUpdated: false,

  head: [['link', { rel: 'icon', href: '/logo-idem.png' }]],

  themeConfig: {
    logo: { light: '/logo-idem.png', dark: '/logo-idem-sombre.png', alt: 'IDEM' },
    siteTitle: 'Préfabrication',

    nav: [
      { text: 'Démarrer', link: '/guide/presentation', activeMatch: '^/guide/' },
      { text: 'Fonctionnalités', link: '/fonctionnalites/roles', activeMatch: '^/fonctionnalites/' },
      { text: 'Technique', link: '/technique/architecture', activeMatch: '^/technique/' },
      { text: 'Production', link: '/production/', activeMatch: '^/production/' },
      {
        text: 'Référence',
        items: [
          { text: 'Direction artistique', link: '/direction-artistique' },
          { text: "Affectations de l'atelier", link: '/workflow-atelier' },
          { text: 'Cette documentation', link: '/documentation' },
        ],
      },
    ],

    sidebar: [
      {
        text: 'Démarrer',
        items: [
          { text: 'Présentation', link: '/guide/presentation' },
          { text: 'Prérequis', link: '/guide/prerequis' },
          { text: 'Installation', link: '/guide/installation' },
          { text: 'Les commandes run', link: '/guide/commandes' },
        ],
      },
      {
        text: 'Exploiter',
        items: [
          { text: 'Développement et production', link: '/guide/modes' },
          { text: 'Sauvegarde, mise à jour, remise à zéro', link: '/guide/exploitation' },
          { text: 'Dépannage', link: '/guide/depannage' },
        ],
      },
      {
        text: 'Fonctionnalités',
        items: [
          { text: 'Rôles et droits', link: '/fonctionnalites/roles' },
          { text: "L'interface", link: '/fonctionnalites/interface' },
          { text: "L'accueil", link: '/fonctionnalites/accueil' },
          { text: 'Affaires', link: '/fonctionnalites/affaires' },
          { text: 'Demandes de préfabrication', link: '/fonctionnalites/demandes' },
          { text: 'Planning', link: '/fonctionnalites/planning' },
          { text: 'Atelier : plans et affectations', link: '/fonctionnalites/atelier' },
          { text: 'Soudures', link: '/fonctionnalites/soudures' },
          { text: 'Commande matériel', link: '/fonctionnalites/commande-materiel' },
          { text: 'PV de préfabrication', link: '/fonctionnalites/pv' },
          { text: 'Utilisateurs et paramètres', link: '/fonctionnalites/utilisateurs' },
          { text: 'Lecture automatique des documents', link: '/fonctionnalites/lecture-automatique' },
        ],
      },
      {
        text: 'Technique',
        items: [
          { text: 'Architecture', link: '/technique/architecture' },
          { text: 'Base de données', link: '/technique/base-de-donnees' },
          { text: "L'API", link: '/technique/api' },
          { text: 'Sessions et sécurité', link: '/technique/securite' },
          { text: 'Le flux en temps réel', link: '/technique/temps-reel' },
          { text: 'Le journal', link: '/technique/journal' },
          { text: 'Les essais automatiques', link: '/technique/essais' },
          { text: 'Conventions de code', link: '/technique/conventions' },
        ],
      },
      {
        // Écrite pour un agent IA qui exploite le serveur de l'atelier — et suivie telle quelle
        // par un humain. AGENTS.md, à la racine du dépôt, en est le résumé.
        text: 'Production',
        items: [
          { text: 'Les règles, pour un agent', link: '/production/' },
          { text: 'La fiche de la production', link: '/production/reference' },
          { text: 'Mettre en service un serveur', link: '/production/mise-en-service' },
          { text: 'Mettre à jour', link: '/production/mise-a-jour' },
          { text: 'Sauvegarder et restaurer', link: '/production/sauvegarde' },
          { text: 'Surveiller et dépanner', link: '/production/surveillance' },
          { text: 'Durcir la production', link: '/production/securite' },
        ],
      },
      {
        text: 'Référence',
        items: [
          { text: 'Direction artistique', link: '/direction-artistique' },
          { text: "Affectations de l'atelier", link: '/workflow-atelier' },
          { text: 'Cette documentation', link: '/documentation' },
        ],
      },
    ],

    socialLinks: [{ icon: 'github', link: 'https://github.com/rayan-studio/Idem-prefa' }],

    search: {
      provider: 'local',
      options: {
        translations: {
          button: { buttonText: 'Rechercher', buttonAriaLabel: 'Rechercher dans la documentation' },
          modal: {
            displayDetails: 'Afficher le détail',
            resetButtonTitle: 'Effacer la recherche',
            backButtonTitle: 'Fermer la recherche',
            noResultsText: 'Aucun résultat pour',
            footer: { selectText: 'ouvrir', navigateText: 'parcourir', closeText: 'fermer' },
          },
        },
      },
    },

    outline: { level: [2, 3], label: 'Sur cette page' },
    docFooter: { prev: 'Page précédente', next: 'Page suivante' },
    darkModeSwitchLabel: 'Apparence',
    lightModeSwitchTitle: 'Passer au thème clair',
    darkModeSwitchTitle: 'Passer au thème sombre',
    sidebarMenuLabel: 'Menu',
    returnToTopLabel: 'Retour en haut',
    externalLinkIcon: true,
    notFound: {
      title: 'PAGE INTROUVABLE',
      quote: "Cette adresse ne mène à aucune page de la documentation.",
      linkLabel: "Revenir à l'accueil",
      linkText: "Revenir à l'accueil",
    },
    footer: {
      message: 'Documentation interne — IDEM Préfabrication.',
    },
  },

  vite: {
    server: {
      // docs/ est monté depuis Windows dans le conteneur : les changements de fichiers n'y
      // arrivent pas comme événements, il faut aller les voir. Sans ce relevé, la page ne
      // se rechargeait pas après un enregistrement.
      watch: { usePolling: true, interval: 300 },
    },
  },
})
