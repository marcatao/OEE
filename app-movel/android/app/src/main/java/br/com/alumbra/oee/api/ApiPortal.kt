package br.com.alumbra.oee.api

import br.com.alumbra.oee.BuildConfig
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL

/** Erro de uma chamada à API do portal. [semAcesso] = token recusado (401): o app volta para o login. */
class ErroApi(mensagem: String, val semAcesso: Boolean = false) : Exception(mensagem)

/** Chamadas à API do portal (/api/app/...). Sempre em JSON; o token vai em Authorization e em X-Token-App. */
object ApiPortal {

    private val base = BuildConfig.PORTAL_URL.trimEnd('/') + "/api/app/"

    suspend fun get(caminho: String, token: String?): JSONObject = chamar("GET", caminho, token, null)

    suspend fun post(caminho: String, token: String?, corpo: JSONObject = JSONObject()): JSONObject =
        chamar("POST", caminho, token, corpo)

    private suspend fun chamar(metodo: String, caminho: String, token: String?, corpo: JSONObject?): JSONObject =
        withContext(Dispatchers.IO) {
            val conexao = (URL(base + caminho).openConnection() as HttpURLConnection).apply {
                requestMethod = metodo
                connectTimeout = 10_000
                readTimeout = 20_000
                setRequestProperty("Accept", "application/json")
                setRequestProperty("User-Agent", "OEE-Alumbra-App/${BuildConfig.VERSION_NAME}")
                if (token != null) {
                    setRequestProperty("Authorization", "Bearer $token")
                    setRequestProperty("X-Token-App", token)
                }
                if (corpo != null) {
                    doOutput = true
                    setRequestProperty("Content-Type", "application/json; charset=utf-8")
                }
            }
            try {
                if (corpo != null) conexao.outputStream.use { it.write(corpo.toString().toByteArray()) }
                val codigo = conexao.responseCode
                val texto = (if (codigo in 200..299) conexao.inputStream else conexao.errorStream)
                    ?.bufferedReader()?.use { it.readText() }.orEmpty()
                val json = runCatching { JSONObject(texto) }.getOrElse { JSONObject() }
                if (codigo == 401) throw ErroApi(json.optString("mensagem", "Entre de novo no aplicativo."), semAcesso = true)
                if (codigo !in 200..299) {
                    // Laravel: "mensagem" (nossa), "message" (abort/validação) e "errors" (campos).
                    val campos = json.optJSONObject("errors")?.let { e -> e.keys().asSequence().mapNotNull { e.optJSONArray(it)?.optString(0) }.firstOrNull() }
                    val texto = json.optString("mensagem").ifEmpty { campos ?: json.optString("message") }
                    throw ErroApi(texto.ifEmpty { "O portal respondeu com erro ($codigo)." } + if (codigo == 403) " (403)" else "")
                }
                json
            } catch (e: ErroApi) {
                throw e
            } catch (e: Exception) {
                throw ErroApi("Sem comunicação com o portal: " + br.com.alumbra.oee.rede.VerificadorRede.motivoDa(e))
            } finally {
                conexao.disconnect()
            }
        }
}
