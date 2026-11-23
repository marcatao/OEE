package br.com.alumbra.oee.maquinas

import org.json.JSONArray
import org.json.JSONObject

/** "Detalhes da OP" (GET /api/app/ops/{id}/detalhes): as abas da OP no portal, só leitura. */

data class OpCompleta(
    val id: Int,
    val numero: String,
    val produto: String,
    val descricao: String?,
    val statusRotulo: String,
    val um: String?,
    val quantidade: Double,
    val produzido: Double,
    val produzidoProtheus: Double,
    val quantidadeReal: Double,
    val perda: Double,
    val emissao: String?,
    val previsaoInicio: String?,
    val previsaoFim: String?,
    val encerradaEm: String?,
    val roteiro: String?,
    val revisao: String?,
    val armazem: String?,
    val centroCusto: String?,
    val tipo: String?,
    val observacao: String?,
)

data class ComponenteCusto(val produto: String, val descricao: String?, val um: String?, val quantidade: Double, val valor: Double, val porPeca: Double?)

data class CustoOp(
    val protheus: Double?,
    val meta: Double?,
    val simulado: Double?,
    val divergencia: Double?,
    val baixas: Double,
    val notas: Double,
    val devolucoes: Double,
    val componentes: List<ComponenteCusto>,
)

data class MovimentoKardex(
    val data: String?,
    val tipo: String,
    val rotulo: String,
    val descricao: String,
    val produto: String,
    val produtoDescricao: String?,
    val documento: String,
    val entrada: Boolean,
    val quantidade: Double,
    val um: String?,
    val valor: Double,
    val produzidoAcumulado: Double,
    val custoPecaAcumulado: Double?,
)

data class EmpenhoApp(
    val componente: String,
    val descricao: String?,
    val um: String?,
    val trt: String?,
    val armazem: String?,
    val lote: String?,
    val data: String?,
    val empenhado: Double,
    val baixado: Double,
    val saldo: Double,
    val atendido: Double?,
)

data class ItemEstrutura(
    val trt: String?,
    val componente: String,
    val descricao: String?,
    val um: String?,
    val porPeca: Double,
    val perda: Double,
    val fixa: Boolean,
    val necessidade: Double,
    val empenhado: Double?,
    val intermediario: Boolean,
    val vigenciaInicio: String? = null,
    val vigenciaFim: String? = null,
)

data class OperacaoRoteiro(
    val operacao: String,
    val descricao: String,
    val maquina: String?,
    val maquinaNome: String?,
    val comEstaOp: Boolean,
    val recurso: String?,
    val ferramenta: String?,
    val ferramentaDescricao: String?,
    val cavidades: Int?,
    val cavidadesAtivas: Int?,
    val tipo: String,
    val setup: Double,
    val tempoPadrao: Double,
    val lotePadrao: Double,
    val maoDeObra: Double,
)

/** Peças contadas pelo IoT na OP (as mesmas da Produção por hora). */
data class ContagemIot(
    val total: Int,
    val hoje: Int,
    /** % da quantidade da OP (0 a 100+), null sem quantidade. */
    val percentual: Double?,
    val ultimaContagem: String?,
    val porMaquina: List<Pair<String, Int>>,
)

data class DetalhesOp(
    val op: OpCompleta,
    val custo: CustoOp,
    val kardexTotal: Int,
    val kardex: List<MovimentoKardex>,
    val empenhos: List<EmpenhoApp>,
    val estrutura: List<ItemEstrutura>,
    /** Vigência da estrutura inteira (ex.: "15/03/2026 até 31/12/2026", "sem limite"); null sem estrutura. */
    val estruturaVigencia: String? = null,
    val roteiro: String?,
    val operacoes: List<OperacaoRoteiro>,
    val contagemIot: ContagemIot? = null,
)

private fun JSONObject.t(nome: String): String? = if (!has(nome) || isNull(nome)) null else optString(nome).takeIf { it.isNotBlank() }
private fun JSONObject.d(nome: String): Double? = if (!has(nome) || isNull(nome)) null else optDouble(nome)
private fun JSONObject.i(nome: String): Int? = if (!has(nome) || isNull(nome)) null else optInt(nome)
private inline fun <T> JSONArray?.cada(conv: (JSONObject) -> T): List<T> =
    if (this == null) emptyList() else List(length()) { conv(getJSONObject(it)) }

