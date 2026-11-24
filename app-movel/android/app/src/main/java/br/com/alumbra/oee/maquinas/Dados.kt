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
    /** Peças contadas pelo IoT na OP (ciclos × cavidades). */
    val quantidadeReal: Double? = null,
)

/** SS/OS aberta pela legenda da parada atual (o portal só manda com a máquina parada). */
data class ChamadoApp(val tipo: String, val numero: String, val status: String, val rotulo: String) {
    /** Cor do selo: aguardando análise = alerta; aberta/em execução = neutra; concluída/encerrada = ok. */
    val situacao: br.com.alumbra.oee.ui.Situacao
        get() = when (status) {
            "pendente" -> br.com.alumbra.oee.ui.Situacao.ALERTA
            "concluida", "encerrada" -> br.com.alumbra.oee.ui.Situacao.OK
            "rejeitada", "cancelada" -> br.com.alumbra.oee.ui.Situacao.ERRO
            else -> br.com.alumbra.oee.ui.Situacao.NEUTRA
        }
}

data class MaquinaResumo(
    val id: Int,
    val codigo: String,
    val nome: String,
    val local: String,
    val situacao: String?,
    val legenda: LegendaApp?,
    val op: OpResumo?,
    val semMotivo: Int,
    val chamado: ChamadoApp? = null,
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
    /** Peças do apontamento (ciclos do IoT × cavidades); null sem contagem. */
    val pecas: Int? = null,
)

/** Uma hora do turno no gráfico de produção. */
data class HoraProducao(
    val hora: String,
    val inicioUtc: String?,
    val pecas: Int,
    /** Meta da hora em peças (pela OP que a máquina tinha em cada momento da hora); null = sem meta. */
    val meta: Int? = null,
    /** Na hora atual, a meta só até agora; nas passadas, a meta inteira; futura = null. */
    val metaAteAgora: Int? = null,
    /** "boa" (≥ 90%), "media" (≥ 50%), "ruim" ou null (sem meta ou hora futura) — regra do portal. */
    val nivel: String? = null,
)

/** OEE (IoT) do turno de hoje da máquina: D × P × Q (0 a 1; performance pode passar de 1); null = sem meta/dados. */
data class OeeApp(
    val oee: Double?,
    val disponibilidade: Double?,
    val performance: Double?,
    val qualidade: Double?,
    val texto: String,
    /** "bom" (≥ 85%), "medio" (≥ 60%), "ruim" ou "sem". */
    val nivel: String,
)

fun oeeDe(j: JSONObject?): OeeApp? = j?.let {
    fun d(n: String) = if (!it.has(n) || it.isNull(n)) null else it.optDouble(n)
    OeeApp(d("oee"), d("disponibilidade"), d("performance"), d("qualidade"), it.optString("texto", "—"), it.optString("nivel", "sem"))
}

/** Molde com cavidades desativadas e produção abaixo da meta: indicar para conserto. */
data class MoldeConserto(val codigo: String, val descricao: String?, val ativas: Int, val total: Int)

/** Produção do turno de hoje (peças por hora, total, peças por minuto agora e a meta, quando houver). */
data class ProducaoApp(
    val porHora: List<HoraProducao>,
    val pecasTurno: Int,
    val cicloMedio: Int?,
    /** Ciclo médio pronto para exibir, com milissegundos ("0,040 s", "32,0 s"); null em portal antigo. */
    val cicloMedioTexto: String? = null,
    /** Ciclo médio em segundos com milissegundos (0.04 = 40 ms). */
    val cicloMedioS: Double? = null,
    val cavidades: Int,
    val pecasPorMinuto: Double,
    val metaPorMinuto: Double?,
    val moldeConserto: MoldeConserto? = null,
    /** Soma das metas das horas do turno (portal que manda a meta de cada hora). */
    val metaTurno: Int? = null,
    /** O portal mandou a meta de CADA hora (por_hora[].meta): o app não calcula mais meta × 60. */
    val metaPorHora: Boolean = false,
)

/** Detalhe de UM apontamento real (toque na lista da tela da máquina; GET /api/app/apontamentos/{id}). */
data class ApontamentoDetalhe(
    val base: ApontamentoApp,
    val maquinaId: Int,
    val maquinaNome: String,
    val emAberto: Boolean,
    val op: OpResumo?,
    val ciclos: Int?,
    val cavidades: Int?,
    val cicloMedioTexto: String?,
    val observacao: String?,
    val referencia: String?,
    val origem: String,
    val criadoPor: String?,
    val criadoEm: String?,
    val atualizadoPor: String?,
    val atualizadoEm: String?,
)

fun apontamentoDetalheDe(j: JSONObject): ApontamentoDetalhe {
    val m = j.optJSONObject("maquina") ?: JSONObject()
    fun t(n: String) = if (!j.has(n) || j.isNull(n)) null else j.optString(n).takeIf { it.isNotBlank() }
    return ApontamentoDetalhe(
        base = apontamentoDe(j),
        maquinaId = m.optInt("id"),
        maquinaNome = m.optString("nome"),
        emAberto = j.optBoolean("em_aberto"),
        op = opResumoDe(j.optJSONObject("op_detalhe")),
        ciclos = if (!j.has("ciclos") || j.isNull("ciclos")) null else j.optInt("ciclos"),
        cavidades = if (!j.has("cavidades") || j.isNull("cavidades")) null else j.optInt("cavidades"),
        cicloMedioTexto = t("ciclo_medio_texto")?.takeIf { it != "—" },
        observacao = t("observacao"),
        referencia = t("referencia"),
        origem = t("origem") ?: "—",
        criadoPor = t("criado_por"),
        criadoEm = t("criado_em"),
        atualizadoPor = t("atualizado_por"),
        atualizadoEm = t("atualizado_em"),
    )
}

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
    val chamado: ChamadoApp? = null,
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
    val producao: ProducaoApp? = null,
    val oee: OeeApp? = null,
)

