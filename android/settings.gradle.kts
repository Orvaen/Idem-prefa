// L'appli Android de la tablette d'atelier : le site, en plein écran, dans une WebView.
// Construite et installée par « run apk » (terminal.json).

pluginManagement {
    repositories {
        google()
        mavenCentral()
        gradlePluginPortal()
    }
}

dependencyResolutionManagement {
    repositoriesMode.set(RepositoriesMode.FAIL_ON_PROJECT_REPOS)
    repositories {
        google()
        mavenCentral()
    }
}

rootProject.name = "idem-prefa"
include(":app")