fun detalhesOpDe(j: JSONObject): DetalhesOp {
    val o = j.getJSONObject("op")
    val c = j.optJSONObject("custo") ?: JSONObject()
    val k = j.optJSONObject("kardex") ?: JSONObject()
    val r = j.optJSONObject("roteiro") ?: JSONObject()
    return DetalhesOp(
        op = OpCompleta(
            o.optInt("id"), o.optString("numero"), o.optString("produto"), o.t("descricao"), o.optString("status_rotulo"), o.t("um"),
            o.optDouble("quantidade", 0.0), o.optDouble("produzido", 0.0), o.optDouble("produzido_protheus", 0.0),
            o.optDouble("quantidade_real", 0.0), o.optDouble("perda", 0.0), o.t("emissao"), o.t("previsao_inicio"), o.t("previsao_fim"),
            o.t("encerrada_em"), o.t("roteiro"), o.t("revisao"), o.t("armazem"), o.t("centro_custo"), o.t("tipo"), o.t("observacao"),
        ),
        custo = CustoOp(
            c.d("protheus"), c.d("meta"), c.d("simulado"), c.d("divergencia"), c.optDouble("baixas", 0.0), c.optDouble("notas", 0.0),
            c.optDouble("devolucoes", 0.0),
            c.optJSONArray("componentes").cada {
                ComponenteCusto(it.optString("produto"), it.t("descricao"), it.t("um"), it.optDouble("quantidade", 0.0),
                    it.optDouble("valor", 0.0), it.d("por_peca"))
            },
        ),
        kardexTotal = k.optInt("total"),
        kardex = k.optJSONArray("movimentos").cada {
            MovimentoKardex(it.t("data"), it.optString("tipo"), it.optString("rotulo"), it.optString("descricao"), it.optString("produto"),
                it.t("produto_descricao"), it.optString("documento"), it.optBoolean("entrada"), it.optDouble("quantidade", 0.0), it.t("um"),
                it.optDouble("valor", 0.0), it.optDouble("produzido_acumulado", 0.0), it.d("custo_peca_acumulado"))
        },
        empenhos = j.optJSONArray("empenhos").cada {
            EmpenhoApp(it.optString("componente"), it.t("descricao"), it.t("um"), it.t("trt"), it.t("armazem"), it.t("lote"), it.t("data"),
                it.optDouble("empenhado", 0.0), it.optDouble("baixado", 0.0), it.optDouble("saldo", 0.0), it.d("atendido"))
        },
        estrutura = j.optJSONArray("estrutura").cada {
            ItemEstrutura(it.t("trt"), it.optString("componente"), it.t("descricao"), it.t("um"), it.optDouble("por_peca", 0.0),
                it.optDouble("perda", 0.0), it.optBoolean("fixa"), it.optDouble("necessidade", 0.0), it.d("empenhado"), it.optBoolean("intermediario"),
                it.t("vigencia_inicio"), it.t("vigencia_fim"))
        },
        estruturaVigencia = j.optJSONObject("estrutura_vigencia")?.t("texto"),
        contagemIot = j.optJSONObject("contagem_iot")?.let { c ->
            ContagemIot(
                c.optInt("total"), c.optInt("hoje"), c.d("percentual"), c.t("ultima_contagem"),
                c.optJSONArray("por_maquina").cada { it.optString("maquina") to it.optInt("pecas") },
            )
        },
        roteiro = r.t("codigo"),
        operacoes = r.optJSONArray("operacoes").cada {
            OperacaoRoteiro(it.optString("operacao"), it.optString("descricao"), it.t("maquina"), it.t("maquina_nome"), it.optBoolean("com_esta_op"),
                it.t("recurso"), it.t("ferramenta"), it.t("ferramenta_descricao"), it.i("cavidades"), it.i("cavidades_ativas"),
                it.optString("tipo"), it.optDouble("setup", 0.0), it.optDouble("tempo_padrao", 0.0), it.optDouble("lote_padrao", 0.0),
                it.optDouble("mao_de_obra", 0.0))
        },
    )
}
