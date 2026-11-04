plugins {
    id("com.android.application")
}

// L'adresse que l'appli ouvre. Par défaut localhost:8080 : la tablette branchée en USB,
// avec les tunnels de « run apk » / « run tablette ». Pour le serveur de l'atelier :
//   run apk -- url=http://192.168.1.20:8080/
val urlSite = (findProperty("urlSite") as String?)?.takeIf { it.isNotBlank() } ?: "http://localhost:8080/"

android {
    namespace = "com.idem.prefabrication"
    compileSdk = 35

    defaultConfig {
        applicationId = "com.idem.prefabrication"
        // Android 11 : la plus ancienne qui donne les encarts du système (barres, clavier)
        // par une seule API. Les tablettes d'atelier sont bien plus récentes.
        minSdk = 30
        targetSdk = 35
        versionCode = 1
        versionName = "1.0"
        buildConfigField("String", "URL_SITE", "\"$urlSite\"")
    }

    buildFeatures {
        buildConfig = true
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
}
