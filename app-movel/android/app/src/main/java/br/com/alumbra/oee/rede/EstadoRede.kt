package br.com.alumbra.oee.rede

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.net.wifi.WifiManager
import br.com.alumbra.oee.BuildConfig
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import java.net.HttpURLConnection
import java.net.URL

/** Situação da rede: o app só sincroniza com o portal na rede Wi-Fi da fábrica (BuildConfig.REDE_WIFI). */
data class EstadoRede(
    val noWifi: Boolean,
    val nomeRede: String?,
    val semPermissaoLocalizacao: Boolean,
    val portalAcessivel: Boolean?,
) {
    val naRedeDaFabrica: Boolean get() = noWifi && nomeRede == BuildConfig.REDE_WIFI
}

object VerificadorRede {

    @Suppress("DEPRECATION")
    fun estadoAtual(contexto: Context): EstadoRede {
        val conectividade = contexto.getSystemService(ConnectivityManager::class.java)
        val capacidades = conectividade.getNetworkCapabilities(conectividade.activeNetwork)
        val noWifi = capacidades?.hasTransport(NetworkCapabilities.TRANSPORT_WIFI) == true
        val temPermissao = contexto.checkSelfPermission(Manifest.permission.ACCESS_FINE_LOCATION) ==
            PackageManager.PERMISSION_GRANTED

        var nome: String? = null
        if (noWifi && temPermissao) {
            val wifi = contexto.applicationContext.getSystemService(WifiManager::class.java)
            nome = wifi.connectionInfo?.ssid?.trim('"')?.takeUnless { it == "<unknown ssid>" }
        }
        return EstadoRede(noWifi, nome, !temPermissao, portalAcessivel = null)
    }

    /** Confere se o portal responde (qualquer resposta HTTP conta como acessível). */
    suspend fun portalResponde(): Boolean = withContext(Dispatchers.IO) {
        try {
            val conexao = URL(BuildConfig.PORTAL_URL).openConnection() as HttpURLConnection
            conexao.connectTimeout = 5000
            conexao.readTimeout = 5000
            conexao.instanceFollowRedirects = false
            conexao.requestMethod = "GET"
            val codigo = conexao.responseCode
            conexao.disconnect()
            codigo in 100..599
        } catch (e: Exception) {
            false
        }
    }
}
