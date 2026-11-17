package br.com.alumbra.oee.maquinas

import br.com.alumbra.oee.api.ApiPortal
import org.json.JSONObject
import java.net.URLEncoder

/** Chamadas da função Máquinas à API do portal (precisam da rede da fábrica). */
class RepositorioMaquinas(private val token: () -> String?) {

    suspend fun lista(): List<MaquinaResumo> = maquinasDe(ApiPortal.get("maquinas", token()))

    suspend fun detalhe(id: Int): DetalheMaquina = detalheDe(ApiPortal.get("maquinas/$id", token()))

    suspend fun informarMotivo(apontamentoId: Int, legendaId: Int): DetalheMaquina =
        detalheDe(ApiPortal.post("apontamentos/$apontamentoId/motivo", token(), JSONObject().put("legenda_id", legendaId)))

    suspend fun trocarOp(maquinaId: Int, numero: String): DetalheMaquina =
        detalheDe(ApiPortal.post("maquinas/$maquinaId/op", token(), JSONObject().put("numero", numero)))

    suspend fun alterarCavidades(ferramentaId: Int, maquinaId: Int, cavidades: Int): DetalheMaquina =
        detalheDe(
            ApiPortal.post(
                "ferramentas/$ferramentaId/cavidades", token(),
                JSONObject().put("cavidades", cavidades).put("maquina_id", maquinaId),
            ),
        )

    suspend fun buscarOps(busca: String): List<OpResumo> =
        opsDe(ApiPortal.get("ops?busca=" + URLEncoder.encode(busca, "UTF-8"), token()))
}