private fun JSONObject.texto(nome: String): String? = if (isNull(nome)) null else optString(nome)

private inline fun <T> JSONArray?.lista(conv: (JSONObject) -> T): List<T> =
    if (this == null) emptyList() else List(length()) { conv(getJSONObject(it)) }

fun legendaDe(j: JSONObject?): LegendaApp? = j?.let {
    LegendaApp(it.optInt("id"), it.optString("codigo"), it.optString("descricao"), it.optString("tipo"), it.texto("cor"))
}

fun chamadoDe(j: JSONObject?): ChamadoApp? = j?.let {
    ChamadoApp(it.optString("tipo"), it.optString("numero"), it.optString("status"), it.optString("rotulo"))
}

fun opResumoDe(j: JSONObject?): OpResumo? = j?.let {
    OpResumo(
        it.optInt("id"), it.optString("numero"), it.optString("produto"), it.texto("descricao"),
        it.optString("status_rotulo"), it.optDouble("quantidade", 0.0), it.optDouble("produzido", 0.0),
        if (it.has("quantidade_real") && !it.isNull("quantidade_real")) it.optDouble("quantidade_real") else null,
    )
}

fun maquinasDe(j: JSONObject): List<MaquinaResumo> = j.optJSONArray("maquinas").lista {
    MaquinaResumo(
        it.optInt("id"), it.optString("codigo"), it.optString("nome"), it.optString("local"), it.texto("situacao"),
        legendaDe(it.optJSONObject("legenda")), opResumoDe(it.optJSONObject("op")), it.optInt("sem_motivo"),
        chamadoDe(it.optJSONObject("chamado")),
    )
}

fun opsDe(j: JSONObject): List<OpResumo> = j.optJSONArray("ops").lista { opResumoDe(it)!! }

internal fun apontamentoDe(j: JSONObject) = ApontamentoApp(
    j.optInt("id"), legendaDe(j.optJSONObject("legenda")), j.optBoolean("sem_motivo"), j.texto("dia"), j.texto("inicio"),
    j.texto("fim"), j.texto("inicio_utc"), j.texto("fim_utc"), j.optDouble("minutos", 0.0), j.texto("op"),
    if (j.has("pecas") && !j.isNull("pecas")) j.optInt("pecas") else null,
)

fun producaoDe(j: JSONObject?): ProducaoApp? = j?.let {
    ProducaoApp(
        porHora = it.optJSONArray("por_hora").lista { h ->
            HoraProducao(
                h.optString("hora"), h.texto("inicio"), h.optInt("pecas"),
                meta = if (!h.has("meta") || h.isNull("meta")) null else h.optInt("meta"),
                metaAteAgora = if (!h.has("meta_ate_agora") || h.isNull("meta_ate_agora")) null else h.optInt("meta_ate_agora"),
                nivel = h.texto("nivel"),
            )
        },
        metaTurno = if (!it.has("meta_turno") || it.isNull("meta_turno")) null else it.optInt("meta_turno"),
        metaPorHora = it.optJSONArray("por_hora")?.optJSONObject(0)?.has("meta") == true,
        pecasTurno = it.optInt("pecas_turno"),
        cicloMedio = if (it.isNull("ciclo_medio")) null else it.optInt("ciclo_medio"),
        cicloMedioTexto = if (!it.has("ciclo_medio_texto") || it.isNull("ciclo_medio_texto")) null else it.optString("ciclo_medio_texto"),
        cicloMedioS = if (!it.has("ciclo_medio_s") || it.isNull("ciclo_medio_s")) null else it.optDouble("ciclo_medio_s"),
        cavidades = it.optInt("cavidades", 1),
        pecasPorMinuto = it.optDouble("pecas_por_minuto", 0.0),
        metaPorMinuto = if (!it.has("meta_por_minuto") || it.isNull("meta_por_minuto")) null else it.optDouble("meta_por_minuto"),
        moldeConserto = it.optJSONObject("molde_conserto")?.let { m ->
            MoldeConserto(m.optString("codigo"), if (m.isNull("descricao")) null else m.optString("descricao"), m.optInt("ativas"), m.optInt("total"))
        },
    )
}

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
        chamado = chamadoDe(m.optJSONObject("chamado")),
        apontamentos = j.optJSONArray("apontamentos").lista(::apontamentoDe),
        semMotivo = j.optJSONArray("sem_motivo").lista(::apontamentoDe),
        op = op,
        legendasMotivo = j.optJSONArray("legendas_motivo").lista { legendaDe(it)!! },
        servidorAgora = j.optString("servidor_agora"),
        segundosAtualizacao = j.optInt("segundos_atualizacao", 10).coerceIn(5, 120),
        periodoDe = j.optJSONObject("periodo")?.texto("de_utc"),
        periodoAte = j.optJSONObject("periodo")?.texto("ate_utc"),
        periodoRotulo = j.optJSONObject("periodo")?.optString("rotulo", "Hoje") ?: "Hoje",
        producao = producaoDe(j.optJSONObject("producao")),
        oee = oeeDe(j.optJSONObject("oee")),
    )
}
