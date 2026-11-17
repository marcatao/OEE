package br.com.alumbra.oee.sessao

import android.content.Context
import android.content.SharedPreferences
import android.util.Base64
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey
import org.json.JSONObject
import java.security.MessageDigest
import java.security.SecureRandom

/** Usuário logado no app (o que o portal devolve em /api/app/eu). */
data class UsuarioApp(
    val nome: String,
    val primeiroNome: String,
    val email: String,
    val perfis: List<String>,
    val unidades: List<String>,
    val operador: Boolean,
    val timeline: Boolean,
    val mantenedor: Boolean,
    val aprovarOs: Boolean,
    val solicitarOs: Boolean,
) {
    companion object {
        fun deJson(json: JSONObject): UsuarioApp {
            val u = json.getJSONObject("usuario")
            val funcoes = u.optJSONObject("funcoes") ?: JSONObject()
            val perfis = u.optJSONArray("perfis")
            val unidades = u.optJSONArray("unidades")
            return UsuarioApp(
                nome = u.optString("nome"),
                primeiroNome = u.optString("primeiro_nome", u.optString("nome")),
                email = u.optString("email"),
                perfis = List(perfis?.length() ?: 0) { perfis!!.getJSONObject(it).optString("rotulo") },
                unidades = List(unidades?.length() ?: 0) { unidades!!.getString(it) },
                operador = funcoes.optBoolean("operador"),
                timeline = funcoes.optBoolean("timeline"),
                mantenedor = funcoes.optBoolean("mantenedor"),
                aprovarOs = funcoes.optBoolean("aprovar_os"),
                solicitarOs = funcoes.optBoolean("solicitar_os"),
            )
        }
    }
}

/**
 * Sessão do app, guardada criptografada no celular: token da API, dados do usuário e a hora da última sincronização
 * com o portal. O mesmo celular serve a várias pessoas, uma de cada vez (troca só pelo "Sair").
 */
class Sessao(contexto: Context) {

    private val prefs: SharedPreferences = EncryptedSharedPreferences.create(
        contexto,
        "sessao_oee",
        MasterKey.Builder(contexto).setKeyScheme(MasterKey.KeyScheme.AES256_GCM).build(),
        EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
        EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM,
    )

    val token: String? get() = prefs.getString("token", null)

    val usuario: UsuarioApp?
        get() = prefs.getString("usuario", null)?.let { runCatching { UsuarioApp.deJson(JSONObject(it)) }.getOrNull() }

    /** Hora (relógio do celular, em ms) da última conversa bem-sucedida com o portal. */
    val ultimaSincronizacao: Long get() = prefs.getLong("ultima_sincronizacao", 0L)

    val horasOffline: Int get() = prefs.getInt("horas_offline", 48)

    /** Passou do limite sem sincronizar (ou o relógio do celular voltou para trás): o app bloqueia. */
    fun bloqueadaPorTempo(agora: Long = System.currentTimeMillis()): Boolean {
        val ultima = ultimaSincronizacao
        return ultima == 0L || agora < ultima || agora - ultima > horasOffline * 3_600_000L
    }

    fun gravarLogin(token: String, resposta: JSONObject) {
        prefs.edit().putString("token", token).apply()
        gravarSincronizacao(resposta)
    }

    /** Resposta de /api/app/eu (ou do login): atualiza o usuário e marca a sincronização. */
    fun gravarSincronizacao(resposta: JSONObject) {
        prefs.edit()
            .putString("usuario", resposta.toString())
            .putInt("horas_offline", resposta.optInt("horas_offline", 48))
            .putLong("ultima_sincronizacao", System.currentTimeMillis())
            .apply()
    }

    /** Encerramento de apontamento de OS ainda não confirmado pelo portal (JSON de EncerramentoPendente). */
    var encerramentoPendente: String?
        get() = prefs.getString("encerramento_pendente", null)
        set(valor) {
            prefs.edit().apply { if (valor == null) remove("encerramento_pendente") else putString("encerramento_pendente", valor) }.apply()
        }

    fun sair() {
        prefs.edit().clear().apply()
    }

    // --- Login pelo portal (como o PKCE): o segredo fica no celular; o portal recebe só o hash dele. ---

    fun novoVerificador(): String {
        val bytes = ByteArray(48).also { SecureRandom().nextBytes(it) }
        val verificador = Base64.encodeToString(bytes, Base64.URL_SAFE or Base64.NO_WRAP or Base64.NO_PADDING)
        prefs.edit().putString("verificador", verificador).apply()
        return verificador
    }

    fun verificadorPendente(): String? = prefs.getString("verificador", null)

    fun limparVerificador() {
        prefs.edit().remove("verificador").apply()
    }

    companion object {
        fun desafioDe(verificador: String): String {
            val hash = MessageDigest.getInstance("SHA-256").digest(verificador.toByteArray(Charsets.US_ASCII))
            return Base64.encodeToString(hash, Base64.URL_SAFE or Base64.NO_WRAP or Base64.NO_PADDING)
        }
    }
}
