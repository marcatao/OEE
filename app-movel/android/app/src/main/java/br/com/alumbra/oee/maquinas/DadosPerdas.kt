package br.com.alumbra.oee.maquinas

import org.json.JSONObject

/** Apontamento de perdas da OP (GET/POST /api/app/ops/{id}/perdas, DELETE /api/app/perdas/{id}). */
data class PerdaApp(
    val id: Int,
    val dia: String,
    val quantidade: Double,
    val peso: Double?,
    val pesoUnitario: Double?,
    val texto: String,
    val maquina: String?,
    val observacao: String?,
    val origem: String,
    val criadoPor: String?,
    val criadoEm: String?,
    val excluida: Boolean,
    val excluidoPor: String?,
    val excluidoEm: String?,
)

data class PerdasOp(
    val opId: Int,
    val numero: String,
    val produto: String,
    val quantidadeOp: Double,
    /** PU = peso de 1 peça em kg (B1_PESO); null = sem PU (só a quantidade pode ser apontada). */
    val pesoUnitario: Double?,
    val totalQuantidade: Double,
    val totalPeso: Double,
    val apontamentos: Int,
    val perdas: List<PerdaApp>,
)

fun perdasOpDe(j: JSONObject): PerdasOp {
    val op = j.optJSONObject("op") ?: JSONObject()
    fun JSONObject.t(n: String) = if (!has(n) || isNull(n)) null else optString(n).takeIf { it.isNotBlank() }
    fun JSONObject.d(n: String) = if (!has(n) || isNull(n)) null else optDouble(n)
    val lista = j.optJSONArray("perdas")
    return PerdasOp(
        opId = op.optInt("id"),
        numero = op.optString("numero"),
        produto = op.optString("produto"),
        quantidadeOp = op.optDouble("quantidade", 0.0),
        pesoUnitario = j.d("peso_unitario")?.takeIf { it > 0 },
        totalQuantidade = j.optDouble("total_quantidade", 0.0),
        totalPeso = j.optDouble("total_peso", 0.0),
        apontamentos = j.optInt("apontamentos"),
        perdas = if (lista == null) emptyList() else List(lista.length()) { i ->
            val p = lista.getJSONObject(i)
            PerdaApp(
                p.optInt("id"), p.optString("dia"), p.optDouble("quantidade", 0.0), p.d("peso"), p.d("peso_unitario"),
                p.optString("texto"), p.t("maquina"), p.t("observacao"), p.optString("origem"), p.t("criado_por"),
                p.t("criado_em"), p.optBoolean("excluida"), p.t("excluido_por"), p.t("excluido_em"),
            )
        },
    )
}
