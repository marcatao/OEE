package br.com.alumbra.oee.os

import br.com.alumbra.oee.api.ApiPortal
import org.json.JSONObject

/** Chamadas da função Ordens de serviço à API do portal. */
class RepositorioOs(private val token: () -> String?) {

    suspend fun resumo(): ResumoOs = resumoDe(ApiPortal.get("os/resumo", token()))

    suspend fun solicitacoes(): Pair<List<SsApp>, List<SsApp>> =
        ApiPortal.get("os/solicitacoes", token()).let { listaSsDe(it, "pendentes") to listaSsDe(it, "minhas") }

    suspend fun ordens(): List<OsApp> = listaOsDe(ApiPortal.get("os/ordens", token()))

    suspend fun avaliacoes(): List<OsApp> = listaOsDe(ApiPortal.get("os/avaliacoes", token()))

    suspend fun detalhe(id: Int): OsApp = osDe(ApiPortal.get("os/ordens/$id", token()))

    suspend fun assumir(ssId: Int): OsApp = osDe(ApiPortal.post("os/solicitacoes/$ssId/assumir", token()).getJSONObject("ordem"))

    /** Consulta leve feita a cada toque: SS que ainda devem tocar (vazia = parar). */
    suspend fun alerta(): List<SsApp> = alertaDe(ApiPortal.get("os/alerta", token()))

    /** Recusa a SS: só ela para de tocar para este mantenedor. Devolve o que ainda deve tocar. */
    suspend fun recusar(ssId: Int): List<SsApp> = alertaDe(ApiPortal.post("os/solicitacoes/$ssId/recusar", token()))

    suspend fun rejeitar(ssId: Int, motivo: String) {
        ApiPortal.post("os/solicitacoes/$ssId/rejeitar", token(), JSONObject().put("motivo", motivo))
    }

    suspend fun formulario(): FormularioSs = formularioDe(ApiPortal.get("os/formulario", token()))

    suspend fun abrirSs(dados: JSONObject): String = ApiPortal.post("os/solicitacoes", token(), dados).optString("mensagem")

    suspend fun iniciar(osId: Int): OsApp = osDe(ApiPortal.post("os/ordens/$osId/iniciar", token()))

    suspend fun encerrar(p: EncerramentoPendente): OsApp = osDe(
        ApiPortal.post(
            "os/apontamentos/${p.apontamentoId}/encerrar", token(),
            JSONObject().put("descricao", p.descricao).put("concluir_os", p.concluir)
                .put("maquina_parada", p.maquinaParada ?: JSONObject.NULL).put("fim_utc", p.fimUtc),
        ),
    )

    suspend fun acao(osId: Int, acao: String, obs: String?): OsApp =
        osDe(ApiPortal.post("os/ordens/$osId/acao", token(), JSONObject().put("acao", acao).put("observacao", obs ?: JSONObject.NULL)))

    /** Grava as respostas do checklist (só os itens enviados). Devolve a OS atualizada. */
    suspend fun responderChecklist(osId: Int, itens: List<ItemChecklist>): OsApp {
        val respostas = org.json.JSONArray()
        itens.forEach {
            respostas.put(JSONObject().put("id", it.id).put("resposta", it.resposta ?: JSONObject.NULL)
                .put("observacao", it.observacao?.takeIf { o -> o.isNotBlank() } ?: JSONObject.NULL))
        }
        return osDe(ApiPortal.post("os/ordens/$osId/checklist", token(), JSONObject().put("respostas", respostas)))
    }

    /** "Adicionar mantenedor": inclui outro mantenedor da área na OS. Devolve a OS atualizada. */
    suspend fun adicionarMantenedor(osId: Int, mantenedorId: Int): OsApp =
        osDe(ApiPortal.post("os/ordens/$osId/mantenedores", token(), JSONObject().put("mantenedor_id", mantenedorId)))

    suspend fun avaliar(osId: Int, avaliacao: String, obs: String?, assinaturaPng: String, nome: String): String =
        ApiPortal.post(
            "os/ordens/$osId/avaliar", token(),
            JSONObject().put("avaliacao", avaliacao).put("observacao", obs ?: JSONObject.NULL)
                .put("assinatura", assinaturaPng).put("nome", nome),
        ).optString("mensagem")
}

/** Encerramento do apontamento guardado no celular até o portal confirmar (sem rede, o fim não se perde). */
data class EncerramentoPendente(
    val apontamentoId: Int,
    val osId: Int,
    val descricao: String,
    val concluir: Boolean,
    val maquinaParada: Boolean?,
    val fimUtc: String,
) {
    fun json(): String = JSONObject().put("ap", apontamentoId).put("os", osId).put("descricao", descricao).put("concluir", concluir)
        .put("parada", maquinaParada ?: JSONObject.NULL).put("fim", fimUtc).toString()

    companion object {
        fun de(texto: String?): EncerramentoPendente? = texto?.let {
            runCatching {
                val j = JSONObject(it)
                EncerramentoPendente(
                    j.getInt("ap"), j.getInt("os"), j.getString("descricao"), j.getBoolean("concluir"),
                    if (j.isNull("parada")) null else j.getBoolean("parada"), j.getString("fim"),
                )
            }.getOrNull()
        }
    }
}
