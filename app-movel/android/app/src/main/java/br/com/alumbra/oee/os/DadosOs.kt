package br.com.alumbra.oee.os

import org.json.JSONArray
import org.json.JSONObject
import java.time.OffsetDateTime

/** Dados da função Ordens de serviço, como a API do portal (/api/app/os/...) devolve. */

data class SsApp(
    val id: Int,
    val numero: String,
    val titulo: String,
    val descricao: String,
    val statusRotulo: String,
    val prioridade: String,
    val tipo: String,
    val maquina: String?,
    val maquinaParada: Boolean,
    val area: String?,
    val solicitante: String?,
    val abertaEm: String?,
    val os: String?,
    val motivoRejeicao: String?,
    /** Este mantenedor recusou a SS no app (não toca mais para ele; ainda pode assumir). */
    val recusada: Boolean = false,
)

data class ApontamentoOs(
    val id: Int,
    val osId: Int,
    val mantenedor: String?,
    val inicioUtc: String?,
    val inicio: String?,
    val fim: String?,
    val minutos: Int,
    val descricao: String?,
)

/** Item do checklist da preventiva (resposta: ok | nok | na | null = sem resposta). */
data class ItemChecklist(
    val id: Int,
    val descricao: String,
    val resposta: String?,
    val observacao: String?,
    val respondidoPor: String? = null,
    val respondidoEm: String? = null,
)

/** Mantenedor da OS (principal = responsável) ou que pode ser incluído nela. */
data class MantenedorOs(val id: Int, val nome: String, val principal: Boolean = false)

data class OsApp(
    val id: Int,
    val numero: String,
    val titulo: String,
    val status: String,
    val statusRotulo: String,
    val prioridade: String,
    val maquina: String?,
    val maquinaParada: Boolean,
    val area: String?,
    val centroCusto: String?,
    val setor: String?,
    val solicitante: String?,
    val responsavel: String?,
    // Só no detalhe:
    val descricao: String? = null,
    val solucao: String? = null,
    val tipo: String? = null,
    val abertura: String? = null,
    val concluidaEm: String? = null,
    val totalMinutos: Int = 0,
    val podeExecutar: Boolean = false,
    val podeAvaliar: Boolean = false,
    val acoes: List<Pair<String, String>> = emptyList(),
    val meuApontamento: ApontamentoOs? = null,
    val apontamentos: List<ApontamentoOs> = emptyList(),
    val checklist: List<ItemChecklist> = emptyList(),
    val respostasChecklist: List<Opcao> = emptyList(),
    val podeResponderChecklist: Boolean = false,
    val mantenedores: List<MantenedorOs> = emptyList(),
    val mantenedoresDisponiveis: List<MantenedorOs> = emptyList(),
)

data class ResumoOs(
    val servidorAgoraMs: Long?,
    val mantenedor: String?,
    val podeAprovar: Boolean,
    val podeExecutar: Boolean,
    val podeSolicitar: Boolean,
    val pendentes: List<SsApp>,
    val minhasAbertas: Int,
    val avaliacoes: Int,
    val rodando: ApontamentoOs?,
    val rodandoOs: OsApp?,
)

data class Opcao(val valor: String, val rotulo: String)

data class MaquinaForm(val id: Int, val codigo: String, val nome: String, val areas: List<Int>)

data class FormularioSs(
    val podeSolicitar: Boolean,
    val areas: List<Opcao>,
    val maquinas: List<MaquinaForm>,
    val tipos: List<Opcao>,
    val prioridades: List<Opcao>,
)

private fun JSONObject.texto(nome: String): String? = if (isNull(nome) || !has(nome)) null else optString(nome)

private inline fun <T> JSONArray?.lista(conv: (JSONObject) -> T): List<T> =
    if (this == null) emptyList() else List(length()) { conv(getJSONObject(it)) }

fun paraMs(iso: String?): Long? = iso?.let { runCatching { OffsetDateTime.parse(it).toInstant().toEpochMilli() }.getOrNull() }

fun ssDe(j: JSONObject) = SsApp(
    j.optInt("id"), j.optString("numero"), j.optString("titulo"), j.optString("descricao"), j.optString("status_rotulo"),
    j.optString("prioridade_rotulo"), j.optString("tipo"), j.texto("maquina"), j.optBoolean("maquina_parada"), j.texto("area"),
    j.texto("solicitante"), j.texto("aberta_em"), j.texto("os"), j.texto("motivo_rejeicao"), j.optBoolean("recusada"),
)

