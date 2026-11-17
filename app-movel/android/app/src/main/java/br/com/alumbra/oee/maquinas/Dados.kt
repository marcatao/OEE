package br.com.alumbra.oee.maquinas

import org.json.JSONArray
import org.json.JSONObject

/** Dados da função Máquinas, como a API do portal (/api/app/maquinas...) devolve. */

data class LegendaApp(val id: Int, val codigo: String, val descricao: String, val tipo: String, val cor: String?)

data class OpResumo(
    val id: Int,
    val numero: String,
    val produto: String,
    val descricao: String?,
    val statusRotulo: String,
    val quantidade: Double,
    val produzido: Double,
)

data class MaquinaResumo(
    val id: Int,
    val codigo: String,
    val nome: String,
    val local: String,
    val situacao: String?,
    val legenda: LegendaApp?,
    val op: OpResumo?,
    val semMotivo: Int,
)

data class ApontamentoApp(
    val id: Int,
    val legenda: LegendaApp?,
    val semMotivo: Boolean,
    val dia: String?,
    val inicio: String?,
    val fim: String?,
    val inicioUtc: String?,
    val fimUtc: String?,
    val minutos: Double,
    val op: String?,
)

data class OperacaoApp(val operacao: String, val descricao: String, val maquina: String, val ferramenta: String, val destaMaquina: Boolean)

data class MoldeApp(val id: Int, val codigo: String, val descricao: String, val cavidades: Int, val cavidadesAtivas: Int)

data class OpDetalhe(val resumo: OpResumo, val roteiro: String?, val operacoes: List<OperacaoApp>, val moldes: List<MoldeApp>)

data class DetalheMaquina(
    val id: Int,
    val codigo: String,
    val nome: String,
    val local: String,
    val situacao: String?,
    val situacaoDesde: String?,
    val legenda: LegendaApp?,
    val apontamentos: List<ApontamentoApp>,
    val semMotivo: List<ApontamentoApp>,
    val op: OpDetalhe?,
    val legendasMotivo: List<LegendaApp>,
    val servidorAgora: String,
    val segundosAtualizacao: Int,
    /** Período mostrado: sempre só HOJE (01:00–23:59, como a Timeline do portal). */
    val periodoDe: String? = null,
    val periodoAte: String? = null,
    val periodoRotulo: String = "Hoje",
)

private fun JSONObject.texto(nome: String): String? = if (isNull(nome)) null else optString(nome)

private inline fun <T> JSONArray?.lista(conv: (JSONObject) -> T): List<T> =
    if (this == null) emptyList() else List(length()) { conv(getJSONObject(it)) }

fun legendaDe(j: JSONObject?): LegendaApp? = j?.let {
    LegendaApp(it.optInt("id"), it.optString("codigo"), it.optString("descricao"), it.optString("tipo"), it.texto("cor"))
}

fun opResumoDe(j: JSONObject?): OpResumo? = j?.let {
    OpResumo(
        it.optInt("id"), it.optString("numero"), it.optString("produto"), it.texto("descricao"),
        it.optString("status_rotulo"), it.optDouble("quantidade", 0.0), it.optDouble("produzido", 0.0),
    )
}

fun maquinasDe(j: JSONObject): List<MaquinaResumo> = j.optJSONArray("maquinas").lista {
    MaquinaResumo(
        it.optInt("id"), it.optString("codigo"), it.optString("nome"), it.optString("local"), it.texto("situacao"),
        legendaDe(it.optJSONObject("legenda")), opResumoDe(it.optJSONObject("op")), it.optInt("sem_motivo"),
    )
}

fun opsDe(j: JSONObject): List<OpResumo> = j.optJSONArray("ops").lista { opResumoDe(it)!! }

private fun apontamentoDe(j: JSONObject) = ApontamentoApp(
    j.optInt("id"), legendaDe(j.optJSONObject("legenda")), j.optBoolean("sem_motivo"), j.texto("dia"), j.texto("inicio"),
    j.texto("fim"), j.texto("inicio_utc"), j.texto("fim_utc"), j.optDouble("minutos", 0.0), j.texto("op"),
)

fun detalheDe(j: JSONObject): DetalheMaquina {
    val m = j.getJSONObject("maquina")
    val op = j.optJSONObject("op")?.let { o ->
        OpDetalhe(
            resumo = opResumoDe(o)!!,
            roteiro = o.optJSONObject("roteiro")?.texto("codigo"),
            operacoes = o.optJSONObject("roteiro")?.optJSONArray("operacoes").lista {
                OperacaoApp(it.optString("operacao"), it.optString("descricao"), it.optString("maquina"),
                    it.optString("ferramenta"), it.optBoolean("desta_maquina"))
            },
            moldes = o.optJSONArray("moldes").lista {
                MoldeApp(it.optInt("id"), it.optString("codigo"), it.optString("descricao"), it.optInt("cavidades"), it.optInt("cavidades_ativas"))
            },
        )
    }
    return DetalheMaquina(
        id = m.optInt("id"), codigo = m.optString("codigo"), nome = m.optString("nome"), local = m.optString("local"),
        situacao = m.texto("situacao"), situacaoDesde = m.texto("situacao_desde"), legenda = legendaDe(m.optJSONObject("legenda")),
        apontamentos = j.optJSONArray("apontamentos").lista(::apontamentoDe),
        semMotivo = j.optJSONArray("sem_motivo").lista(::apontamentoDe),
        op = op,
        legendasMotivo = j.optJSONArray("legendas_motivo").lista { legendaDe(it)!! },
        servidorAgora = j.optString("servidor_agora"),
        segundosAtualizacao = j.optInt("segundos_atualizacao", 10).coerceIn(5, 120),
        periodoDe = j.optJSONObject("periodo")?.texto("de_utc"),
        periodoAte = j.optJSONObject("periodo")?.texto("ate_utc"),
        periodoRotulo = j.optJSONObject("periodo")?.optString("rotulo", "Hoje") ?: "Hoje",
    )
}
