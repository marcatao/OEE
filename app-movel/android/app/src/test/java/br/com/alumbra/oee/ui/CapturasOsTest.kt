package br.com.alumbra.oee.ui

import androidx.compose.foundation.layout.Box
import app.cash.paparazzi.DeviceConfig
import app.cash.paparazzi.Paparazzi
import br.com.alumbra.oee.os.AlertaNovaSs
import br.com.alumbra.oee.os.ApontamentoOs
import br.com.alumbra.oee.os.CartaoOs
import br.com.alumbra.oee.os.CartaoSs
import br.com.alumbra.oee.os.ConteudoAvaliacao
import br.com.alumbra.oee.os.OsApp
import br.com.alumbra.oee.os.SsApp
import br.com.alumbra.oee.os.TelaApontamentoTravado
import androidx.compose.foundation.layout.padding
import androidx.compose.ui.unit.dp
import org.junit.Rule
import org.junit.Test

/** Capturas da função Ordens de serviço: ./gradlew recordPaparazziDebug */
class CapturasOsTest {

    @get:Rule
    val paparazzi = Paparazzi(deviceConfig = DeviceConfig.PIXEL_5, maxPercentDifference = 0.1)

    private val agora = 1_763_400_000_000L
    private val ss = SsApp(1, "000123", "Vazamento de óleo no cilindro", "Vazamento forte de óleo no cilindro de fechamento, máquina parou.",
        "Aguardando análise", "Alta", "Corretiva", "INJ-01 · Injetora 01", true, "Mecânica", "Ana Souza", "17/11/2026 14:05", null, null)
    private val os = OsApp(7, "000045", "Vazamento de óleo no cilindro", "em_execucao", "Em execução", "Alta", "INJ-01 · Injetora 01", true,
        "Mecânica", "1001 · INJECAO", "Injeção", "Ana Souza", "João Mecânico",
        descricao = "Vazamento forte de óleo no cilindro de fechamento.", solucao = "Troca da vedação do cilindro e reaperto das conexões.",
        abertura = "17/11/2026 14:05", concluidaEm = "17/11/2026 16:10", totalMinutos = 95,
        apontamentos = listOf(ApontamentoOs(1, 7, "João Mecânico", null, "17/11/2026 14:35", "17/11/2026 16:10", 95, "Troca da vedação do cilindro")))
    private val ap = ApontamentoOs(1, 7, "João Mecânico", java.time.Instant.ofEpochMilli(agora - (47 * 60_000 + 12_000)).toString(), "17/11/2026 14:35", null, 0, null)

    @Test
    fun alerta_e_listas() = paparazzi.snapshot {
        TemaOee(escuro = false) {
            Box {
                androidx.compose.foundation.layout.Column(
                    androidx.compose.ui.Modifier.padding(top = 330.dp, start = 16.dp, end = 16.dp),
                    verticalArrangement = androidx.compose.foundation.layout.Arrangement.spacedBy(10.dp),
                ) {
                    CartaoSs(ss, assumindo = false) {}
                    CartaoOs(os.copy(maquinaParada = false, prioridade = "Média")) {}
                }
                AlertaNovaSs(listOf(ss, ss.copy(id = 2, titulo = "Troca de lâmpada", maquinaParada = false, maquina = null)), null, {}, {}, {})
            }
        }
    }

    @Test
    fun apontamento_travado() = paparazzi.snapshot {
        TemaOee(escuro = false) {
            TelaApontamentoTravado(os, ap, 0, null, false, null, { _, _, _, _ -> }, {}, agoraFixo = agora)
        }
    }

    @Test
    fun avaliacao() = paparazzi.snapshot {
        TemaOee(escuro = false) {
            ConteudoAvaliacao(os.copy(status = "concluida"), null, {}) { _, _, _, _, _ -> }
        }
    }
}
