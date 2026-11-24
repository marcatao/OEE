package br.com.alumbra.oee.maquinas

import br.com.alumbra.oee.api.ApiPortal
import org.json.JSONObject
import java.net.URLEncoder

/** Chamadas da função Máquinas à API do portal (precisam da rede da fábrica). */
class RepositorioMaquinas(private val pastaGuardada: java.io.File? = null, private val token: () -> String?) {

    suspend fun lista(): List<MaquinaResumo> = maquinasDe(ApiPortal.get("maquinas", token()))

    /** Detalhe da máquina; a resposta fica guardada no celular para abrir sem rede (último dado recebido). */
    suspend fun detalhe(id: Int): DetalheMaquina {
        val json = ApiPortal.get("maquinas/$id", token())
        guardar(id, json)
        return detalheDe(json)
    }

    /** Último detalhe recebido desta máquina (sem rede), ou null. */
    fun detalheGuardado(id: Int): DetalheMaquina? = pastaGuardada?.let { pasta ->
        runCatching { detalheDe(JSONObject(java.io.File(pasta, "maquina_$id.json").readText())) }.getOrNull()
    }

    private fun guardar(id: Int, json: JSONObject) {
        pastaGuardada?.let { pasta -> runCatching { java.io.File(pasta, "maquina_$id.json").writeText(json.toString()) } }
    }

    suspend fun informarMotivo(apontamentoId: Int, legendaId: Int): DetalheMaquina =
        detalheDe(ApiPortal.post("apontamentos/$apontamentoId/motivo", token(), JSONObject().put("legenda_id", legendaId)))

    /** [modo]: "atual" (o apontamento em andamento inteiro passa para a OP nova) ou "novo" (encerra agora e começa outro). */
    suspend fun trocarOp(maquinaId: Int, numero: String, modo: String): DetalheMaquina =
        detalheDe(ApiPortal.post("maquinas/$maquinaId/op", token(), JSONObject().put("numero", numero).put("modo", modo)))

    suspend fun alterarCavidades(ferramentaId: Int, maquinaId: Int, cavidades: Int): DetalheMaquina =
        detalheDe(
            ApiPortal.post(
                "ferramentas/$ferramentaId/cavidades", token(),
                JSONObject().put("cavidades", cavidades).put("maquina_id", maquinaId),
            ),
        )

    /** Detalhes da OP (kardex, empenhos, engenharia); guardados no celular para abrir sem rede. */
    suspend fun detalhesOp(opId: Int): DetalhesOp {
        val json = ApiPortal.get("ops/$opId/detalhes", token())
        pastaGuardada?.let { pasta -> runCatching { java.io.File(pasta, "op_$opId.json").writeText(json.toString()) } }
        return detalhesOpDe(json)
    }

    fun detalhesOpGuardado(opId: Int): DetalhesOp? = pastaGuardada?.let { pasta ->
        runCatching { detalhesOpDe(JSONObject(java.io.File(pasta, "op_$opId.json").readText())) }.getOrNull()
    }

    suspend fun apontamento(id: Int): ApontamentoDetalhe = apontamentoDetalheDe(ApiPortal.get("apontamentos/$id", token()))

    /** O operador altera só a OP do apontamento (só OPs abertas). */
    suspend fun alterarOpDoApontamento(id: Int, numero: String): ApontamentoDetalhe =
        apontamentoDetalheDe(ApiPortal.post("apontamentos/$id/op", token(), JSONObject().put("numero", numero)))

    suspend fun perdas(opId: Int): PerdasOp = perdasOpDe(ApiPortal.get("ops/$opId/perdas", token()))

    /** Inclui uma perda: só a quantidade OU só o peso (o portal calcula o outro pelo PU). Devolve a lista atualizada. */
    suspend fun apontarPerda(opId: Int, quantidade: Double?, peso: Double?, dia: String, maquinaId: Int, observacao: String?): PerdasOp {
        val corpo = JSONObject().put("dia", dia).put("maquina_id", maquinaId)
        if (quantidade != null) corpo.put("quantidade", quantidade) else if (peso != null) corpo.put("peso", peso)
        if (!observacao.isNullOrBlank()) corpo.put("observacao", observacao.trim())
        return perdasOpDe(ApiPortal.post("ops/$opId/perdas", token(), corpo))
    }

    suspend fun excluirPerda(id: Int): PerdasOp = perdasOpDe(ApiPortal.delete("perdas/$id", token()))

    suspend fun buscarOps(busca: String): List<OpResumo> =
        opsDe(ApiPortal.get("ops?busca=" + URLEncoder.encode(busca, "UTF-8"), token()))
}