/** /os/alerta: só as SS que ainda devem tocar para o usuário. */
fun alertaDe(j: JSONObject) = j.optJSONArray("pendentes").lista {
    SsApp(it.optInt("id"), "", it.optString("titulo"), "", "", "", "", it.texto("maquina"), it.optBoolean("maquina_parada"),
        null, null, null, null, null)
}

fun apontamentoOsDe(j: JSONObject?) = j?.let {
    ApontamentoOs(
        it.optInt("id"), it.optInt("ordem_servico_id"), it.texto("mantenedor"), it.texto("inicio_utc"), it.texto("inicio"),
        it.texto("fim"), it.optInt("minutos"), it.texto("descricao"),
    )
}

fun osDe(j: JSONObject) = OsApp(
    id = j.optInt("id"), numero = j.optString("numero"), titulo = j.optString("titulo"), status = j.optString("status"),
    statusRotulo = j.optString("status_rotulo"), prioridade = j.optString("prioridade_rotulo"), maquina = j.texto("maquina"),
    maquinaParada = j.optBoolean("maquina_parada"), area = j.texto("area"), centroCusto = j.texto("centro_custo"),
    setor = j.texto("setor"), solicitante = j.texto("solicitante"), responsavel = j.texto("responsavel"),
    descricao = j.texto("descricao"), solucao = j.texto("solucao"), tipo = j.texto("tipo"), abertura = j.texto("abertura"),
    concluidaEm = j.texto("concluida_em"), totalMinutos = j.optInt("total_minutos"), podeExecutar = j.optBoolean("pode_executar"),
    podeAvaliar = j.optBoolean("pode_avaliar"),
    acoes = j.optJSONArray("acoes").lista { it.optString("acao") to it.optString("rotulo") },
    meuApontamento = apontamentoOsDe(j.optJSONObject("meu_apontamento")),
    apontamentos = j.optJSONArray("apontamentos").lista { apontamentoOsDe(it)!! },
    checklist = j.optJSONArray("checklist").lista {
        ItemChecklist(it.optInt("id"), it.optString("descricao"), it.texto("resposta"), it.texto("observacao"),
            it.texto("respondido_por"), it.texto("respondido_em"))
    },
    respostasChecklist = j.optJSONArray("respostas_checklist").lista { Opcao(it.optString("valor"), it.optString("rotulo")) },
    podeResponderChecklist = j.optBoolean("pode_responder_checklist"),
    mantenedores = j.optJSONArray("mantenedores").lista { MantenedorOs(it.optInt("id"), it.optString("nome"), it.optBoolean("principal")) },
    mantenedoresDisponiveis = j.optJSONArray("mantenedores_disponiveis").lista { MantenedorOs(it.optInt("id"), it.optString("nome")) },
)

fun resumoDe(j: JSONObject) = ResumoOs(
    servidorAgoraMs = paraMs(j.texto("servidor_agora")),
    mantenedor = j.optJSONObject("mantenedor")?.optString("nome"),
    podeAprovar = j.optBoolean("pode_aprovar"),
    podeExecutar = j.optBoolean("pode_executar"),
    podeSolicitar = j.optBoolean("pode_solicitar"),
    pendentes = j.optJSONArray("pendentes").lista(::ssDe),
    minhasAbertas = j.optInt("minhas_abertas"),
    avaliacoes = j.optInt("avaliacoes"),
    rodando = apontamentoOsDe(j.optJSONObject("rodando")),
    rodandoOs = j.optJSONObject("rodando")?.optJSONObject("os")?.let(::osDe),
)

fun listaSsDe(j: JSONObject, chave: String) = j.optJSONArray(chave).lista(::ssDe)

fun listaOsDe(j: JSONObject) = j.optJSONArray("ordens").lista(::osDe)

fun formularioDe(j: JSONObject) = FormularioSs(
    podeSolicitar = j.optBoolean("pode_solicitar"),
    areas = j.optJSONArray("areas").lista { Opcao(it.optInt("id").toString(), it.optString("nome")) },
    maquinas = j.optJSONArray("maquinas").lista { m ->
        val a = m.optJSONArray("areas")
        MaquinaForm(m.optInt("id"), m.optString("codigo"), m.optString("nome"), List(a?.length() ?: 0) { a!!.getInt(it) })
    },
    tipos = j.optJSONArray("tipos").lista { Opcao(it.optString("valor"), it.optString("rotulo")) },
    prioridades = j.optJSONArray("prioridades").lista { Opcao(it.optString("valor"), it.optString("rotulo")) },
)
