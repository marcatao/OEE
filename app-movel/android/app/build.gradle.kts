import java.util.Properties

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
    id("org.jetbrains.kotlin.plugin.compose")
    // Capturas das telas sem celular (./gradlew recordPaparazziDebug → app/src/test/snapshots).
    id("app.cash.paparazzi")
}

val versao = Properties().apply { rootProject.file("versao.properties").inputStream().use { load(it) } }
val assinatura = Properties().apply { rootProject.file("keystore/assinatura.properties").inputStream().use { load(it) } }

android {
    namespace = "br.com.alumbra.oee"
    compileSdk = 35

    defaultConfig {
        applicationId = "br.com.alumbra.oee"
        minSdk = 26
        targetSdk = 35
        versionCode = versao.getProperty("codigo").toInt()
        versionName = versao.getProperty("nome")
        // Endereço do portal e rede Wi-Fi da fábrica.
        buildConfigField("String", "PORTAL_URL", "\"https://www.alumbra.com.br/oee\"")
        buildConfigField("String", "REDE_WIFI", "\"wlan_alumbra\"")
    }

    signingConfigs {
        create("interna") {
            storeFile = rootProject.file("keystore/" + assinatura.getProperty("arquivo"))
            storePassword = assinatura.getProperty("senha")
            keyAlias = assinatura.getProperty("apelido")
            keyPassword = assinatura.getProperty("senha")
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            signingConfig = signingConfigs.getByName("interna")
        }
        debug {
            signingConfig = signingConfigs.getByName("interna")
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions { jvmTarget = "17" }
    buildFeatures {
        compose = true
        buildConfig = true
    }
}

// O APK sai com o nome e a versão: oee-alumbra-0.1.0-1.apk
android.applicationVariants.all {
    outputs.all {
        (this as com.android.build.gradle.internal.api.BaseVariantOutputImpl).outputFileName =
            "oee-alumbra-${versionName}-${versionCode}.apk"
    }
}

dependencies {
    val compose = platform("androidx.compose:compose-bom:2024.12.01")
    implementation(compose)
    implementation("androidx.core:core-ktx:1.15.0")
    implementation("androidx.activity:activity-compose:1.9.3")
    implementation("androidx.lifecycle:lifecycle-runtime-compose:2.8.7")
    implementation("androidx.browser:browser:1.8.0")
    implementation("androidx.security:security-crypto:1.1.0-alpha06")
    implementation("androidx.compose.ui:ui")
    implementation("androidx.compose.material3:material3")
    implementation("androidx.compose.ui:ui-tooling-preview")
    debugImplementation("androidx.compose.ui:ui-tooling")
    testImplementation("junit:junit:4.13.2")
}
