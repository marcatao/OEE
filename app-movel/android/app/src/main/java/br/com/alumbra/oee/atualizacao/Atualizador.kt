package br.com.alumbra.oee.atualizacao

import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Build
import android.provider.Settings
import androidx.core.content.FileProvider
import br.com.alumbra.oee.BuildConfig
import br.com.alumbra.oee.api.ApiPortal
import br.com.alumbra.oee.api.ErroApi
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONObject
import java.io.File
import java.security.MessageDigest

/** Versão mais nova publicada no portal (Segurança › Aplicativo). */
data class InfoAtualizacao(
    val codigo: Int,
    val nome: String,
    val tamanho: Long,
    val sha256: String,
    val observacao: String?,
    val temNova: Boolean,
    val obrigatoria: Boolean,
)

/**
 * Atualização do app pelo portal: pergunta a versão mais nova (/api/app/versao), baixa o APK (/api/app/versao/apk)
 * para o cache, confere o SHA-256 e abre o instalador do Android (o usuário toca em "Instalar"; num celular comum o
 * Android não deixa instalar sem essa confirmação). Só instala por cima um APK com a mesma assinatura e build maior.
 */
object Atualizador {

    fun deJson(j: JSONObject): InfoAtualizacao? {
        val atual = j.optJSONObject("atual") ?: return null
        return InfoAtualizacao(
            codigo = atual.optInt("codigo"),
            nome = atual.optString("nome"),
            tamanho = atual.optLong("tamanho"),
            sha256 = atual.optString("sha256"),
            observacao = if (atual.isNull("observacao")) null else atual.optString("observacao").takeIf { it.isNotBlank() },
            temNova = j.optBoolean("tem_nova") && atual.optInt("codigo") > BuildConfig.VERSION_CODE,
            obrigatoria = j.optBoolean("obrigatoria"),
        )
    }

    suspend fun verificar(token: String): InfoAtualizacao? =
        deJson(ApiPortal.get("versao?instalado=${BuildConfig.VERSION_CODE}", token))

    private fun arquivo(ctx: Context, info: InfoAtualizacao) = File(File(ctx.cacheDir, "atualizacao"), "oee-alumbra-${info.nome}-${info.codigo}.apk")

    /** Baixa (ou reaproveita o já baixado e conferido) o APK da versão e devolve o arquivo. */
    suspend fun baixar(ctx: Context, token: String, info: InfoAtualizacao, aoProgresso: (Float) -> Unit): File {
        val destino = arquivo(ctx, info)
        if (destino.exists() && sha256(destino).equals(info.sha256, ignoreCase = true)) return destino
        // Só uma versão guardada por vez.
        destino.parentFile?.listFiles()?.forEach { it.delete() }
        ApiPortal.baixar("versao/apk", token, destino) { lidos, total ->
            val t = if (total > 0) total else info.tamanho
            if (t > 0) aoProgresso((lidos.toFloat() / t).coerceIn(0f, 1f))
        }
        if (!sha256(destino).equals(info.sha256, ignoreCase = true)) {
            destino.delete()
            throw ErroApi("O arquivo baixado veio diferente do publicado. Tente de novo.")
        }
        return destino
    }

    /** O Android pede uma vez "Instalar apps desconhecidos" para o OEE Alumbra. */
    fun podeInstalar(ctx: Context): Boolean =
        Build.VERSION.SDK_INT < Build.VERSION_CODES.O || ctx.packageManager.canRequestPackageInstalls()

    fun abrirPermissao(ctx: Context) {
        ctx.startActivity(
            Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES, Uri.parse("package:${ctx.packageName}"))
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK),
        )
    }

    /** Abre o instalador do Android com o APK baixado. */
    fun instalar(ctx: Context, apk: File) {
        val uri = FileProvider.getUriForFile(ctx, "${ctx.packageName}.arquivos", apk)
        ctx.startActivity(
            Intent(Intent.ACTION_VIEW)
                .setDataAndType(uri, "application/vnd.android.package-archive")
                .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION or Intent.FLAG_ACTIVITY_NEW_TASK),
        )
    }

    private suspend fun sha256(f: File): String = withContext(Dispatchers.IO) {
        val md = MessageDigest.getInstance("SHA-256")
        f.inputStream().use { entrada ->
            val buffer = ByteArray(64 * 1024)
            while (true) {
                val n = entrada.read(buffer)
                if (n < 0) break
                md.update(buffer, 0, n)
            }
        }
        md.digest().joinToString("") { "%02x".format(it) }
    }
}
