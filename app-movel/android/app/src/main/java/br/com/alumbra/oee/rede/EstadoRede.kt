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
    /** Motivo da falha ao falar com o portal (certificado, tempo esgotado, endereço...), para diagnóstico. */
    val motivoPortal: String? = null,
) {
    /** Compara sem diferenciar maiúsculas e minúsculas (o nome da rede é "Wlan_alumbra"). */
    val naRedeDaFabrica: Boolean
        get() = noWifi && nomeRede?.trim().equals(BuildConfig.REDE_WIFI, ignoreCase = true)
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

    /** Confere se o portal responde (qualquer resposta HTTP conta como acessível). Devolve o motivo da falha. */
    suspend fun portalResponde(): Pair<Boolean, String?> = withContext(Dispatchers.IO) {
        try {
            val conexao = URL(BuildConfig.PORTAL_URL).openConnection() as HttpURLConnection
            conexao.connectTimeout = 10_000
            conexao.readTimeout = 10_000
            conexao.instanceFollowRedirects = false
            conexao.requestMethod = "GET"
            val codigo = conexao.responseCode
            conexao.disconnect()
            (codigo in 100..599) to null
        } catch (e: Exception) {
            false to motivoDa(e)
        }
    }

    /** Explica a falha de rede em português (aparece no app para facilitar o suporte). */
    fun motivoDa(e: Throwable): String {
        val causas = generateSequence(e) { it.cause }.toList()
        return when {
            causas.any { it is javax.net.ssl.SSLHandshakeException || it is java.security.cert.CertificateException } ->
                "Certificado do portal não reconhecido por este aparelho (${causas.last().javaClass.simpleName})."
            causas.any { it is java.net.UnknownHostException } -> "Endereço do portal não encontrado (DNS)."
            causas.any { it is java.net.SocketTimeoutException } -> "O portal demorou demais para responder."
            causas.any { it is java.net.ConnectException } -> "Conexão recusada pelo portal ou pelo firewall."
            else -> "${e.javaClass.simpleName}: ${e.message ?: "sem detalhe"}"
        }
    }
}
